<?php

use App\Models\SiteVariableModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin site settings (`Admin\Settings`), exercised through real routing +
 * filters (`authFilter`/`adminFilter`) + CSRF: CRUD, validation, soft-delete,
 * search/paginate, admin gating. `index()` renders a view that doesn't exist
 * yet (`frontend-coder` builds it next) — this suite only exercises routes
 * that return JSON, plus gating on `index()` (filters run before the
 * controller/view, so the view's absence doesn't matter there).
 *
 * @internal
 */
final class AdminSettingsTest extends CIUnitTestCase
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

    public function testCreateStoresSettingAndReturnsSuccessEnvelope(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/settings/create', $this->withCsrf([
            'name'        => 'app_name',
            'value'       => 'ProxyV2',
            'description' => 'App display name',
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $row = model(SiteVariableModel::class)->where('name', 'app_name')->first();
        $this->assertNotNull($row);
        $this->assertSame('ProxyV2', $row->value);
        $this->assertSame('App display name', $row->description);
    }

    public function testCreateRejectsMissingName(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $missingName = $this->withSession()->post('/admin/settings/create', $this->withCsrf([
            'name'  => '',
            'value' => 'x',
        ]));
        $missingName->assertStatus(400);

        $this->assertNull(model(SiteVariableModel::class)->where('value', 'x')->first());
    }

    public function testCreateRejectsDuplicateName(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'global']);
        model(SiteVariableModel::class)->insert(['name' => 'app_name', 'value' => 'v1']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/settings/create', $this->withCsrf([
            'name'  => 'app_name',
            'value' => 'v2',
        ]));

        $result->assertStatus(400);
        $this->assertSame(1, model(SiteVariableModel::class)->where('name', 'app_name')->countAllResults());
    }

    public function testEditReturnsSettingFields(): void
    {
        $admin  = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'global']);
        $model  = model(SiteVariableModel::class);
        $id     = $model->insert(['name' => 'edit_target', 'value' => 'original', 'description' => 'desc']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/settings/edit/' . $id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('edit_target', $json['setting']['name']);
        $this->assertSame('original', $json['setting']['value']);
        $this->assertSame('desc', $json['setting']['description']);
    }

    public function testEditRejectsUnknownSettingId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/settings/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdatePersistsEdits(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'global']);
        $model = model(SiteVariableModel::class);
        $id    = $model->insert(['name' => 'original_name', 'value' => 'v1']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/settings/update', $this->withCsrf([
            'id'          => $id,
            'name'        => 'updated_name',
            'value'       => 'v2',
            'description' => 'updated desc',
        ]));

        $update->assertStatus(200);

        $reloaded = $model->find($id);
        $this->assertSame('updated_name', $reloaded->name);
        $this->assertSame('v2', $reloaded->value);
        $this->assertSame('updated desc', $reloaded->description);
    }

    public function testUpdateAllowsKeepingOwnNameButRejectsAnotherRowsName(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'global']);
        $model = model(SiteVariableModel::class);
        $idA   = $model->insert(['name' => 'setting_a', 'value' => '1']);
        $idB   = $model->insert(['name' => 'setting_b', 'value' => '2']);
        $this->loginAs($admin);

        // Keeping its own name is fine (unique-except-self).
        $selfUpdate = $this->withSession()->post('/admin/settings/update', $this->withCsrf([
            'id'    => $idA,
            'name'  => 'setting_a',
            'value' => '1-updated',
        ]));
        $selfUpdate->assertStatus(200);

        // Colliding with another row's name is rejected.
        $collision = $this->withSession()->post('/admin/settings/update', $this->withCsrf([
            'id'    => $idB,
            'name'  => 'setting_a',
            'value' => '2',
        ]));
        $collision->assertStatus(400);
    }

    public function testUpdateRejectsUnknownSettingId(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/settings/update', $this->withCsrf([
            'id'    => 999999,
            'name'  => 'nope',
            'value' => '1',
        ]));

        $update->assertStatus(404);
    }

    public function testDeleteSoftDeletesSetting(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter@example.com', 'zone' => 'global']);
        $model = model(SiteVariableModel::class);
        $id    = $model->insert(['name' => 'delete_me', 'value' => '1']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/settings/delete', $this->withCsrf(['id' => $id]));

        $delete->assertStatus(200);
        $this->assertNull($model->find($id));
        $this->assertSame([], $model->searchAdmin(['keyword' => 'delete_me']));

        // Soft-deleted, not hard-deleted: still present with deleted_at set.
        $this->assertNotNull($model->withDeleted()->find($id)->deleted_at);
    }

    public function testDeleteRejectsUnknownSettingId(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/settings/delete', $this->withCsrf(['id' => 999999]));

        $delete->assertStatus(404);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/settings');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/settings/create', $this->withCsrf([
            'name'  => 'should_not_be_created',
            'value' => '1',
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(SiteVariableModel::class)->where('name', 'should_not_be_created')->first());
    }

    public function testGuestIsRedirectedToLoginFromAdminSettingsIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/settings');

        $result->assertRedirectTo('/login');
    }
}
