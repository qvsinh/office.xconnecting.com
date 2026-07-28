<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the self-service account area
 * (`account/profile.php`, `account/security.php`) and the pending-2FA
 * challenge page (`auth/verify_2fa.php`). Controller/model logic (profile
 * update, password change, 2FA enable/disable, IDOR scoping, secret
 * redaction) is already covered by AccountTest, Auth2faLoginTest,
 * Google2faServiceTest and UserModelTest — this suite closes the remaining
 * gap: do the pages actually render, is `google2fa_secret` ever leaked into
 * HTML, is profile data escaped, and is every route correctly gated.
 *
 * @internal
 */
final class AccountViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    private const XSS_SCRIPT = '<script>alert(1)</script>';
    private const A_SECRET   = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testProfilePageRendersFormsForLoggedInUser(): void
    {
        $user = $this->seedWebUser(['email' => 'view-account-profile@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/account');

        $result->assertStatus(200);
        $result->assertSee('Profile');
        $result->assertSee('Change password');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('accountProfile(', $body);
        $this->assertStringContainsString(esc($user->email, 'attr'), $body);
    }

    /**
     * The topbar identity must survive pages that pass their own `$user`.
     *
     * `/account` and `/account/security` pass `$user` as an **array**
     * (profileData()), and the layout used to fall back with
     * `$user ??= session('userWorking')` — so the array won and the avatar
     * initial plus the name/email in the header rendered empty. The layout now
     * reads `$navUser` straight from the session; this pins that down.
     *
     * @dataProvider accountPageProvider
     */
    public function testHeaderShowsTheSignedInIdentityOnPagesThatPassTheirOwnUserVariable(string $page): void
    {
        $user = $this->seedWebUser([
            'email' => 'headercheck@example.com',
            'name'  => 'Header Check Person',
        ]);
        $this->loginAs($user);

        $result = $this->withSession()->get($page);

        $result->assertStatus(200);
        $result->assertSee('Header Check Person');
        $result->assertSee('headercheck@example.com');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function accountPageProvider(): array
    {
        return [['/account'], ['/account/security']];
    }

    public function testSecurityPageRendersStatusWithoutSecret(): void
    {
        $user = $this->seedWebUser(['email' => 'view-account-security@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/account/security');

        $result->assertStatus(200);
        $result->assertSee('Two-factor authentication');
        $result->assertSee('Enable 2FA');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('accountSecurity(', $body);
        $this->assertStringNotContainsString('google2fa_secret', $body);
    }

    public function testVerify2faPageRendersCodeFormWhenPending(): void
    {
        $user = $this->seedWebUser(['email' => 'view-verify2fa-pending@example.com', 'zone' => 'user']);
        $this->withSession(['basicAuthAuthenticated' => 1, 'pending2faUserId' => $user->id]);

        $result = $this->get('/verify-2fa');

        $result->assertStatus(200);
        $result->assertSee('Two-factor verification');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('verify2faForm(', $body);
    }

    public function testVerify2faPageRedirectsToLoginWhenNoPendingAndNotAuthenticated(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/verify-2fa');

        $result->assertRedirectTo('/login');
    }

    public function testVerify2faPageRedirectsToDashboardWhenAlreadyAuthenticated(): void
    {
        $user = $this->seedWebUser(['email' => 'view-verify2fa-authed@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/verify-2fa');

        $result->assertRedirectTo('/dashboard');
    }

    public function testGoogle2faSecretNeverAppearsOnProfileOrSecurityPages(): void
    {
        // Seeded already-2FA-enabled: authenticated directly via session (not
        // loginAs()/`/login`), because a real login for a 2fa-enabled account
        // now stops at the pending-2FA state (see Auth2faLoginTest) — this
        // test is about page rendering once authenticated, not the login flow.
        $user = $this->seedWebUser([
            'email'             => 'view-account-secret@example.com',
            'zone'              => 'user',
            'two_factor_method' => 'google2fa',
            'google2fa_secret'  => self::A_SECRET,
        ]);
        $this->withSession(['basicAuthAuthenticated' => 1, 'userAuthenticated' => $user, 'userWorking' => $user]);

        // First request runs with the session values just set above; only
        // after it persists to $_SESSION does a bare withSession()->get()
        // (which reads $_SESSION) carry the same identity forward.
        $profile  = $this->get('/account');
        $security = $this->withSession()->get('/account/security');

        $profile->assertStatus(200);
        $security->assertStatus(200);
        $this->assertStringNotContainsString(self::A_SECRET, (string) $profile->response()->getBody());
        $this->assertStringNotContainsString(self::A_SECRET, (string) $security->response()->getBody());
    }

    /**
     * Profile name/company are fed to Alpine via `json_encode()` inside the
     * page's own `<script>` block (JS-string context, not HTML), so the
     * closing `</script>` is emitted with an escaped slash (`<\/script>`) —
     * the raw XSS payload (with its real closing tag) must never appear.
     */
    public function testProfileFieldsWithScriptTagAreEscaped(): void
    {
        $user = $this->seedWebUser([
            'email'   => 'view-account-xss@example.com',
            'zone'    => 'user',
            'name'    => self::XSS_SCRIPT,
            'company' => self::XSS_SCRIPT,
        ]);
        $this->loginAs($user);

        $result = $this->withSession()->get('/account');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $body);
        $this->assertStringContainsString('<script>alert(1)<\/script>', $body);
    }

    public function testDashboardIncludesAccountLink(): void
    {
        $user = $this->seedWebUser(['email' => 'view-account-nav@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('href="/account"', $body);
    }

    public function testGuestIsRedirectedToLoginFromAccountProfile(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/account');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromAccountSecurity(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/account/security');

        $result->assertRedirectTo('/login');
    }
}
