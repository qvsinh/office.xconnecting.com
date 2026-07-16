<?php

use App\Filters\PermissionFilter;
use App\Models\PermissionModel;
use App\Models\RoleModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FilterTestTrait;

/**
 * `PermissionFilter` (`filter => 'permission:<name>'`), exercised directly via
 * `FilterTestTrait` — mirrors `AuthFiltersTest`. Not wired to any route yet
 * (opt-in), so this is the only place it's checked end to end.
 *
 * @internal
 */
final class PermissionFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FilterTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
        user_can_reset_cache();
    }

    public function testRedirectsGuestToLogin(): void
    {
        $result = ($this->getFilterCaller(new PermissionFilter(), 'before'))(['users.manage']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('login'), $result->getHeaderLine('Location'));
    }

    public function testAdminBypassesThePermissionCheck(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'global']);

        $result = ($this->getFilterCaller(new PermissionFilter(), 'before'))(['anything.not.granted']);

        $this->assertNull($result);
    }

    public function testRedirectsToDashboardWhenTheRoleLacksThePermission(): void
    {
        $userRole = model(RoleModel::class)->findByName('user'); // seeded, 0 permissions
        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $userRole->id]);

        $result = ($this->getFilterCaller(new PermissionFilter(), 'before'))(['users.manage']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(site_url('dashboard'), $result->getHeaderLine('Location'));
    }

    public function testAllowsWhenTheRoleGrantsThePermission(): void
    {
        $roleModel = model(RoleModel::class);
        $userRole  = $roleModel->findByName('user');
        $permissionId = model(PermissionModel::class)->findByName('users.manage')->id;
        $roleModel->syncPermissions((int) $userRole->id, [(int) $permissionId]);

        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $userRole->id]);

        $result = ($this->getFilterCaller(new PermissionFilter(), 'before'))(['users.manage']);

        $this->assertNull($result);
    }

    public function testReturnsJsonForbiddenForAjaxRequests(): void
    {
        $userRole = model(RoleModel::class)->findByName('user'); // 0 permissions
        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $userRole->id]);
        $this->request->setHeader('X-Requested-With', 'XMLHttpRequest');

        $result = ($this->getFilterCaller(new PermissionFilter(), 'before'))(['users.manage']);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }
}
