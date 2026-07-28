<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use Config\Auth as AuthConfig;
use App\Libraries\PasswordHasher;
use App\Controllers\Auth;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `Auth::login` business logic, exercised directly via ControllerTestTrait
 * (bypasses routing/filters, so CSRF — a route-level concern, see
 * app/Config/Routes.php — is out of scope here).
 *
 * @internal
 */
final class AuthLoginTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const FIXTURE_TEST_PASSWORD = 'Sup3rSecret!';

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testLoginSuccessAdminRedirectsToAdminDashboard(): void
    {
        $this->seedUser(['email' => 'admin@example.com', 'zone' => 'admin']);

        $result = $this->postLogin('admin@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(200);
        $result->assertJSONFragment(['status' => 'success', 'redirect_url' => '/admin/dashboard']);
        $this->assertSame('admin@example.com', session('userAuthenticated')->email);
        $this->assertSame('admin@example.com', session('userWorking')->email);
        $this->assertNotEmpty(json_decode($result->response()->getJSON(), true)['csrf_hash']);
    }

    public function testLoginSuccessClientRedirectsToDashboard(): void
    {
        $this->seedUser(['email' => 'client@example.com', 'zone' => 'user']);

        $result = $this->postLogin('client@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(200);
        $result->assertJSONFragment(['status' => 'success', 'redirect_url' => '/dashboard']);
    }

    public function testLoginStampsLoginAt(): void
    {
        $user = $this->seedUser(['email' => 'stamp@example.com']);
        $this->assertNull($user->login_at);

        $this->postLogin('stamp@example.com', self::FIXTURE_TEST_PASSWORD);

        $refreshed = model(UserModel::class)->find($user->id);
        $this->assertNotNull($refreshed->login_at);
    }

    public function testLoginRegeneratesSessionIdToPreventFixation(): void
    {
        $this->seedUser(['email' => 'fixation@example.com', 'zone' => 'user']);

        // CIUnitTestCase swaps in MockSession, which doesn't touch the real
        // PHP session id (session_start() is stubbed out) but does flip
        // `didRegenerate` when Session::regenerate() runs — use that to
        // confirm the controller calls it on the login success path.
        $this->postLogin('fixation@example.com', self::FIXTURE_TEST_PASSWORD);

        $this->assertTrue(session()->didRegenerate);
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $this->seedUser(['email' => 'wrongpw@example.com']);

        $result = $this->postLogin('wrongpw@example.com', 'not-the-right-one');

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'failed']);
        $this->assertNull(session('userAuthenticated'));
        $this->assertNotEmpty(json_decode($result->response()->getJSON(), true)['csrf_hash']);
    }

    public function testLoginFailsForUnknownEmail(): void
    {
        $result = $this->postLogin('nobody@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'failed']);
    }

    public function testLoginRejectsDisabledStatus(): void
    {
        $this->seedUser(['email' => 'disabled@example.com', 'status' => 0]);

        $result = $this->postLogin('disabled@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(401);
        $this->assertNull(session('userAuthenticated'));
    }

    public function testLoginRejectsSuspendedStatus(): void
    {
        $this->seedUser(['email' => 'suspended@example.com', 'status' => 3]);

        $result = $this->postLogin('suspended@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(401);
    }

    public function testLoginRejectsSoftDeletedUser(): void
    {
        $user = $this->seedUser(['email' => 'deleted@example.com']);
        model(UserModel::class)->delete($user->id);

        $result = $this->postLogin('deleted@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(401);
        $this->assertNull(session('userAuthenticated'));
    }

    public function testLoginValidationRejectsInvalidEmail(): void
    {
        $result = $this->postLogin('not-an-email', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'failed']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedUser(array $overrides = []): object
    {
        $userModel  = model(UserModel::class);

        $id = $userModel->insert(array_merge([
            'name'              => 'Test User',
            'email'             => 'user@example.com',
            'password'          => (new PasswordHasher())->hash(self::FIXTURE_TEST_PASSWORD),
            'zone'              => 'user',
            'status'            => 1,
            'two_factor_method' => 'none',
            'language'          => 'en',
        ], $overrides));

        return $userModel->find($id);
    }

    private function postLogin(string $email, string $password)
    {
        $data = ['email' => $email, 'password' => $password];

        // Validation::withRequest() reads $request->getVar(), which pulls
        // from the `request` superglobal ($_REQUEST) rather than `post` —
        // populate both so the controller sees the same input either way.
        service('superglobals')->setPostArray($data);
        service('superglobals')->setRequestArray($data);

        // ControllerTestTrait caches the IncomingRequest it built, and an
        // IncomingRequest snapshots the superglobals at construction. Without
        // dropping it, a *second* postLogin() in one test method is validated
        // against the first call's input.
        Services::resetSingle('request');
        Services::resetSingle('validation');
        $this->withRequest(service('incomingrequest', null, false));

        return $this->controller(Auth::class)->execute('login');
    }

    // ------------------------------------------- legacy hash self-migration

    /**
     * An account still holding the pre-migration unsalted MD5 digest must log in
     * normally, and the row must be rewritten with a modern hash on the way
     * through — that is the whole migration strategy. Nobody has the plaintext
     * except the person typing it, so login is the only place this can happen.
     */
    public function testALegacyMd5PasswordLogsInAndIsUpgradedInPlace(): void
    {
        $user = $this->seedUser([
            'email'    => 'legacy@example.com',
            'password' => md5(self::FIXTURE_TEST_PASSWORD),
        ]);

        $this->assertSame(32, strlen((string) $user->password), 'Fixture should start as a bare MD5 digest.');

        $result = $this->postLogin('legacy@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertJSONFragment(['status' => 'success']);

        $stored = model(UserModel::class)->find($user->id)->password;
        $hasher = new PasswordHasher();

        $this->assertStringStartsWith('$2y$', (string) $stored, 'The MD5 row should have been rehashed.');
        $this->assertFalse($hasher->needsRehash($stored));
        $this->assertTrue($hasher->verify(self::FIXTURE_TEST_PASSWORD, $stored));

        // And the upgraded row keeps working on the next login.
        session()->destroy();
        $this->postLogin('legacy@example.com', self::FIXTURE_TEST_PASSWORD)
            ->assertJSONFragment(['status' => 'success']);
    }

    public function testAWrongPasswordAgainstALegacyHashIsStillRejectedAndLeavesItAlone(): void
    {
        $user = $this->seedUser([
            'email'    => 'legacy2@example.com',
            'password' => md5(self::FIXTURE_TEST_PASSWORD),
        ]);

        $result = $this->postLogin('legacy2@example.com', 'not-the-password');

        $result->assertStatus(401);
        // A failed attempt must not touch the stored hash — otherwise a wrong
        // guess would be enough to overwrite somebody's credentials.
        $this->assertSame(md5(self::FIXTURE_TEST_PASSWORD), model(UserModel::class)->find($user->id)->password);
    }

    /**
     * Raising `auth.password_cost` must not force a reset: the old hash verifies,
     * then gets rewritten at the new cost.
     */
    public function testRaisingTheCostRehashesOnNextLogin(): void
    {
        $cheap = new PasswordHasher($this->authConfig(4));
        $user  = $this->seedUser(['email' => 'cheap@example.com', 'password' => $cheap->hash(self::FIXTURE_TEST_PASSWORD)]);

        $this->assertSame(4, password_get_info((string) $user->password)['options']['cost']);

        // The app-wide config the controller will read.
        Factories::injectMock('config', 'Auth', $this->authConfig(6));

        $this->postLogin('cheap@example.com', self::FIXTURE_TEST_PASSWORD)
            ->assertJSONFragment(['status' => 'success']);

        $stored = model(UserModel::class)->find($user->id)->password;
        $this->assertSame(6, password_get_info((string) $stored)['options']['cost']);
    }

    private function authConfig(int $cost): AuthConfig
    {
        $config                     = new AuthConfig();
        $config->password_algorithm = 'bcrypt';
        $config->password_cost      = $cost;

        return $config;
    }
}
