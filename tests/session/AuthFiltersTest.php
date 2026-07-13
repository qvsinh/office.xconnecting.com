<?php

use App\Filters\AdminFilter;
use App\Filters\AuthFilter;
use App\Filters\GuestFilter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FilterTestTrait;

/**
 * `AuthFilter` / `AdminFilter` / `GuestFilter`, exercised directly via
 * `FilterTestTrait` (no route/CSRF involved — see AuthLoginTest and
 * `php spark routes` for the route-to-filter wiring itself).
 *
 * @internal
 */
final class AuthFiltersTest extends CIUnitTestCase
{
    use FilterTestTrait;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testAuthFilterRedirectsGuestToLogin(): void
    {
        $result = ($this->getFilterCaller(new AuthFilter(), 'before'))();

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('login'), $result->getHeaderLine('Location'));
    }

    public function testAuthFilterAllowsAuthenticatedUser(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'client']);

        $result = ($this->getFilterCaller(new AuthFilter(), 'before'))();

        $this->assertNull($result);
    }

    public function testAdminFilterRedirectsGuestToLogin(): void
    {
        $result = ($this->getFilterCaller(new AdminFilter(), 'before'))();

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('login'), $result->getHeaderLine('Location'));
    }

    public function testAdminFilterRedirectsNonAdminToDashboard(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'client']);

        $result = ($this->getFilterCaller(new AdminFilter(), 'before'))();

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('dashboard'), $result->getHeaderLine('Location'));
    }

    public function testAdminFilterAllowsGlobalZoneUser(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'global']);

        $result = ($this->getFilterCaller(new AdminFilter(), 'before'))();

        $this->assertNull($result);
    }

    public function testGuestFilterAllowsUnauthenticatedRequest(): void
    {
        $result = ($this->getFilterCaller(new GuestFilter(), 'before'))();

        $this->assertNull($result);
    }

    public function testGuestFilterRedirectsAuthenticatedAdminToAdminDashboard(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'global']);

        $result = ($this->getFilterCaller(new GuestFilter(), 'before'))();

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('admin/dashboard'), $result->getHeaderLine('Location'));
    }

    public function testGuestFilterRedirectsAuthenticatedClientToDashboard(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'client']);

        $result = ($this->getFilterCaller(new GuestFilter(), 'before'))();

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('dashboard'), $result->getHeaderLine('Location'));
    }
}
