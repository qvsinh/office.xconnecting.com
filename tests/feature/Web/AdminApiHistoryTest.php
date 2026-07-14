<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin API history viewer over every user's `api_history` rows
 * (`Admin\ApiHistory`), gated by `authFilter` + `adminFilter`. Exercised
 * through real routing + filters + CSRF. Model-level coverage lives in
 * ApiHistoryModelTest — this suite is transport, the admin gate, cross-user
 * read/delete, and the `user` (email/id) filter.
 *
 * @internal
 */
final class AdminApiHistoryTest extends CIUnitTestCase
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

    public function testGuestIsRedirectedToLoginFromAdminApiHistoryMutation(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->post('/admin/api-history/delete', $this->withCsrf(['id' => 1]));

        $result->assertRedirectTo('/login');
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'gate-client@example.com', 'zone' => 'client']);
        $rowId  = $this->seedRow(['user_id' => $client->id]);
        $this->loginAs($client);

        $listResult = $this->withSession()->get('/admin/api-history');
        $listResult->assertRedirectTo('/dashboard');

        $deleteResult = $this->withSession()->post('/admin/api-history/delete', $this->withCsrf(['id' => $rowId]));
        $deleteResult->assertRedirectTo('/dashboard');
        $this->assertNotNull(model(ApiHistoryModel::class)->find($rowId));
    }

    public function testAdminCanPreviewAndDeleteAnyUsersRow(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-hist@example.com', 'zone' => 'global']);
        $owner = $this->seedWebUser(['email' => 'owner-hist@example.com', 'zone' => 'client']);
        $rowId = $this->seedRow(['user_id' => $owner->id]);

        $this->loginAs($admin);

        $preview = $this->withSession()->get('/admin/api-history/preview?id=' . $rowId);
        $preview->assertStatus(200);
        $previewJson = json_decode($preview->getJSON(), true);
        $this->assertSame($rowId, (int) $previewJson['row']['id']);

        $delete = $this->withSession()->post('/admin/api-history/delete', $this->withCsrf(['id' => $rowId]));
        $delete->assertStatus(200);

        $model = model(ApiHistoryModel::class);
        $this->assertNull($model->find($rowId));
        $this->assertNotNull($model->withDeleted()->find($rowId)); // soft-delete, not purged
    }

    public function testAdminPreviewOnUnknownIdReturnsNotFound(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-hist2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $preview = $this->withSession()->get('/admin/api-history/preview?id=999999');

        $preview->assertStatus(404);
    }

    public function testAdminDeleteAllWithoutUserFilterMatchesEveryUser(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-hist3@example.com', 'zone' => 'global']);
        $userA = $this->seedWebUser(['email' => 'all-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'all-b@example.com', 'zone' => 'client']);
        $rowA  = $this->seedRow(['user_id' => $userA->id, 'service_name' => 'cnr']);
        $rowB  = $this->seedRow(['user_id' => $userB->id, 'service_name' => 'cnr']);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-history/delete-all', $this->withCsrf([
            'service_name' => 'cnr',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(2, $json['deleted']);

        $model = model(ApiHistoryModel::class);
        $this->assertNull($model->find($rowA));
        $this->assertNull($model->find($rowB));
    }

    public function testAdminUserFilterByEmailNarrowsDeleteAllToThatUserOnly(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin-hist4@example.com', 'zone' => 'global']);
        $userA  = $this->seedWebUser(['email' => 'narrow-a@example.com', 'zone' => 'client']);
        $userB  = $this->seedWebUser(['email' => 'narrow-b@example.com', 'zone' => 'client']);
        $rowA   = $this->seedRow(['user_id' => $userA->id]);
        $rowB   = $this->seedRow(['user_id' => $userB->id]);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-history/delete-all', $this->withCsrf([
            'user' => $userA->email,
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(1, $json['deleted']);

        $model = model(ApiHistoryModel::class);
        $this->assertNull($model->find($rowA));
        $this->assertNotNull($model->find($rowB)); // different user, untouched
    }

    public function testAdminUserFilterByIdNarrowsDeleteAllToThatUserOnly(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-hist5@example.com', 'zone' => 'global']);
        $userA = $this->seedWebUser(['email' => 'narrow-id-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'narrow-id-b@example.com', 'zone' => 'client']);
        $rowA  = $this->seedRow(['user_id' => $userA->id]);
        $rowB  = $this->seedRow(['user_id' => $userB->id]);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-history/delete-all', $this->withCsrf([
            'user' => (string) $userA->id,
        ]));

        $result->assertStatus(200);
        $this->assertSame(1, json_decode($result->getJSON(), true)['deleted']);

        $model = model(ApiHistoryModel::class);
        $this->assertNull($model->find($rowA));
        $this->assertNotNull($model->find($rowB));
    }

    public function testAdminUserFilterWithUnmatchedEmailMatchesNoRows(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-hist6@example.com', 'zone' => 'global']);
        $userA = $this->seedWebUser(['email' => 'unmatched-a@example.com', 'zone' => 'client']);
        $rowA  = $this->seedRow(['user_id' => $userA->id]);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-history/delete-all', $this->withCsrf([
            'user' => 'nobody@example.com',
        ]));

        $result->assertStatus(200);
        $this->assertSame(0, json_decode($result->getJSON(), true)['deleted']);
        $this->assertNotNull(model(ApiHistoryModel::class)->find($rowA));
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
}
