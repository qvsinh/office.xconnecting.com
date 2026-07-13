<?php

use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * End-to-end HTTP-stack coverage for web auth + shell: real routing,
 * filters (authFilter/adminFilter/guestFilter), CSRF, and view rendering —
 * everything `AuthLoginTest` (ControllerTestTrait) and `AuthFiltersTest`
 * (FilterTestTrait) bypass by design. See those files for the isolated
 * unit-level coverage of the same business rules.
 *
 * CSRF note: `Config\Security::$csrfProtection` is `'cookie'` (double-submit
 * cookie, not tied to session), so a valid pair is just a matching cookie +
 * posted token value — `withCsrf()` below fabricates one instead of
 * disabling the filter, keeping the real CSRF-required behaviour in the
 * loop for the one route that needs it.
 *
 * @internal
 */
final class AuthWebFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TEST_PASSWORD = 'Sup3rSecret!';

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite covers the app-login flow, not the basic-auth site
        // gate itself — the sandbox `.env` has `app.basic.auth.username`
        // set, so every request would otherwise get redirected to
        // `/basic-auth` first. Pre-pass the gate here; `withSession()`
        // calls further down capture this flag along with whatever the
        // request itself sets.
        $this->withSession(['basicAuthAuthenticated' => 1]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testGetLoginRendersTheLoginForm(): void
    {
        $result = $this->get('/login');

        $result->assertStatus(200);
        $result->assertSee('Log in');
        $result->assertSeeElement('form');
        // Alpine (x-model, no `value` attr) drives the field, so assert on
        // the rendered input markup itself rather than assertSeeInField().
        $result->assertSee('name="email"');
        $result->assertSee('name="password"');
    }

    public function testAdminCanLoginThroughRoutingAndReachAdminDashboard(): void
    {
        $user = $this->seedUser(['name' => 'Ada Admin', 'email' => 'admin@example.com', 'zone' => 'global']);

        $login = $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::TEST_PASSWORD]));

        $login->assertStatus(200);
        $login->assertJSONFragment(['status' => 'success', 'redirect_url' => '/admin/dashboard']);

        $dashboard = $this->withSession()->get('/admin/dashboard');

        $dashboard->assertStatus(200);
        $dashboard->assertSee('Ada Admin');
    }

    public function testClientCanLoginThroughRoutingAndReachClientDashboard(): void
    {
        $user = $this->seedUser(['name' => 'Cara Client', 'email' => 'client@example.com', 'zone' => 'client']);

        $login = $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::TEST_PASSWORD]));

        $login->assertStatus(200);
        $login->assertJSONFragment(['status' => 'success', 'redirect_url' => '/dashboard']);

        $dashboard = $this->withSession()->get('/dashboard');

        $dashboard->assertStatus(200);
        $dashboard->assertSee('Cara Client');
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $this->seedUser(['email' => 'nocsrf@example.com']);

        // Also force a fresh `security` instance so this test doesn't inherit
        // whatever hash a prior test/request happened to leave cached.
        Services::resetSingle('security');
        $result = $this->post('/login', ['email' => 'nocsrf@example.com', 'password' => self::TEST_PASSWORD]);

        // `security.redirect = true` (.env) makes CSRF::before() redirect
        // back on failure (non-AJAX) instead of throwing a 403 — either way,
        // the request is rejected before it ever reaches Auth::login(), and
        // the session/login never got established.
        $result->assertRedirect();
        $this->assertNull(session('userAuthenticated'));
    }

    public function testGuestIsRedirectedToLoginFromAdminDashboard(): void
    {
        $result = $this->get('/admin/dashboard');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromClientDashboard(): void
    {
        $result = $this->get('/dashboard');

        $result->assertRedirectTo('/login');
    }

    public function testClientUserIsBlockedFromAdminDashboard(): void
    {
        $user = $this->seedUser(['email' => 'client2@example.com', 'zone' => 'client']);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::TEST_PASSWORD]));

        $result = $this->withSession()->get('/admin/dashboard');

        $result->assertRedirectTo('/dashboard');
    }

    public function testAuthenticatedUserIsRedirectedAwayFromLogin(): void
    {
        $user = $this->seedUser(['email' => 'already@example.com', 'zone' => 'global']);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::TEST_PASSWORD]));

        $result = $this->withSession()->get('/login');

        $result->assertRedirectTo('/admin/dashboard');
    }

    public function testLogoutDestroysSessionAndSubsequentRequestRedirectsToLogin(): void
    {
        $user = $this->seedUser(['email' => 'logout@example.com', 'zone' => 'client']);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::TEST_PASSWORD]));

        $logout = $this->withSession()->get('/logout');
        $logout->assertRedirectTo('/login');

        // CodeIgniter\Session\Session::destroy() is a documented no-op when
        // ENVIRONMENT === 'testing' (always true under PHPUnit) — it never
        // actually clears $_SESSION in this harness, which is a framework
        // test-harness limitation, not a defect in Auth::logout(). Simulate
        // the post-destroy state (empty session, `basicAuthAuthenticated`
        // re-primed to isolate authFilter's own behaviour — the real gate
        // re-prompting after a full session destroy is covered by the
        // basic-auth gate's own tests) to verify authFilter still blocks
        // the next request once the session really is gone.
        $result = $this->withSession(['basicAuthAuthenticated' => 1])->get('/dashboard');
        $result->assertRedirectTo('/login');
    }

    public function testDashboardEscapesUserSuppliedName(): void
    {
        $user = $this->seedUser([
            'name'  => '<script>alert(1)</script>',
            'email' => 'xss@example.com',
            'zone'  => 'client',
        ]);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::TEST_PASSWORD]));

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertDontSee('<script>alert(1)</script>');
        $result->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;');
    }

    /**
     * Fabricates a valid double-submit CSRF cookie+token pair for the login
     * POST (Config\Security::$csrfProtection === 'cookie', tokenRandomize
     * === false, so a matching hash in both places is all `Security::verify()`
     * checks). The `security` service is force-recreated against the fresh
     * cookie: it's a shared singleton that FeatureTestTrait's call() does NOT
     * reset between requests (unlike `request`/`filters`/`validation`), so a
     * prior request in the same run would otherwise freeze in an unrelated
     * hash and make every subsequent CSRF check fail.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withCsrf(array $data): array
    {
        $security = config('Security');
        $hash     = bin2hex(random_bytes(16));

        service('superglobals')->setCookie($security->cookieName, $hash);
        // Force a fresh Security instance on next access (inside the CSRF
        // filter, once the new request for this call is in place) instead
        // of eagerly building one now against a still-cached prior request.
        Services::resetSingle('security');

        $data[$security->tokenName] = $hash;

        return $data;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedUser(array $overrides = []): object
    {
        $authConfig = config('Auth');
        $userModel  = model(UserModel::class);

        $id = $userModel->insert(array_merge([
            'name'              => 'Test User',
            'email'             => 'user@example.com',
            'password'          => hash($authConfig->password_algorithm, self::TEST_PASSWORD . $authConfig->password_salt),
            'zone'              => 'client',
            'status'            => 1,
            'two_factor_method' => 'none',
            'language'          => 'en',
        ], $overrides));

        return $userModel->find($id);
    }
}
