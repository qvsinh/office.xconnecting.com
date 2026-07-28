<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Web\WebAuthFixture;

/**
 * Login-time 2FA enforcement (`Auth::login()` pending branch +
 * `Auth::showVerify2fa()`/`verify2fa()`). Additive: the non-2FA path is
 * covered byte-for-byte by `AuthLoginTest`/`AuthWebFlowTest`, which must stay
 * green — this suite only exercises the new `two_factor_method=google2fa`
 * branch and the `/verify-2fa` challenge.
 *
 * @internal
 */
final class Auth2faLoginTest extends CIUnitTestCase
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

    public function testLoginWithTwoFactorEnabledDoesNotAuthenticateAndReturnsPendingRedirect(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-login@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $result = $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::WEB_AUTH_TEST_PASSWORD]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertTrue($json['requires_2fa']);
        $this->assertSame('/verify-2fa', $json['redirect_url']);
        $this->assertNull(session('userAuthenticated'));
        $this->assertNull(session('userWorking'));
        $this->assertSame((int) $user->id, (int) session('pending2faUserId'));
        $this->assertNull(model(UserModel::class)->find($user->id)->login_at); // not stamped until 2nd factor
    }

    public function testVerify2faWithCorrectCodeCompletesLoginAndStampsLoginAt(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-verify-correct@example.com', 'zone' => 'user', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::WEB_AUTH_TEST_PASSWORD]));

        $code   = (new Google2FA())->getCurrentOtp($secret);
        $result = $this->withSession()->post('/verify-2fa', $this->withCsrf(['code' => $code]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('/dashboard', $json['redirect_url']);
        $this->assertSame((int) $user->id, (int) session('userAuthenticated')->id);
        $this->assertSame((int) $user->id, (int) session('userWorking')->id);
        $this->assertNull(session('pending2faUserId'));
        $this->assertNotNull(model(UserModel::class)->find($user->id)->login_at);
    }

    public function testVerify2faWithWrongCodeStaysPending(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-verify-wrong@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::WEB_AUTH_TEST_PASSWORD]));

        $result = $this->withSession()->post('/verify-2fa', $this->withCsrf(['code' => '000000']));

        $result->assertStatus(401);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
        $this->assertNull(session('userAuthenticated'));
        $this->assertSame((int) $user->id, (int) session('pending2faUserId'));
    }

    public function testVerify2faLocksOutAfterTooManyWrongAttempts(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-verify-lockout@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::WEB_AUTH_TEST_PASSWORD]));

        for ($i = 0; $i < 5; $i++) {
            $this->withSession()->post('/verify-2fa', $this->withCsrf(['code' => '000000']));
        }

        $result = $this->withSession()->post('/verify-2fa', $this->withCsrf(['code' => '000000']));

        $result->assertStatus(429);
        $this->assertNull(session('pending2faUserId'));
    }

    public function testShowVerify2faRedirectsToLoginWithoutPendingState(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/verify-2fa');

        $result->assertRedirectTo('/login');
    }

    public function testShowVerify2faRedirectsAuthenticatedUserToDashboard(): void
    {
        $user = $this->seedWebUser(['email' => 'already-authed@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/verify-2fa');

        $result->assertRedirectTo('/dashboard');
    }

    public function testVerify2faWithoutCsrfTokenIsRejected(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-nocsrf@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->post('/login', $this->withCsrf(['email' => $user->email, 'password' => self::WEB_AUTH_TEST_PASSWORD]));

        $code   = (new Google2FA())->getCurrentOtp($secret);
        $result = $this->withSession()->post('/verify-2fa', ['code' => $code]);

        $result->assertRedirect();
        $this->assertNull(session('userAuthenticated'));
    }
}
