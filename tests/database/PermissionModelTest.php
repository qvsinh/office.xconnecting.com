<?php

use App\Models\PermissionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PermissionModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCreateAndFindPermission(): void
    {
        $model = new PermissionModel();
        $id    = $model->insert(['name' => 'reports.view', 'label' => 'View reports']);

        $permission = $model->findPermission((int) $id);

        $this->assertNotNull($permission);
        $this->assertSame('reports.view', $permission->name);
        $this->assertSame('View reports', $permission->label);
    }

    public function testFindByNameReturnsNullWhenMissing(): void
    {
        $model = new PermissionModel();

        $this->assertNull($model->findByName('nope'));
    }

    public function testFindByNameReturnsTheSeededPermission(): void
    {
        $model = new PermissionModel();

        $permission = $model->findByName('users.manage');

        $this->assertNotNull($permission);
        $this->assertSame('users.manage', $permission->name);
    }

    public function testSearchAdminFiltersByKeywordAndOrdersByIdOnTies(): void
    {
        $model = new PermissionModel();
        $model->insert(['name' => 'zzz.perm', 'label' => 'ZZZ perm']);
        $model->insert(['name' => 'aaa.perm', 'label' => 'AAA perm']);

        $rows = $model->searchAdmin(['keyword' => 'perm'], 'name', 'asc');

        $this->assertCount(2, $rows);
        $this->assertSame('aaa.perm', $rows[0]->name);
        $this->assertSame('zzz.perm', $rows[1]->name);
    }

    public function testCountAdminMatchesSearchAdminFilter(): void
    {
        $model = new PermissionModel();
        $model->insert(['name' => 'billing.manage', 'label' => 'Manage billing']);

        $this->assertSame(1, $model->countAdmin(['keyword' => 'billing']));
    }
}
