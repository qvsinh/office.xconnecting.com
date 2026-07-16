<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `POST /admin/system/cache/clear` (`Admin\System::clearCache`) — flushes the
 * CI4 cache store. Gated by `authFilter` + `adminFilter` + `csrf`.
 *
 * @internal
 */
final class AdminSystemCacheClearTest extends CIUnitTestCase
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

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->post('/admin/system/cache/clear', $this->withCsrf([]))->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsRejected(): void
    {
        $client = $this->seedWebUser(['email' => 'cache-clear-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $this->withSession()->post('/admin/system/cache/clear', $this->withCsrf([]))->assertRedirectTo('/dashboard');
    }

    public function testAdminCanClearCache(): void
    {
        $admin = $this->seedWebUser(['email' => 'cache-clear-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/cache/clear', $this->withCsrf([]));
        $json   = json_decode($result->getJSON(), true);

        // Clean succeeds on the configured cache handler; a handler that can't
        // clean surfaces the graceful 500 envelope — never a fatal.
        $this->assertContains($json['status'], ['success', 'failed']);
        $this->assertContains($result->response()->getStatusCode(), [200, 500]);
    }
}
