<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `POST /admin/system/migrations/run` + `/rollback` (`Admin\System`) — the
 * admin-triggered migrate/rollback actions. Gated by `authFilter` +
 * `adminFilter` + `csrf` like every other admin mutation. The rollback
 * happy-path is intentionally NOT invoked here: `regress(-1)` would drop the
 * whole (single) test batch mid-suite; its auth gating is what matters and is
 * covered below.
 *
 * @internal
 */
final class AdminSystemMigrationsActionTest extends CIUnitTestCase
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

    public function testAdminCanRunMigrations(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-mig-run@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/migrations/run', $this->withCsrf([]));

        $result->assertStatus(200);
        $this->assertSame('success', json_decode($result->getJSON(), true)['status']);
    }

    public function testRunGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->post('/admin/system/migrations/run', $this->withCsrf([]))->assertRedirectTo('/login');
    }

    public function testRunNonAdminClientIsRejected(): void
    {
        $client = $this->seedWebUser(['email' => 'mig-run-client@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $this->withSession()->post('/admin/system/migrations/run', $this->withCsrf([]))->assertRedirectTo('/dashboard');
    }

    public function testRollbackGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->post('/admin/system/migrations/rollback', $this->withCsrf([]))->assertRedirectTo('/login');
    }

    public function testRollbackNonAdminClientIsRejected(): void
    {
        $client = $this->seedWebUser(['email' => 'mig-rb-client@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $this->withSession()->post('/admin/system/migrations/rollback', $this->withCsrf([]))->assertRedirectTo('/dashboard');
    }
}
