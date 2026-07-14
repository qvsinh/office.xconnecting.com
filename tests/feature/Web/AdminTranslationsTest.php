<?php

use App\Models\TranslationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin translations (`Admin\Translations`), exercised through real routing +
 * filters (`authFilter`/`adminFilter`) + CSRF: CRUD, composite-unique
 * `(lang_code, slug)` enforcement, hard delete, export, admin gating.
 * `index()` renders a view that doesn't exist yet (`frontend-coder` builds
 * it next) — this suite only exercises routes that return JSON, plus gating
 * on `index()` (filters run before the controller/view, so the view's
 * absence doesn't matter there).
 *
 * @internal
 */
final class AdminTranslationsTest extends CIUnitTestCase
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

    public function testCreateStoresTranslationAndReturnsSuccessEnvelope(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/translations/create', $this->withCsrf([
            'lang_code' => 'en',
            'slug'      => 'welcome',
            'location'  => 'home',
            'content'   => 'Welcome aboard',
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $row = model(TranslationModel::class)->where('lang_code', 'en')->where('slug', 'welcome')->first();
        $this->assertNotNull($row);
        $this->assertSame('Welcome aboard', $row->content);
        $this->assertSame('home', $row->location);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $missing = $this->withSession()->post('/admin/translations/create', $this->withCsrf([
            'lang_code' => '',
            'slug'      => '',
            'content'   => '',
        ]));
        $missing->assertStatus(400);

        $this->assertSame(0, model(TranslationModel::class)->countAllResults());
    }

    public function testCreateRejectsDuplicateLangCodeAndSlug(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'global']);
        model(TranslationModel::class)->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'v1']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/translations/create', $this->withCsrf([
            'lang_code' => 'en',
            'slug'      => 'welcome',
            'content'   => 'v2',
        ]));

        $result->assertStatus(400);
        $this->assertSame(
            1,
            model(TranslationModel::class)->where('lang_code', 'en')->where('slug', 'welcome')->countAllResults(),
        );
    }

    public function testCreateAllowsSameSlugOnAnotherLanguage(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator4@example.com', 'zone' => 'global']);
        model(TranslationModel::class)->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/translations/create', $this->withCsrf([
            'lang_code' => 'ja',
            'slug'      => 'welcome',
            'content'   => 'ようこそ',
        ]));

        $result->assertStatus(200);
        $this->assertSame(2, model(TranslationModel::class)->where('slug', 'welcome')->countAllResults());
    }

    public function testEditReturnsTranslationFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'global']);
        $model = model(TranslationModel::class);
        $id    = $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'location' => 'home', 'content' => 'Welcome']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/translations/edit/' . $id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('en', $json['translation']['lang_code']);
        $this->assertSame('welcome', $json['translation']['slug']);
        $this->assertSame('home', $json['translation']['location']);
        $this->assertSame('Welcome', $json['translation']['content']);
    }

    public function testEditRejectsUnknownTranslationId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/translations/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdatePersistsEdits(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'global']);
        $model = model(TranslationModel::class);
        $id    = $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/translations/update', $this->withCsrf([
            'id'        => $id,
            'lang_code' => 'en',
            'slug'      => 'welcome-updated',
            'location'  => 'header',
            'content'   => 'Welcome, updated',
        ]));

        $update->assertStatus(200);

        $reloaded = $model->find($id);
        $this->assertSame('welcome-updated', $reloaded->slug);
        $this->assertSame('header', $reloaded->location);
        $this->assertSame('Welcome, updated', $reloaded->content);
    }

    public function testUpdateAllowsKeepingOwnLangSlugButRejectsAnotherRowsCombo(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'global']);
        $model = model(TranslationModel::class);
        $idA   = $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => '1']);
        $idB   = $model->insert(['lang_code' => 'en', 'slug' => 'goodbye', 'content' => '2']);
        $this->loginAs($admin);

        // Keeping its own (lang_code, slug) is fine (unique-except-self).
        $selfUpdate = $this->withSession()->post('/admin/translations/update', $this->withCsrf([
            'id'        => $idA,
            'lang_code' => 'en',
            'slug'      => 'welcome',
            'content'   => '1-updated',
        ]));
        $selfUpdate->assertStatus(200);

        // Colliding with another row's (lang_code, slug) is rejected.
        $collision = $this->withSession()->post('/admin/translations/update', $this->withCsrf([
            'id'        => $idB,
            'lang_code' => 'en',
            'slug'      => 'welcome',
            'content'   => '2',
        ]));
        $collision->assertStatus(400);
    }

    public function testUpdateRejectsUnknownTranslationId(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/translations/update', $this->withCsrf([
            'id'        => 999999,
            'lang_code' => 'en',
            'slug'      => 'nope',
            'content'   => '1',
        ]));

        $update->assertStatus(404);
    }

    public function testDeleteHardRemovesTranslation(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter@example.com', 'zone' => 'global']);
        $model = model(TranslationModel::class);
        $id    = $model->insert(['lang_code' => 'en', 'slug' => 'delete_me', 'content' => '1']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/translations/delete', $this->withCsrf(['id' => $id]));

        $delete->assertStatus(200);
        $this->assertNull($model->find($id));
        $this->assertSame(0, $model->builder()->where('id', $id)->countAllResults());
    }

    public function testDeleteRejectsUnknownTranslationId(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/translations/delete', $this->withCsrf(['id' => 999999]));

        $delete->assertStatus(404);
    }

    public function testExportReturnsJsonMapForChosenLanguage(): void
    {
        $admin = $this->seedWebUser(['email' => 'exporter@example.com', 'zone' => 'global']);
        model(TranslationModel::class)->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);
        model(TranslationModel::class)->insert(['lang_code' => 'ja', 'slug' => 'welcome', 'content' => 'ようこそ']);
        $this->loginAs($admin);

        $export = $this->withSession()->get('/admin/translations/export?lang_code=en');

        $export->assertStatus(200);
        $export->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $export->assertHeader('Content-Disposition', 'attachment; filename="translations-en.json"');

        $body = json_decode($export->response()->getBody(), true);
        $this->assertSame(['welcome' => 'Welcome'], $body);
    }

    public function testExportWithoutLangCodeReturnsBadRequest(): void
    {
        $admin = $this->seedWebUser(['email' => 'exporter2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $export = $this->withSession()->get('/admin/translations/export');

        $export->assertStatus(400);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/translations');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/translations/create', $this->withCsrf([
            'lang_code' => 'en',
            'slug'      => 'should_not_be_created',
            'content'   => '1',
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(TranslationModel::class)->where('slug', 'should_not_be_created')->first());
    }

    public function testGuestIsRedirectedToLoginFromAdminTranslationsIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/translations');

        $result->assertRedirectTo('/login');
    }
}
