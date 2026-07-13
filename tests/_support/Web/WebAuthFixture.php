<?php

namespace Tests\Support\Web;

use App\Models\UserModel;
use CodeIgniter\Config\Services;

/**
 * Seeds a web-login user and fabricates a valid double-submit CSRF pair,
 * for Feature-level tests that need a real authenticated session (admin or
 * client zone) through the actual `/login` route. See AuthWebFlowTest for
 * the original pattern this was lifted from.
 */
trait WebAuthFixture
{
    private const WEB_AUTH_TEST_PASSWORD = 'Sup3rSecret!';

    /**
     * @param array<string, mixed> $overrides
     */
    protected function seedWebUser(array $overrides = []): object
    {
        $authConfig = config('Auth');
        $userModel  = model(UserModel::class);

        $id = $userModel->insert(array_merge([
            'name'              => 'Test User',
            'email'             => 'user@example.com',
            'password'          => hash($authConfig->password_algorithm, self::WEB_AUTH_TEST_PASSWORD . $authConfig->password_salt),
            'zone'              => 'client',
            'env'               => 'TESTING',
            'status'            => 1,
            'two_factor_method' => 'none',
            'language'          => 'en',
        ], $overrides));

        return $userModel->find($id);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function withCsrf(array $data): array
    {
        $security = config('Security');
        $hash     = bin2hex(random_bytes(16));

        service('superglobals')->setCookie($security->cookieName, $hash);
        Services::resetSingle('security');

        $data[$security->tokenName] = $hash;

        return $data;
    }

    /**
     * Logs the given user in through the real `/login` route (routing +
     * filters + CSRF all exercised), returning the resulting session.
     */
    protected function loginAs(object $user): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->post('/login', $this->withCsrf([
            'email'    => $user->email,
            'password' => self::WEB_AUTH_TEST_PASSWORD,
        ]));
    }
}
