<?php

use App\Models\EmailTemplateModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin email templates (`Admin\EmailTemplates`), exercised through real
 * routing + filters (`authFilter`/`adminFilter`) + CSRF: CRUD, composite-
 * unique `(name, lang_code)` enforcement, soft delete, admin gating.
 * `index()` renders a view that doesn't exist yet (`frontend-coder` builds
 * it next) — this suite only exercises routes that return JSON, plus gating
 * on `index()` (filters run before the controller/view, so the view's
 * absence doesn't matter there).
 *
 * @internal
 */
final class AdminEmailTemplatesTest extends CIUnitTestCase
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

    public function testCreateStoresTemplateAndReturnsSuccessEnvelope(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/email-templates/create', $this->withCsrf([
            'name'      => 'signup_verify_email',
            'lang_code' => 'en',
            'subject'   => 'Verify your email',
            'content'   => '<p>Hello {name}</p>',
            'active'    => 1,
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $row = model(EmailTemplateModel::class)->where('name', 'signup_verify_email')->where('lang_code', 'en')->first();
        $this->assertNotNull($row);
        $this->assertSame('Verify your email', $row->subject);
        $this->assertSame('<p>Hello {name}</p>', $row->content);
        $this->assertSame(1, (int) $row->active);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $missing = $this->withSession()->post('/admin/email-templates/create', $this->withCsrf([
            'name'      => '',
            'lang_code' => '',
            'subject'   => '',
        ]));
        $missing->assertStatus(400);

        $this->assertSame(0, model(EmailTemplateModel::class)->countAllResults());
    }

    public function testCreateRejectsDuplicateNameAndLangCode(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'admin']);
        model(EmailTemplateModel::class)->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'v1', 'content' => '1']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/email-templates/create', $this->withCsrf([
            'name'      => 'signup_verify_email',
            'lang_code' => 'en',
            'subject'   => 'v2',
            'content'   => '2',
        ]));

        $result->assertStatus(400);
        $this->assertSame(
            1,
            model(EmailTemplateModel::class)->where('name', 'signup_verify_email')->where('lang_code', 'en')->countAllResults(),
        );
    }

    public function testCreateAllowsSameNameOnAnotherLanguage(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator4@example.com', 'zone' => 'admin']);
        model(EmailTemplateModel::class)->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => '1']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/email-templates/create', $this->withCsrf([
            'name'      => 'signup_verify_email',
            'lang_code' => 'ja',
            'subject'   => 'Verify JA',
            'content'   => '2',
        ]));

        $result->assertStatus(200);
        $this->assertSame(2, model(EmailTemplateModel::class)->where('name', 'signup_verify_email')->countAllResults());
    }

    public function testEditReturnsTemplateFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'admin']);
        $model = model(EmailTemplateModel::class);
        $id    = $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => 'Hi', 'active' => 1]);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/email-templates/edit/' . $id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('signup_verify_email', $json['template']['name']);
        $this->assertSame('en', $json['template']['lang_code']);
        $this->assertSame('Verify', $json['template']['subject']);
        $this->assertSame('Hi', $json['template']['content']);
        $this->assertSame(1, $json['template']['active']);
    }

    public function testEditRejectsUnknownTemplateId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/email-templates/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdatePersistsEdits(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'admin']);
        $model = model(EmailTemplateModel::class);
        $id    = $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => 'Hi', 'active' => 1]);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/email-templates/update', $this->withCsrf([
            'id'        => $id,
            'name'      => 'signup_verify_email',
            'lang_code' => 'en',
            'subject'   => 'Please verify',
            'content'   => 'Updated body',
            'active'    => 0,
        ]));

        $update->assertStatus(200);

        $reloaded = $model->find($id);
        $this->assertSame('Please verify', $reloaded->subject);
        $this->assertSame('Updated body', $reloaded->content);
        $this->assertSame(0, (int) $reloaded->active);
    }

    public function testUpdateAllowsKeepingOwnNameLangButRejectsAnotherRowsCombo(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'admin']);
        $model = model(EmailTemplateModel::class);
        $idA   = $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => '1', 'content' => '1']);
        $idB   = $model->insert(['name' => 'change_email', 'lang_code' => 'en', 'subject' => '2', 'content' => '2']);
        $this->loginAs($admin);

        // Keeping its own (name, lang_code) is fine (unique-except-self).
        $selfUpdate = $this->withSession()->post('/admin/email-templates/update', $this->withCsrf([
            'id'        => $idA,
            'name'      => 'signup_verify_email',
            'lang_code' => 'en',
            'subject'   => '1-updated',
            'content'   => '1-updated',
        ]));
        $selfUpdate->assertStatus(200);

        // Colliding with another row's (name, lang_code) is rejected.
        $collision = $this->withSession()->post('/admin/email-templates/update', $this->withCsrf([
            'id'        => $idB,
            'name'      => 'signup_verify_email',
            'lang_code' => 'en',
            'subject'   => '2',
            'content'   => '2',
        ]));
        $collision->assertStatus(400);
    }

    public function testUpdateRejectsUnknownTemplateId(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater3@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/email-templates/update', $this->withCsrf([
            'id'        => 999999,
            'name'      => 'nope',
            'lang_code' => 'en',
            'subject'   => '1',
            'content'   => '1',
        ]));

        $update->assertStatus(404);
    }

    public function testDeleteSoftDeletesTemplate(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter@example.com', 'zone' => 'admin']);
        $model = model(EmailTemplateModel::class);
        $id    = $model->insert(['name' => 'delete_me', 'lang_code' => 'en', 'subject' => '1', 'content' => '1']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/email-templates/delete', $this->withCsrf(['id' => $id]));

        $delete->assertStatus(200);
        $this->assertNull($model->find($id));
        $this->assertSame([], $model->searchAdmin(['keyword' => 'delete_me']));

        // Soft-deleted, not hard-deleted: still present with deleted_at set.
        $this->assertNotNull($model->withDeleted()->find($id)->deleted_at);
    }

    public function testDeleteRejectsUnknownTemplateId(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/email-templates/delete', $this->withCsrf(['id' => 999999]));

        $delete->assertStatus(404);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/email-templates');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/email-templates/create', $this->withCsrf([
            'name'      => 'should_not_be_created',
            'lang_code' => 'en',
            'subject'   => '1',
            'content'   => '1',
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(EmailTemplateModel::class)->where('name', 'should_not_be_created')->first());
    }

    public function testGuestIsRedirectedToLoginFromAdminEmailTemplatesIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/email-templates');

        $result->assertRedirectTo('/login');
    }
}
