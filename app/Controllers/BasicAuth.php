<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Form-based site gate, ported from the legacy `BasicAuth::login`. Sits in
 * front of the app login (gate -> app login -> dashboard) when
 * `app.basic.auth.username` is set in `.env`; see `BasicAuthFilter`.
 */
class BasicAuth extends BaseController
{
    protected $helpers = ['auth'];

    public function index(): string
    {
        return view('auth/basic_auth');
    }

    public function authenticate(): ResponseInterface
    {
        $rules = [
            'username' => 'required',
            'password' => 'required',
        ];

        if (! $this->validate($rules)) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'    => 'failed',
                'message'   => implode(' ', $this->validator->getErrors()),
                'csrf_hash' => csrf_hash(),
            ]);
        }

        $username = (string) $this->request->getPost('username');
        $password = (string) $this->request->getPost('password');

        $usernameMatches = hash_equals((string) getenv('app.basic.auth.username'), $username);
        $passwordMatches = hash_equals((string) getenv('app.basic.auth.password'), $password);

        if (! $usernameMatches || ! $passwordMatches) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'    => 'failed',
                'message'   => 'Username or password is not correct',
                'csrf_hash' => csrf_hash(),
            ]);
        }

        session()->set('basicAuthAuthenticated', 1);

        return $this->response->setJSON([
            'status'       => 'success',
            'redirect_url' => $this->redirectUrl(),
            'csrf_hash'    => csrf_hash(),
        ]);
    }

    private function redirectUrl(): string
    {
        if (is_admin()) {
            return '/admin/dashboard';
        }

        if (is_authenticated()) {
            return '/dashboard';
        }

        return '/login';
    }
}
