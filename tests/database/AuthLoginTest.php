<?php

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
        $this->seedUser(['email' => 'admin@example.com', 'zone' => 'global']);

        $result = $this->postLogin('admin@example.com', self::FIXTURE_TEST_PASSWORD);

        $result->assertStatus(200);
        $result->assertJSONFragment(['status' => 'success', 'redirect_url' => '/admin/dashboard']);
        $this->assertSame('admin@example.com', session('userAuthenticated')->email);
        $this->assertSame('admin@example.com', session('userWorking')->email);
    }

    public function testLoginSuccessClientRedirectsToDashboard(): void
    {
        $this->seedUser(['email' => 'client@example.com', 'zone' => 'client']);

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

    public function testLoginFailsWithWrongPassword(): void
    {
        $this->seedUser(['email' => 'wrongpw@example.com']);

        $result = $this->postLogin('wrongpw@example.com', 'not-the-right-one');

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'failed']);
        $this->assertNull(session('userAuthenticated'));
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
        $authConfig = config('Auth');
        $userModel  = model(UserModel::class);

        $id = $userModel->insert(array_merge([
            'name'              => 'Test User',
            'email'             => 'user@example.com',
            'password'          => hash($authConfig->password_algorithm, self::FIXTURE_TEST_PASSWORD . $authConfig->password_salt),
            'zone'              => 'client',
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

        return $this->controller(Auth::class)->execute('login');
    }
}
