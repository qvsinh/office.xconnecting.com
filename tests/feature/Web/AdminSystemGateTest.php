<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin gating for `/admin/system` and `/admin/system/migrations`
 * (`Admin\System`), exercised through real routing + filters
 * (`authFilter`/`adminFilter`) — a guest or non-admin session is redirected
 * by the filter before the controller ever runs, so this is testable
 * independently of the `admin/system/info` / `admin/system/migrations`
 * views (frontend-coder, not yet built). Data-shape/no-secret-leak
 * coverage for the controller logic lives in AdminSystemInfoTest
 * (database) and AdminSystemMigrationsTest (unit).
 *
 * @internal
 */
final class AdminSystemGateTest extends CIUnitTestCase
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

    public function testGuestIsRedirectedToLoginFromSystemInfoAndMigrations(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->get('/admin/system')->assertRedirectTo('/login');
        $this->get('/admin/system/migrations')->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsRejectedBySystemInfoAndMigrationsGate(): void
    {
        $client = $this->seedWebUser(['email' => 'gate-system-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $this->withSession()->get('/admin/system')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/system/migrations')->assertRedirectTo('/dashboard');
    }
}
