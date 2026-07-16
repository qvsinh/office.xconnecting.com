<?php

use App\Models\PermissionModel;
use App\Models\RoleModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class RoleModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCreateAndFindRole(): void
    {
        $model = new RoleModel();
        $id    = $model->insert(['name' => 'editor', 'label' => 'Editor']);

        $role = $model->findRole((int) $id);

        $this->assertNotNull($role);
        $this->assertSame('editor', $role->name);
        $this->assertSame('Editor', $role->label);
    }

    public function testFindByNameReturnsNullWhenMissing(): void
    {
        $model = new RoleModel();

        $this->assertNull($model->findByName('nope'));
    }

    public function testSearchAdminFiltersByKeywordAndOrdersByIdOnTies(): void
    {
        $model = new RoleModel();
        $model->insert(['name' => 'zzz-role', 'label' => 'ZZZ role']);
        $model->insert(['name' => 'aaa-role', 'label' => 'AAA role']);

        $rows = $model->searchAdmin(['keyword' => 'role'], 'name', 'asc');

        // The two default seeded roles (admin/user) don't match "role", so
        // only these two show up, alphabetically ordered.
        $this->assertCount(2, $rows);
        $this->assertSame('aaa-role', $rows[0]->name);
        $this->assertSame('zzz-role', $rows[1]->name);
    }

    public function testCountAdminMatchesSearchAdminFilter(): void
    {
        $model = new RoleModel();
        $model->insert(['name' => 'support', 'label' => 'Support']);

        $this->assertSame(1, $model->countAdmin(['keyword' => 'support']));
    }

    public function testPermissionsForReturnsGrantedPermissionNames(): void
    {
        $roleModel       = new RoleModel();
        $permissionModel = new PermissionModel();

        $roleId        = (int) $roleModel->insert(['name' => 'auditor', 'label' => 'Auditor']);
        $permissionAId = (int) $permissionModel->insert(['name' => 'reports.view', 'label' => 'View reports']);
        $permissionBId = (int) $permissionModel->insert(['name' => 'reports.export', 'label' => 'Export reports']);

        $roleModel->syncPermissions($roleId, [$permissionAId, $permissionBId]);

        $names = $roleModel->permissionsFor($roleId);

        $this->assertCount(2, $names);
        $this->assertContains('reports.view', $names);
        $this->assertContains('reports.export', $names);
    }

    public function testPermissionsForReturnsEmptyListForARoleWithNoGrants(): void
    {
        $roleModel = new RoleModel();
        $roleId    = (int) $roleModel->insert(['name' => 'no-grants', 'label' => 'No Grants']);

        $this->assertSame([], $roleModel->permissionsFor($roleId));
    }

    public function testSyncPermissionsReplacesThePreviousGrantSetWholesale(): void
    {
        $roleModel       = new RoleModel();
        $permissionModel = new PermissionModel();

        $roleId  = (int) $roleModel->insert(['name' => 'rotating', 'label' => 'Rotating']);
        $firstId = (int) $permissionModel->insert(['name' => 'first.perm', 'label' => 'First']);
        $secondId = (int) $permissionModel->insert(['name' => 'second.perm', 'label' => 'Second']);

        $roleModel->syncPermissions($roleId, [$firstId]);
        $this->assertSame(['first.perm'], $roleModel->permissionsFor($roleId));

        $roleModel->syncPermissions($roleId, [$secondId]);
        $this->assertSame(['second.perm'], $roleModel->permissionsFor($roleId));
    }

    public function testSyncPermissionsWithAnEmptyListClearsAllGrants(): void
    {
        $roleModel       = new RoleModel();
        $permissionModel = new PermissionModel();

        $roleId       = (int) $roleModel->insert(['name' => 'clearable', 'label' => 'Clearable']);
        $permissionId = (int) $permissionModel->insert(['name' => 'clear.me', 'label' => 'Clear me']);

        $roleModel->syncPermissions($roleId, [$permissionId]);
        $roleModel->syncPermissions($roleId, []);

        $this->assertSame([], $roleModel->permissionsFor($roleId));
    }
}
