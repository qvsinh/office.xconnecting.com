<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Self-service API history viewer (`Client\ApiHistory`), scoped strictly to
 * `session('userWorking')`. Exercised through real routing + filters
 * (`authFilter`) + CSRF. Model-level filter/sort/soft-delete coverage lives
 * in ApiHistoryModelTest — this suite is transport, auth-gating, and the
 * IDOR/ownership contract on preview/delete/delete-all/delete-by-age.
 *
 * @internal
 */
final class ClientApiHistoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testGuestIsRedirectedToLoginFromApiHistoryList(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/api-history');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromApiHistoryMutation(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->post('/api-history/delete', $this->withCsrf(['id' => 1]));

        $result->assertRedirectTo('/login');
    }

    public function testMutationWithoutCsrfTokenIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'nocsrf@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/api-history/delete', ['id' => 1]);

        // security.redirect = true (.env) -> CSRF::before() redirects on failure.
        $result->assertRedirect();
    }

    public function testPreviewReturnsOwnRowButNotAForeignRow(): void
    {
        [$userA, $userB, $rowA] = $this->seedTwoUsersAndARowForA();

        $this->loginAs($userA);
        $ownPreview = $this->withSession()->get('/api-history/preview?id=' . $rowA);
        $ownPreview->assertStatus(200);
        $ownJson = json_decode($ownPreview->getJSON(), true);
        $this->assertSame('success', $ownJson['status']);
        $this->assertSame($rowA, (int) $ownJson['row']['id']);

        session()->destroy();
        $this->loginAs($userB);
        $foreignPreview = $this->withSession()->get('/api-history/preview?id=' . $rowA);
        $foreignPreview->assertStatus(404);
        $this->assertSame('failed', json_decode($foreignPreview->getJSON(), true)['status']);
    }

    public function testDeleteRejectsAForeignRowAndLeavesItIntact(): void
    {
        [$userA, $userB, $rowA] = $this->seedTwoUsersAndARowForA();

        $this->loginAs($userB);
        $result = $this->withSession()->post('/api-history/delete', $this->withCsrf(['id' => $rowA]));

        $result->assertStatus(404);
        $this->assertNotNull(model(ApiHistoryModel::class)->find($rowA));
    }

    public function testDeleteSoftDeletesTheCallersOwnRow(): void
    {
        [$userA, , $rowA] = $this->seedTwoUsersAndARowForA();

        $this->loginAs($userA);
        $result = $this->withSession()->post('/api-history/delete', $this->withCsrf(['id' => $rowA]));

        $result->assertStatus(200);
        $model = model(ApiHistoryModel::class);
        $this->assertNull($model->find($rowA));
        $this->assertNotNull($model->withDeleted()->find($rowA));
    }

    public function testDeleteAllOnlyDeletesTheCallersRowsMatchingTheCurrentFilters(): void
    {
        $userA = $this->seedWebUser(['email' => 'delall-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'delall-b@example.com', 'zone' => 'client']);

        $model      = model(ApiHistoryModel::class);
        $cnrForA    = $this->seedRow(['user_id' => $userA->id, 'service_name' => 'cnr']);
        $jprsForA   = $this->seedRow(['user_id' => $userA->id, 'service_name' => 'jprs']);
        $cnrForB    = $this->seedRow(['user_id' => $userB->id, 'service_name' => 'cnr']);

        $this->loginAs($userA);
        $result = $this->withSession()->post('/api-history/delete-all', $this->withCsrf([
            'service_name' => 'cnr',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame(1, $json['deleted']);

        $this->assertNull($model->find($cnrForA));
        $this->assertNotNull($model->find($jprsForA)); // different filter value, untouched
        $this->assertNotNull($model->find($cnrForB)); // different user, untouched
    }

    public function testDeleteByAgeValidatesDaysIsARequiredPositiveInteger(): void
    {
        $user = $this->seedWebUser(['email' => 'age-validate@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/api-history/delete-by-age', $this->withCsrf(['days' => 0]));

        $result->assertStatus(400);
        $this->assertSame('failed', json_decode($result->getJSON(), true)['status']);
    }

    public function testDeleteByAgeOnlyDeletesTheCallersOldRows(): void
    {
        $userA = $this->seedWebUser(['email' => 'age-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'age-b@example.com', 'zone' => 'client']);

        $model       = model(ApiHistoryModel::class);
        $oldForA     = $this->seedRow(['user_id' => $userA->id]);
        $recentForA  = $this->seedRow(['user_id' => $userA->id]);
        $oldForB     = $this->seedRow(['user_id' => $userB->id]);

        $model->db->table('api_history')->whereIn('id', [$oldForA, $oldForB])
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-30 days'))]);

        $this->loginAs($userA);
        $result = $this->withSession()->post('/api-history/delete-by-age', $this->withCsrf(['days' => 7]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(1, $json['deleted']);

        $this->assertNull($model->find($oldForA));
        $this->assertNotNull($model->find($recentForA));
        $this->assertNotNull($model->find($oldForB)); // different user, untouched
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRow(array $overrides = []): int
    {
        return model(ApiHistoryModel::class)->insert(array_merge([
            'env'          => 'TESTING',
            'service_name' => 'cnr',
            'service_id'   => 800,
            'command'      => 'StatusAccount',
            'domain'       => 'example.com',
            'params'       => '{"command":"StatusAccount"}',
            'response'     => '{"code":200}',
            'status'       => 1,
        ], $overrides), true);
    }

    /**
     * @return array{0: object, 1: object, 2: int} [userA, userB, rowA id]
     */
    private function seedTwoUsersAndARowForA(): array
    {
        $userA = $this->seedWebUser(['email' => 'hist-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'hist-b@example.com', 'zone' => 'client']);
        $rowA  = $this->seedRow(['user_id' => $userA->id]);

        return [$userA, $userB, $rowA];
    }
}
