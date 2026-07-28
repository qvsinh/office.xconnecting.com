<?php

namespace App\Controllers;

use App\Libraries\PasswordHasher;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Self-service account area (profile, password, Google Authenticator 2FA),
 * usable by both client and admin sessions for THEIR OWN account. Every
 * read/mutation is scoped to `session('userWorking')->id` — the owning id is
 * never accepted from request input (mirrors `Client\ApiAccessKeys`) — and
 * the authoritative row is always re-fetched from the DB (never the
 * possibly-stale session copy) before any password/2FA decision.
 *
 * `google2fa_secret` is never included in a JSON response or view payload
 * after enrollment: `setupGoogle2fa()` is the one place it's shown, and only
 * for the request that generated it.
 */
class Account extends BaseController
{
    private const MAX_GOOGLE2FA_VERIFY_ATTEMPTS = 5;

    public function profile(): string
    {
        return view('account/profile', ['user' => $this->profileData($this->workingUser())]);
    }

    public function updateProfile(): ResponseInterface
    {
        $rules = [
            'name'         => 'required|max_length[255]',
            'company'      => 'permit_empty|max_length[255]',
            'phone_number' => 'permit_empty|max_length[20]',
            'language'     => 'permit_empty|max_length[5]',
            'timezone'     => 'permit_empty|max_length[30]',
        ];

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $user = $this->workingUser();

        model(UserModel::class)->update((int) $user->id, [
            'name'         => trim((string) $this->request->getPost('name')),
            'company'      => trim((string) $this->request->getPost('company')),
            'phone_number' => trim((string) $this->request->getPost('phone_number')),
            'language'     => trim((string) $this->request->getPost('language')) ?: $user->language,
            'timezone'     => trim((string) $this->request->getPost('timezone')) ?: $user->timezone,
        ]);

        $this->refreshSessionUser((int) $user->id);

        return $this->jsonSuccess('Profile has been updated.');
    }

