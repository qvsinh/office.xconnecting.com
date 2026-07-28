<?php

namespace App\Controllers;

use App\Libraries\AccessLogger;
use App\Libraries\PasswordHasher;
use App\Models\AccessLogModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Web password login. Ported from
 * ../proxy.services.vn/app/Controllers/Auth.php, password-only:
 * - No `RUNNING_MODE=dev` password-skip backdoor (intentionally not ported).
 * - `two_factor_method !== 'none'` accounts stop short of an authenticated
 *   session after a correct password: only `pending2faUserId` (the id, never
 *   the user object) is set, and `verify2fa()` is the one place that grants
 *   `userAuthenticated`/`userWorking` for those accounts. See §4 below.
 * - No remember-me, signup, or password-reset (later cards).
 */
class Auth extends BaseController
{
    private const MAX_VERIFY_2FA_ATTEMPTS = 5;

    protected $helpers = ['auth'];

    public function showLogin(): ResponseInterface|string
    {
        if (is_authenticated()) {
            return redirect()->to($this->dashboardUrl());
        }

        return view('auth/login');
    }

    public function login(): ResponseInterface
    {
        $rules = [
            'email'    => 'required|valid_email|max_length[255]',
            'password' => 'required|min_length[5]',
        ];

        if (! $this->validate($rules)) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'    => 'failed',
                'message'   => implode(' ', $this->validator->getErrors()),
                'csrf_hash' => csrf_hash(),
            ]);
        }

        $email    = (string) $this->request->getPost('email');
        $password = (string) $this->request->getPost('password');

        $userModel = model(UserModel::class);
        $user      = $userModel->findActiveByEmail($email);
        $hasher    = new PasswordHasher();

        if (! $user || ! $hasher->verify($password, $user->password) || (int) $user->status !== 1) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'    => 'failed',
                'message'   => 'Invalid or inactive account.',
                'csrf_hash' => csrf_hash(),
            ]);
        }

        if ($user->two_factor_method !== 'none') {
            return $this->beginPending2fa($user);
        }

        // The one moment the plaintext is in hand, so the one moment a stale hash
        // can be upgraded: pre-migration MD5 rows, or rows written before
        // `auth.password_cost` was raised. Silent and per-account — no forced
        // reset, and a failure here must not block a valid login.
        if ($hasher->needsRehash($user->password)) {
            try {
                $userModel->update($user->id, ['password' => $hasher->hash($password)]);
            } catch (Throwable $e) {
                log_message('error', 'Could not upgrade the password hash for user #{id}: {msg}', [
                    'id'  => $user->id,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        $userModel->stampLogin((int) $user->id);
        // Logged before the session rotates, so the row belongs to the login
        // event itself rather than to whatever the new session does next.
        (new AccessLogger())->record(AccessLogModel::EVENT_LOGIN, (int) $user->id, $this->request);

        // Rotate the session ID on privilege change to prevent session fixation.
        session()->regenerate(true);

        session()->set('userAuthenticated', $user);
        session()->set('userWorking', $user);
        session()->set('language', $user->language);

        return $this->response->setJSON([
            'status'       => 'success',
            'redirect_url' => $this->dashboardUrl(),
            'csrf_hash'    => csrf_hash(),
        ]);
    }

    public function logout(): ResponseInterface
    {
        session()->destroy();

        return redirect()->to('/login');
    }

    /**
     * The 2FA challenge page for a password-correct, second-factor-pending
     * login. Guarded in-controller rather than by a new filter: a pending
     * user isn't authenticated, so `guestFilter` would let them straight
     * through, and `authFilter` would just bounce them to `/login` — neither
     * filter can tell "pending" apart from "guest".
     */
    public function showVerify2fa(): ResponseInterface|string
    {
        if (is_authenticated()) {
            return redirect()->to($this->dashboardUrl());
        }

        if (! session('pending2faUserId')) {
            return redirect()->to('/login');
        }

        return view('auth/verify_2fa');
    }

    public function verify2fa(): ResponseInterface
    {
        if (is_authenticated()) {
            return redirect()->to($this->dashboardUrl());
        }

        $pendingId = (int) session('pending2faUserId');
        if ($pendingId <= 0) {
            return redirect()->to('/login');
        }

        if (! $this->validate(['code' => 'required|exact_length[6]|numeric'])) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'    => 'failed',
                'message'   => implode(' ', $this->validator->getErrors()),
                'csrf_hash' => csrf_hash(),
            ]);
        }

        // Brute-force cap: a 6-digit TOTP code has only 1,000,000 combinations.
        $attempts = (int) session('verify2faAttempts');
        if ($attempts >= self::MAX_VERIFY_2FA_ATTEMPTS) {
            $this->clearPending2fa();

            return $this->response->setStatusCode(429)->setJSON([
                'status'    => 'failed',
                'message'   => 'Too many attempts. Please log in again.',
                'csrf_hash' => csrf_hash(),
            ]);
        }

        $userModel = model(UserModel::class);
        $user      = $userModel->findUser($pendingId);

        if (! $user || (int) $user->status !== 1 || $user->two_factor_method === 'none' || ! $user->google2fa_secret) {
            $this->clearPending2fa();

            return redirect()->to('/login');
        }

        $code = (string) $this->request->getPost('code');

        if (! service('google2fa')->verify($user->google2fa_secret, $code)) {
            session()->set('verify2faAttempts', $attempts + 1);

            return $this->response->setStatusCode(401)->setJSON([
                'status'    => 'failed',
                'message'   => 'Invalid authentication code.',
                'csrf_hash' => csrf_hash(),
            ]);
        }

        $userModel->stampLogin($pendingId);
        // Only a *completed* second factor counts as a login — a correct
        // password that never passes 2FA is not one.
        (new AccessLogger())->record(AccessLogModel::EVENT_LOGIN, $pendingId, $this->request);

        // Rotate the session ID on privilege change to prevent session fixation.
        session()->regenerate(true);

        session()->set('userAuthenticated', $user);
        session()->set('userWorking', $user);
        session()->set('language', $user->language);
        $this->clearPending2fa();

        return $this->response->setJSON([
            'status'       => 'success',
            'redirect_url' => $this->dashboardUrl(),
            'csrf_hash'    => csrf_hash(),
        ]);
    }

    /**
     * Sets the pending-2FA marker for a password-correct login. Stores the
     * id only — never the user object — so `verify2fa()` always re-fetches
     * the authoritative row rather than trusting anything cached here.
     */
    private function beginPending2fa(object $user): ResponseInterface
    {
        session()->set('pending2faUserId', (int) $user->id);
        session()->remove('verify2faAttempts');

        return $this->response->setJSON([
            'status'       => 'success',
            'requires_2fa' => true,
            'redirect_url' => '/verify-2fa',
            'csrf_hash'    => csrf_hash(),
        ]);
    }

    private function clearPending2fa(): void
    {
        session()->remove('pending2faUserId');
        session()->remove('verify2faAttempts');
    }

    private function dashboardUrl(): string
    {
        return is_admin() ? '/admin/dashboard' : '/dashboard';
    }
}
