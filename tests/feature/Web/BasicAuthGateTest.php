<?php

use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * End-to-end HTTP-stack coverage for the basic-auth site gate
 * (`BasicAuthFilter` + `BasicAuth` controller): gate redirect when enabled
 * and unpassed, no redirect once passed, valid/invalid gate credentials,
 * CSRF enforcement, and the full gate -> app-login -> dashboard chain. See
 * `AuthWebFlowTest` for the app-login flow itself (it pre-passes this gate
 * in its own setUp()).
 *
 * @internal
 */
final class BasicAuthGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TEST_PASSWORD = 'Sup3rSecret!';
    private const GATE_USERNAME = 'proxy';
    private const GATE_PASSWORD = 'Gate#2026!';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testGuestHittingLoginWithoutGateSessionIsRedirectedToGate(): void
    {
        $result = $this->get('/login');

        $result->assertRedirectTo('/basic-auth');
    }

    public function testGuestHittingDashboardWithoutGateSessionIsRedirectedToGate(): void
    {
        $result = $this->get('/dashboard');

        $result->assertRedirectTo('/basic-auth');
    }

    public function testGateSessionFlagAllowsLoginToRenderInsteadOfGateRedirect(): void
    {
        $result = $this->withSession(['basicAuthAuthenticated' => 1])->get('/login');

        $result->assertStatus(200);
        $result->assertSee('Log in');
    }

    public function testGateSessionFlagAllowsDashboardToReachAuthFilterInsteadOfGateRedirect(): void
    {
        // No app login yet, so authFilter still redirects — but to /login,
        // never to /basic-auth, proving the gate itself was already passed.
        $result = $this->withSession(['basicAuthAuthenticated' => 1])->get('/dashboard');

        $result->assertRedirectTo('/login');
    }

    public function testGetGateRendersTheGateForm(): void
    {
        $result = $this->get('/basic-auth');

        $result->assertStatus(200);
        $result->assertSeeElement('form');
        $result->assertSee('name="username"');
        $result->assertSee('name="password"');
    }

    public function testCorrectGateCredentialsSucceedAndSetSessionFlag(): void
    {
        $result = $this->post('/basic-auth', $this->withCsrf([
            'username' => self::GATE_USERNAME,
            'password' => self::GATE_PASSWORD,
        ]));

        $result->assertStatus(200);
        $result->assertJSONFragment(['status' => 'success']);

        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('redirect_url', $json);
        $this->assertArrayHasKey('csrf_hash', $json);
        $this->assertSame(1, session('basicAuthAuthenticated'));
    }

    public function testWrongGateCredentialsFailWithoutSettingSessionFlag(): void
    {
        $result = $this->post('/basic-auth', $this->withCsrf([
            'username' => self::GATE_USERNAME,
            'password' => 'not-the-password',
        ]));

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'failed']);
        $this->assertNull(session('basicAuthAuthenticated'));
    }

    public function testGatePostWithoutCsrfTokenIsRejected(): void
    {
        Services::resetSingle('security');
        $result = $this->post('/basic-auth', [
            'username' => self::GATE_USERNAME,
            'password' => self::GATE_PASSWORD,
        ]);

        $result->assertRedirect();
        $this->assertNull(session('basicAuthAuthenticated'));
    }

    public function testGatePassThenAppLoginReachesAdminDashboard(): void
    {
        $user = $this->seedUser(['name' => 'Ada Admin', 'email' => 'gate-admin@example.com', 'zone' => 'admin']);

        $gate = $this->post('/basic-auth', $this->withCsrf([
            'username' => self::GATE_USERNAME,
            'password' => self::GATE_PASSWORD,
        ]));
        $gate->assertJSONFragment(['status' => 'success']);

        $login = $this->withSession(['basicAuthAuthenticated' => 1])->post('/login', $this->withCsrf([
            'email'    => $user->email,
            'password' => self::TEST_PASSWORD,
        ]));
        $login->assertJSONFragment(['status' => 'success', 'redirect_url' => '/admin/dashboard']);

        $dashboard = $this->withSession()->get('/admin/dashboard');

        $dashboard->assertStatus(200);
        $dashboard->assertSee('Ada Admin');
    }

    /**
     * `app.basic.auth.username` empty -> filter is a documented no-op.
     * Flipping `.env` mid-suite isn't safe here (other suites, e.g.
     * `AuthWebFlowTest`, run in the same process and rely on the gate being
     * enabled), so this exercises `BasicAuthFilter::before()` directly
     * against a request built without touching the process-wide env value:
     * `getenv()` is read live inside `before()`, so this only proves the
     * no-op branch reads correctly given an empty value, using PHP's
     * per-name env override rather than mutating `.env`.
     */
    public function testFilterIsNoOpWhenGateUsernameEnvIsEmpty(): void
    {
        $original = getenv('app.basic.auth.username');
        putenv('app.basic.auth.username=');

        try {
            $filter = new App\Filters\BasicAuthFilter();
            $result = $filter->before(service('request'));

            $this->assertNull($result);
        } finally {
            putenv('app.basic.auth.username=' . $original);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withCsrf(array $data): array
    {
        $security = config('Security');
        $hash     = bin2hex(random_bytes(16));

        service('superglobals')->setCookie($security->cookieName, $hash);
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
            'zone'              => 'user',
            'status'            => 1,
            'two_factor_method' => 'none',
            'language'          => 'en',
        ], $overrides));

        return $userModel->find($id);
    }
}
