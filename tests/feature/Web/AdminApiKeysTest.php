<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin CRUD over `users_api_access_keys` (`Admin\ApiAccessKeys`), exercised
 * through real routing + filters (`authFilter`/`adminFilter`) + CSRF. Model
 * -level coverage (token shape, key-cap, keyword search) lives in
 * ApiAccessKeyModelTest — this suite is transport + admin-gate + the
 * "no user_id reassignment on update" contract.
 *
 * @internal
 */
final class AdminApiKeysTest extends CIUnitTestCase
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

    public function testAdminCanCreateUpdateToggleAndDeleteAKeyForAnyUser(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'target@example.com', 'zone' => 'client', 'env' => 'LIVE']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/api-access-keys/create', $this->withCsrf([
            'user_id'    => $target->id,
            'service_id' => 800,
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertNotEmpty($json['access_key']);

        $keyModel = model(ApiAccessKeyModel::class);
        $row      = $keyModel->findByAccessKey($json['access_key']);
        $this->assertNotNull($row);
        $this->assertSame((int) $target->id, (int) $row->user_id);
        $this->assertSame(1, (int) $row->user_status); // LIVE -> 1
        $this->assertSame(1, (int) $row->is_enable);

        $update = $this->withSession()->post('/admin/api-access-keys/update', $this->withCsrf([
            'id'          => $row->id,
            'service_id'  => 801, // sent but must be IGNORED (immutable — see below)
            'description' => 'updated desc',
            'enable_log'  => 0,
            // is_enable intentionally omitted -> update() leaves it untouched (still 1)
        ]));
        $update->assertStatus(200);

        $updated = $keyModel->find($row->id);
        $this->assertSame('updated desc', $updated->description);
        $this->assertSame(0, (int) $updated->enable_log);
        $this->assertSame(1, (int) $updated->is_enable);
        // Critical: user_id is never accepted/changed by update().
        $this->assertSame((int) $target->id, (int) $updated->user_id);
        // Critical: service_id is IMMUTABLE — it's encoded into the access
        // token, so changing only the row would desync it and 401 the key.
        // The posted 801 must be ignored; the row stays 800.
        $this->assertSame(800, (int) $updated->service_id);

        $toggle = $this->withSession()->post('/admin/api-access-keys/toggle', $this->withCsrf([
            'id'        => $row->id,
            'is_enable' => 1,
        ]));
        $toggle->assertStatus(200);
        $this->assertSame(1, (int) $keyModel->find($row->id)->is_enable);

        $toggleLog = $this->withSession()->post('/admin/api-access-keys/toggle-log', $this->withCsrf([
            'id'         => $row->id,
            'enable_log' => 1,
        ]));
        $toggleLog->assertStatus(200);
        $this->assertSame(1, (int) $keyModel->find($row->id)->enable_log);

        $delete = $this->withSession()->post('/admin/api-access-keys/delete', $this->withCsrf(['id' => $row->id]));
        $delete->assertStatus(200);
        $this->assertNull($keyModel->find($row->id));
    }

    public function testCreateRejectsMissingUserIdOrServiceId(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/api-access-keys/create', $this->withCsrf([
            'service_id' => 800,
        ]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testCreateRejectsUnknownServiceId(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin3@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'target3@example.com', 'zone' => 'client']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/api-access-keys/create', $this->withCsrf([
            'user_id'    => $target->id,
            'service_id' => 999999,
        ]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testToggleOnUnknownIdReturnsNotFound(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin4@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/api-access-keys/toggle', $this->withCsrf([
            'id'        => 999999,
            'is_enable' => 1,
        ]));

        $result->assertStatus(404);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->post('/admin/api-access-keys/create', $this->withCsrf([
            'user_id'    => $client->id,
            'service_id' => 800,
        ]));

        // adminFilter redirects non-admin sessions away (see AdminFilter::before()).
        $result->assertRedirectTo('/dashboard');
        $this->assertSame(0, model(ApiAccessKeyModel::class)->countForUser((int) $client->id));
    }

    public function testGuestIsRedirectedToLoginFromAdminMutation(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->post('/admin/api-access-keys/create', $this->withCsrf([
            'user_id'    => 1,
            'service_id' => 800,
        ]));

        $result->assertRedirectTo('/login');
    }
}
