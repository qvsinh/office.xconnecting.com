<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Web\WebAuthFixture;

/**
 * Self-service account area (`Account`): profile update, password change,
 * and Google Authenticator (TOTP) enrollment/disable — every mutation is
 * scoped to the caller's own row (`session('userWorking')`), never a
 * request-supplied id, and `google2fa_secret` never leaves an enrollment
 * response.
 *
 * `GET /account` and `GET /account/security` render views built by
 * frontend-coder next (kanban plan §Phase 2) and are only exercised here for
 * the guest-redirect gate, which fires before any view renders.
 *
 * @internal
 */
final class AccountTest extends CIUnitTestCase
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

    // --- Gating ---

    public function testGuestIsRedirectedToLoginFromProfilePage(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/account');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromSecurityPage(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/account/security');

        $result->assertRedirectTo('/login');
    }

    public function testMutationWithoutCsrfTokenIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'nocsrf-account@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/profile', ['name' => 'x']);

        $result->assertRedirect();
    }

    // --- Profile ---

    public function testUpdateProfileWritesOnlyTheCallersOwnRowIgnoringAForeignId(): void
    {
        $userA = $this->seedWebUser(['email' => 'profile-a@example.com', 'name' => 'Original A']);
        $userB = $this->seedWebUser(['email' => 'profile-b@example.com', 'name' => 'Original B']);
        $this->loginAs($userA);

        $result = $this->withSession()->post('/account/profile', $this->withCsrf([
            'id'           => $userB->id, // IDOR attempt: controller must never read this
            'name'         => 'Updated A',
            'company'      => 'Acme',
            'phone_number' => '0123456789',
            'language'     => 'en',
            'timezone'     => 'Asia/Tokyo',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);

        $model = model(UserModel::class);
        $this->assertSame('Updated A', $model->find($userA->id)->name);
        $this->assertSame('Original B', $model->find($userB->id)->name); // untouched
    }

    public function testUpdateProfileRejectsEmptyName(): void
    {
        $user = $this->seedWebUser(['email' => 'profile-empty@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/profile', $this->withCsrf(['name' => '']));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testUpdateProfileRefreshesTheSessionUser(): void
    {
        $user = $this->seedWebUser(['email' => 'profile-refresh@example.com', 'name' => 'Old Name']);
        $this->loginAs($user);

        $this->withSession()->post('/account/profile', $this->withCsrf(['name' => 'New Name']));

        $this->assertSame('New Name', session('userWorking')->name);
        $this->assertSame('New Name', session('userAuthenticated')->name);
    }

    // --- Password ---

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $user = $this->seedWebUser(['email' => 'pw-wrong@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/change-password', $this->withCsrf([
            'current_password' => 'not-the-password',
            'password'         => 'NewPassw0rd!',
            'password_confirm' => 'NewPassw0rd!',
        ]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testChangePasswordRejectsMismatchedConfirmation(): void
    {
        $user = $this->seedWebUser(['email' => 'pw-mismatch@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/change-password', $this->withCsrf([
            'current_password' => self::WEB_AUTH_TEST_PASSWORD,
            'password'         => 'NewPassw0rd!',
            'password_confirm' => 'SomethingElse!',
        ]));

        $result->assertStatus(400);
    }

    /**
     * The shared policy (App\Validation\PasswordRules) has to actually reach
     * this endpoint — a rule that is defined but not wired up protects nothing.
     *
     * @dataProvider weakPasswordProvider
     */
    public function testChangePasswordRejectsAPasswordThatFailsThePolicy(string $weak, string $expectedHint): void
    {
        $user = $this->seedWebUser(['email' => 'pw-weak-' . md5($weak) . '@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/change-password', $this->withCsrf([
            'current_password' => self::WEB_AUTH_TEST_PASSWORD,
            'password'         => $weak,
            'password_confirm' => $weak,
        ]));

        $result->assertStatus(400);
        $this->assertStringContainsString($expectedHint, json_decode($result->getJSON(), true)['message']);

        // ...and the stored hash is untouched.
        $authConfig = config('Auth');
        $this->assertSame(
            hash($authConfig->password_algorithm, self::WEB_AUTH_TEST_PASSWORD . $authConfig->password_salt),
            model(UserModel::class)->find($user->id)->password,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function weakPasswordProvider(): array
    {
        return [
            'too short'    => ['Ab1!def', 'at least 8 characters'],
            'no special'   => ['Str0ngpass1', 'special character'],
            'no uppercase' => ['str0ng!pass', 'uppercase'],
            'no number'    => ['Strong!pass', 'number'],
            'no lowercase' => ['STR0NG!PASS', 'lowercase'],
            'letters only' => ['abcdefghij', 'uppercase'],
        ];
    }

    public function testChangePasswordSuccessRehashesPasswordAndNeverEchoesIt(): void
    {
        $user = $this->seedWebUser(['email' => 'pw-success@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/change-password', $this->withCsrf([
            'current_password' => self::WEB_AUTH_TEST_PASSWORD,
            'password'         => 'NewPassw0rd!',
            'password_confirm' => 'NewPassw0rd!',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayNotHasKey('password', $json);
        $result->assertDontSee('NewPassw0rd!');

        $authConfig   = config('Auth');
        $expectedHash = hash($authConfig->password_algorithm, 'NewPassw0rd!' . $authConfig->password_salt);
        $this->assertSame($expectedHash, model(UserModel::class)->find($user->id)->password);
    }

    // --- Google 2FA enrollment ---

    public function testSetupGoogle2faReturnsSecretQrAndOtpauthOnceAndDoesNotPersistYet(): void
    {
        $user = $this->seedWebUser(['email' => '2fa-setup@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/2fa/setup', $this->withCsrf([]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertNotEmpty($json['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $json['otpauth']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $json['qr_code']);
        $this->assertSame('none', model(UserModel::class)->find($user->id)->two_factor_method);
    }

    public function testSetupGoogle2faRejectsWhenAlreadyEnabled(): void
    {
        $engine = new Google2FA();
        $secret = $engine->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-already@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);
        $this->loginWithTotp($user, $secret);

        $result = $this->withSession()->post('/account/2fa/setup', $this->withCsrf([]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testVerifyGoogle2faWithCorrectCodeEnablesTwoFactorAndNeverReturnsTheSecret(): void
    {
        $user = $this->seedWebUser(['email' => '2fa-verify-ok@example.com']);
        $this->loginAs($user);

        $setup  = $this->withSession()->post('/account/2fa/setup', $this->withCsrf([]));
        $secret = json_decode($setup->getJSON(), true)['secret'];
        $code   = (new Google2FA())->getCurrentOtp($secret);

        $result = $this->withSession()->post('/account/2fa/verify', $this->withCsrf(['code' => $code]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayNotHasKey('secret', $json);
        $this->assertArrayNotHasKey('google2fa_secret', $json);

        $stored = model(UserModel::class)->find($user->id);
        $this->assertSame('google2fa', $stored->two_factor_method);
        $this->assertSame($secret, $stored->google2fa_secret);
    }

    public function testVerifyGoogle2faWithWrongCodeLeavesTwoFactorDisabled(): void
    {
        $user = $this->seedWebUser(['email' => '2fa-verify-wrong@example.com']);
        $this->loginAs($user);

        $this->withSession()->post('/account/2fa/setup', $this->withCsrf([]));

        $result = $this->withSession()->post('/account/2fa/verify', $this->withCsrf(['code' => '000000']));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
        $this->assertSame('none', model(UserModel::class)->find($user->id)->two_factor_method);
    }

    public function testVerifyGoogle2faWithoutPriorSetupIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => '2fa-verify-nopending@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/2fa/verify', $this->withCsrf(['code' => '123456']));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    // --- Google 2FA disable ---

    public function testDisable2faWithCorrectCodeDisablesAndClearsSecret(): void
    {
        $engine = new Google2FA();
        $secret = $engine->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-disable-code@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);
        $this->loginWithTotp($user, $secret);

        $code   = $engine->getCurrentOtp($secret);
        $result = $this->withSession()->post('/account/2fa/disable', $this->withCsrf(['code' => $code]));

        $result->assertStatus(200);
        $stored = model(UserModel::class)->find($user->id);
        $this->assertSame('none', $stored->two_factor_method);
        $this->assertNull($stored->google2fa_secret);
    }

    public function testDisable2faWithCorrectPasswordDisables(): void
    {
        $engine = new Google2FA();
        $secret = $engine->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-disable-pw@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);
        $this->loginWithTotp($user, $secret);

        $result = $this->withSession()->post('/account/2fa/disable', $this->withCsrf(['password' => self::WEB_AUTH_TEST_PASSWORD]));

        $result->assertStatus(200);
        $this->assertSame('none', model(UserModel::class)->find($user->id)->two_factor_method);
    }

    public function testDisable2faWithWrongCodeAndWrongPasswordIsRejected(): void
    {
        $engine = new Google2FA();
        $secret = $engine->generateSecretKey();
        $user   = $this->seedWebUser(['email' => '2fa-disable-wrong@example.com', 'two_factor_method' => 'google2fa', 'google2fa_secret' => $secret]);
        $this->loginWithTotp($user, $secret);

        $result = $this->withSession()->post('/account/2fa/disable', $this->withCsrf(['code' => '000000', 'password' => 'not-the-password']));

        $result->assertStatus(400);
        $this->assertSame('google2fa', model(UserModel::class)->find($user->id)->two_factor_method);
    }

    public function testDisable2faWhenAlreadyDisabledIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => '2fa-disable-none@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/2fa/disable', $this->withCsrf(['password' => self::WEB_AUTH_TEST_PASSWORD]));

        $result->assertStatus(400);
    }

    /**
     * Logs a 2FA-enabled user in through the full login -> pending -> verify
     * flow (loginAs() alone can't establish an authenticated session for
     * these accounts since Auth::login() now stops at `pending2faUserId`).
     */
    private function loginWithTotp(object $user, string $secret): void
    {
        $this->loginAs($user);
        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->withSession()->post('/verify-2fa', $this->withCsrf(['code' => $code]));
    }
}
