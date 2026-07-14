<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the admin user management UI
 * (`admin/users/index.php`, `admin/users/detail.php`,
 * `admin/users/note.php`) plus the shared "Acting as ..." banner in
 * `layouts/app.php`. Controller/model logic (CRUD, lockout guards,
 * act-as security) is already covered by AdminUsersTest and
 * AdminActAsTest — this suite closes the remaining gap: does each view
 * actually render, is dynamic output (name/company/note) escaped, does
 * the password hash ever leak into a response, and does the impersonation
 * banner show/hide correctly.
 *
 * @internal
 */
final class AdminUsersViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    private const XSS_SCRIPT = '<script>alert(1)</script>';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testIndexRendersTheListWithSearchFormAndAddUserControl(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-users-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/users');

        $result->assertStatus(200);
        $result->assertSee('Users');
        $result->assertSee('+ Add user');
        $result->assertSeeElement('table');
        $result->assertSeeElement('form');
    }

    public function testDetailRendersProfileActAsButtonAndApiKeysLink(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-users-admin2@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'view-users-target@example.com', 'zone' => 'client']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/users/view/' . $target->id);

        $result->assertStatus(200);
        $result->assertSee('Act as this user');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('/admin/api-access-keys?keyword=', $body);
    }

    public function testNoteViewRendersTheNoteEditor(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-users-admin3@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'view-users-note-target@example.com', 'zone' => 'client']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/users/note/' . $target->id);

        $result->assertStatus(200);
        $result->assertSee('Admin note');
        $result->assertSeeElement('textarea');
    }

    public function testNameAndCompanyXssIsEscapedOnListAndDetailPages(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-users-admin4@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser([
            'email' => 'view-users-xss@example.com',
            'zone'  => 'client',
            'name'  => self::XSS_SCRIPT,
        ]);
        // `company` is intentionally not in UserModel::$allowedFields (no
        // create/edit UI field for it yet), so it can't be mass-assigned
        // via seedWebUser()/insert() — set it with the query builder
        // directly, test-only, to exercise its escaping on detail.php.
        db_connect()->table('users')->where('id', $target->id)->update(['company' => self::XSS_SCRIPT]);
        $this->loginAs($admin);

        $index = $this->withSession()->get('/admin/users');
        $index->assertStatus(200);
        $indexBody = (string) $index->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $indexBody);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $indexBody);

        $detail = $this->withSession()->get('/admin/users/view/' . $target->id);
        $detail->assertStatus(200);
        $detailBody = (string) $detail->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $detailBody);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $detailBody);
    }

    public function testAdminNoteXssIsEscapedOnDetailAndNotePages(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-users-admin5@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'view-users-note-xss@example.com', 'zone' => 'client']);
        model(UserModel::class)->update($target->id, ['note' => self::XSS_SCRIPT]);
        $this->loginAs($admin);

        $detail = $this->withSession()->get('/admin/users/view/' . $target->id);
        $detail->assertStatus(200);
        $detailBody = (string) $detail->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $detailBody);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $detailBody);

        // note.php only ever emits the note via json_encode() wrapped in
        // esc(..., 'attr') inside the Alpine x-data payload (see note.php),
        // so the escaped form there is the stricter numeric-entity encoding
        // (matches the pattern in ApiKeysViewTest's client XSS assertion).
        $note = $this->withSession()->get('/admin/users/note/' . $target->id);
        $note->assertStatus(200);
        $noteBody = (string) $note->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $noteBody);
        $this->assertStringContainsString('&lt;script&gt;alert&#x28;1&#x29;&lt;&#x5C;&#x2F;script&gt;', $noteBody);
    }

    public function testPasswordHashNeverAppearsInIndexOrEditResponses(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-users-admin6@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'view-users-pwd@example.com', 'zone' => 'client']);
        $this->loginAs($admin);
        $hash = model(UserModel::class)->find($target->id)->password;

        $index = $this->withSession()->get('/admin/users');
        $index->assertStatus(200);
        $this->assertStringNotContainsString($hash, (string) $index->response()->getBody());

        $edit = $this->withSession()->get('/admin/users/edit/' . $target->id);
        $edit->assertStatus(200);
        $this->assertStringNotContainsString($hash, $edit->getJSON());
        $editJson = json_decode($edit->getJSON(), true);
        $this->assertArrayNotHasKey('password', $editJson['user']);
    }

    public function testActingAsBannerAppearsWhileImpersonatingAndDisappearsAfterStopActing(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-users-admin7@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['name' => 'Banner Target', 'email' => 'view-users-banner@example.com', 'zone' => 'client']);
        $this->loginAs($admin);

        $noBanner = $this->withSession()->get('/dashboard');
        $noBanner->assertStatus(200);
        $this->assertStringNotContainsString('Acting as', (string) $noBanner->response()->getBody());

        $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));

        $withBanner = $this->withSession()->get('/dashboard');
        $withBanner->assertStatus(200);
        $withBannerBody = (string) $withBanner->response()->getBody();
        $this->assertStringContainsString('Acting as', $withBannerBody);
        $this->assertStringContainsString('Banner Target', $withBannerBody);
        $this->assertStringContainsString('Return to admin', $withBannerBody);

        $this->withSession()->post('/admin/users/stop-acting-as', $this->withCsrf([]));

        $afterStop = $this->withSession()->get('/dashboard');
        $afterStop->assertStatus(200);
        $this->assertStringNotContainsString('Acting as', (string) $afterStop->response()->getBody());
    }

    public function testGuestIsRedirectedToLoginFromUsersIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/users');

        $result->assertRedirectTo('/login');
    }

    public function testClientSessionIsBlockedFromUsersIndexByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'view-users-client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/admin/users');

        $result->assertRedirectTo('/dashboard');
    }
}
