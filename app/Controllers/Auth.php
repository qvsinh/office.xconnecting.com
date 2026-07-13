<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web password login. Ported from
 * ../proxy.services.vn/app/Controllers/Auth.php, password-only:
 * - No `RUNNING_MODE=dev` password-skip backdoor (intentionally not ported).
 * - No 2FA branch yet — accounts with `two_factor_method` set still log in
 *   with password alone until the dedicated 2FA card lands (kanban plan §7).
 * - No remember-me, signup, or password-reset (later cards).
 */
class Auth extends BaseController
{
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
                'status'  => 'failed',
                'message' => implode(' ', $this->validator->getErrors()),
            ]);
        }

        $email    = (string) $this->request->getPost('email');
        $password = (string) $this->request->getPost('password');

        $userModel    = model(UserModel::class);
        $user         = $userModel->findActiveByEmail($email);
        $authConfig   = config('Auth');
        $passwordHash = hash($authConfig->password_algorithm, $password . $authConfig->password_salt);

        if (! $user || ! hash_equals($user->password, $passwordHash) || (int) $user->status !== 1) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'  => 'failed',
                'message' => 'Invalid or inactive account.',
            ]);
        }

        $userModel->stampLogin((int) $user->id);

        session()->set('userAuthenticated', $user);
        session()->set('userWorking', $user);
        session()->set('language', $user->language);

        return $this->response->setJSON([
            'status'       => 'success',
            'redirect_url' => $this->dashboardUrl(),
        ]);
    }

    public function logout(): ResponseInterface
    {
        session()->destroy();

        return redirect()->to('/login');
    }

    private function dashboardUrl(): string
    {
        return is_admin() ? '/admin/dashboard' : '/dashboard';
    }
}
