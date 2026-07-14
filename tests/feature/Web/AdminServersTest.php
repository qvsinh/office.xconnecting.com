<?php

use App\Models\ServerModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin server management (`Admin\Servers`), exercised through real routing +
 * filters (`authFilter`/`adminFilter`) + CSRF: CRUD, validation, soft-delete,
 * search/paginate, admin gating. `index()` renders a view that doesn't exist
 * yet (`frontend-coder` builds it next) — this suite only exercises routes
 * that return JSON, plus gating on `index()` (filters run before the
 * controller/view, so the view's absence doesn't matter there).
 *
 * @internal
 */
final class AdminServersTest extends CIUnitTestCase
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

    public function testCreateStoresServerAndReturnsSuccessEnvelope(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/servers/create', $this->withCsrf([
            'name'   => 'ns1',
            'ip'     => '203.0.113.10',
            'status' => 1,
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $row = model(ServerModel::class)->where('name', 'ns1')->first();
        $this->assertNotNull($row);
        $this->assertSame('203.0.113.10', $row->ip);
        $this->assertSame(1, (int) $row->status);
    }

    public function testCreateRejectsMissingNameOrIp(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $missingName = $this->withSession()->post('/admin/servers/create', $this->withCsrf([
            'name'   => '',
            'ip'     => '203.0.113.11',
            'status' => 1,
        ]));
        $missingName->assertStatus(400);

        $missingIp = $this->withSession()->post('/admin/servers/create', $this->withCsrf([
            'name'   => 'ns2',
            'ip'     => '',
            'status' => 1,
        ]));
        $missingIp->assertStatus(400);

        $this->assertNull(model(ServerModel::class)->where('name', 'ns2')->first());
    }

    public function testCreateRejectsInvalidStatus(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/servers/create', $this->withCsrf([
            'name'   => 'ns3',
            'ip'     => '203.0.113.12',
            'status' => 7,
        ]));

        $result->assertStatus(400);
    }

    public function testEditReturnsServerFields(): void
    {
        $admin  = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'global']);
        $server = model(ServerModel::class);
        $id     = $server->insert(['name' => 'edit-target', 'ip' => '198.51.100.1', 'status' => 1]);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/servers/edit/' . $id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('edit-target', $json['server']['name']);
        $this->assertSame('198.51.100.1', $json['server']['ip']);
        $this->assertSame(1, $json['server']['status']);
    }

    public function testEditRejectsUnknownServerId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/servers/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdatePersistsEdits(): void
    {
        $admin      = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'global']);
        $serverModel = model(ServerModel::class);
        $id         = $serverModel->insert(['name' => 'Original Name', 'ip' => '10.0.0.1', 'status' => 1]);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/servers/update', $this->withCsrf([
            'id'     => $id,
            'name'   => 'Updated Name',
            'ip'     => '10.0.0.2',
            'status' => 0,
        ]));

        $update->assertStatus(200);

        $reloaded = $serverModel->find($id);
        $this->assertSame('Updated Name', $reloaded->name);
        $this->assertSame('10.0.0.2', $reloaded->ip);
        $this->assertSame(0, (int) $reloaded->status);
    }

    public function testUpdateRejectsUnknownServerId(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/servers/update', $this->withCsrf([
            'id'     => 999999,
            'name'   => 'Nope',
            'ip'     => '10.0.0.1',
            'status' => 1,
        ]));

        $update->assertStatus(404);
    }

    public function testDeleteSoftDeletesServer(): void
    {
        $admin       = $this->seedWebUser(['email' => 'deleter@example.com', 'zone' => 'global']);
        $serverModel = model(ServerModel::class);
        $id          = $serverModel->insert(['name' => 'delete-me', 'ip' => '10.0.0.5', 'status' => 1]);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/servers/delete', $this->withCsrf(['id' => $id]));

        $delete->assertStatus(200);
        $this->assertNull($serverModel->find($id));
        $this->assertSame([], $serverModel->searchAdmin(['keyword' => 'delete-me']));

        // Soft-deleted, not hard-deleted: still present with deleted_at set.
        $this->assertNotNull($serverModel->withDeleted()->find($id)->deleted_at);
    }

    public function testDeleteRejectsUnknownServerId(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/servers/delete', $this->withCsrf(['id' => 999999]));

        $delete->assertStatus(404);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/servers');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/servers/create', $this->withCsrf([
            'name'   => 'Should Not Be Created',
            'ip'     => '10.0.0.9',
            'status' => 1,
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(ServerModel::class)->where('name', 'Should Not Be Created')->first());
    }

    public function testGuestIsRedirectedToLoginFromAdminServersIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/servers');

        $result->assertRedirectTo('/login');
    }
}