    public function changePassword(): ResponseInterface
    {
        $rules = [
            'current_password' => 'required',
            // Shared policy — see App\Validation\PasswordRules.
            'password'         => 'required|strong_password',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id      = (int) $this->workingUser()->id;
        $current = model(UserModel::class)->findUser($id);

        if (! $current || ! $this->verifyPassword((string) $this->request->getPost('current_password'), $current->password)) {
            return $this->jsonFailed('Current password is incorrect.');
        }

        model(UserModel::class)->update($id, [
            'password' => $this->hashPassword((string) $this->request->getPost('password')),
        ]);

        $this->refreshSessionUser($id);

        return $this->jsonSuccess('Password has been changed.');
    }

    public function security(): string
    {
        return view('account/security', ['user' => $this->profileData($this->workingUser())]);
    }

    /**
     * Generates a fresh TOTP secret and returns the QR + manual key + otpauth
     * URL for the authenticator app — shown exactly once, here. The secret is
     * held in session (`pendingGoogle2faSecret`) until `verifyGoogle2fa()`
     * confirms it; nothing is persisted to `users` yet.
     */
    public function setupGoogle2fa(): ResponseInterface
    {
        $user = model(UserModel::class)->findUser((int) $this->workingUser()->id);

        if (! $user) {
            return $this->jsonFailed('User not found.', 404);
        }

        if ($user->two_factor_method !== 'none') {
            return $this->jsonFailed('Two-factor authentication is already enabled. Disable it first to re-enroll.');
        }

        $google2fa = service('google2fa');
        $secret    = $google2fa->generateSecret();
        $otpauth   = $google2fa->otpauthUrl($user->email, $secret);

        session()->set('pendingGoogle2faSecret', $secret);
        session()->remove('google2faVerifyAttempts');

        return $this->jsonSuccess('Scan the QR code with your authenticator app, then enter a code to confirm.', [
            'secret'  => $secret,
            'otpauth' => $otpauth,
            'qr_code' => $google2fa->qrCodeDataUri($otpauth),
        ]);
    }

    /**
     * Verifies the code against the pending secret from `setupGoogle2fa()`;
     * on success persists it and switches the account to `google2fa`.
     */
    public function verifyGoogle2fa(): ResponseInterface
    {
        if (! $this->validate(['code' => 'required|exact_length[6]|numeric'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $secret = (string) session('pendingGoogle2faSecret');
        if ($secret === '') {
            return $this->jsonFailed('Start enrollment again.');
        }

        $attempts = (int) session('google2faVerifyAttempts');
        if ($attempts >= self::MAX_GOOGLE2FA_VERIFY_ATTEMPTS) {
            session()->remove('pendingGoogle2faSecret');
            session()->remove('google2faVerifyAttempts');

            return $this->jsonFailed('Too many attempts. Please start enrollment again.', 429);
        }

        $code = (string) $this->request->getPost('code');

        if (! service('google2fa')->verify($secret, $code)) {
            session()->set('google2faVerifyAttempts', $attempts + 1);

            return $this->jsonFailed('Invalid authentication code.');
        }

        $id = (int) $this->workingUser()->id;
        model(UserModel::class)->enableGoogle2fa($id, $secret);

        session()->remove('pendingGoogle2faSecret');
        session()->remove('google2faVerifyAttempts');
        $this->refreshSessionUser($id);

        return $this->jsonSuccess('Two-factor authentication is now enabled.');
    }

    /**
     * Requires a valid current TOTP code OR the account password before
     * clearing the secret — either proves the requester is the account
     * owner even if their session were hijacked without the second factor.
     */
    public function disable2fa(): ResponseInterface
    {
        $rules = [
            'code'     => 'permit_empty|exact_length[6]|numeric',
            'password' => 'permit_empty',
        ];

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id   = (int) $this->workingUser()->id;
        $user = model(UserModel::class)->findUser($id);

        if (! $user) {
            return $this->jsonFailed('User not found.', 404);
        }

        if ($user->two_factor_method === 'none') {
            return $this->jsonFailed('Two-factor authentication is already disabled.');
        }

        $code     = (string) $this->request->getPost('code');
        $password = (string) $this->request->getPost('password');

        $codeValid     = $code !== '' && $user->google2fa_secret && service('google2fa')->verify($user->google2fa_secret, $code);
        $passwordValid = $password !== '' && $this->verifyPassword($password, $user->password);

        if (! $codeValid && ! $passwordValid) {
            return $this->jsonFailed('Enter your current authentication code or your account password.');
        }

        model(UserModel::class)->disableTwoFactor($id);
        $this->refreshSessionUser($id);

        return $this->jsonSuccess('Two-factor authentication has been disabled.');
    }

    /**
     * The session identity this self-service area edits: `userWorking`, the
     * same convention every other client-scoped controller uses (so an
     * admin's "act as" also carries into their own-account pages).
     */
    private function workingUser(): object
    {
        return session('userWorking');
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(object $user): array
    {
        return [
            'id'                => (int) $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'company'           => $user->company ?? null,
            'phone_number'      => $user->phone_number ?? null,
            'language'          => $user->language,
            'timezone'          => $user->timezone,
            'two_factor_method' => $user->two_factor_method,
        ];
    }

    /**
     * Reloads the row after a mutation and pushes it back into
     * `userWorking` (and `userAuthenticated`, when it's the same identity —
     * never when impersonating) so the rendered UI reflects the change
     * immediately, matching `Auth::login()`'s session shape.
     */
    private function refreshSessionUser(int $id): void
    {
        $refreshed = model(UserModel::class)->findUser($id);
        if (! $refreshed) {
            return;
        }

        session()->set('userWorking', $refreshed);

        $authUser = session('userAuthenticated');
        if ($authUser !== null && (int) $authUser->id === $id) {
            session()->set('userAuthenticated', $refreshed);
        }
    }

    private function verifyPassword(string $plain, string $hash): bool
    {
        return (new PasswordHasher())->verify($plain, $hash);
    }

    private function hashPassword(string $plain): string
    {
        return (new PasswordHasher())->hash($plain);
    }
}
