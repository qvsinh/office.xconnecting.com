<?php

use App\Models\PermissionModel;
use App\Models\RoleModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `user_can()` (`app/Helpers/auth_helper.php`) against real `roles` /
 * `permissions` / `role_permissions` rows — the paths that don't short-circuit
 * before touching the DB are covered in `tests/unit/AuthHelperTest.php`.
 *
 * @internal
 */
final class UserCanHelperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        helper('auth');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
        user_can_reset_cache();
    }

    public function testUserCanIsTrueWhenTheWorkingUsersRoleGrantsThePermission(): void
    {
        $roleId       = $this->createRole('editor', ['content.manage']);
        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $roleId]);

        $this->assertTrue(user_can('content.manage'));
        $this->assertFalse(user_can('system.manage')); // granted a different permission only
    }

    public function testUserCanIsFalseWhenTheRoleGrantsNothing(): void
    {
        $roleId = $this->createRole('bystander', []);
        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $roleId]);

        $this->assertFalse(user_can('content.manage'));
    }

    public function testPerRequestCacheDoesNotLeakBetweenUsersWithDifferentRoles(): void
    {
        $grantedRoleId = $this->createRole('granted-role', ['content.manage']);
        $bareRoleId    = $this->createRole('bare-role', []);

        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $grantedRoleId]);
        $this->assertTrue(user_can('content.manage'));

        // Switch identity mid "request" (e.g. an act-as-style flow) — the
        // cache is keyed by role_id, so the second role must not inherit the
        // first role's cached grant.
        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $bareRoleId]);
        $this->assertFalse(user_can('content.manage'));

        // Switching back still reflects the first role correctly too.
        session()->set('userAuthenticated', (object) ['zone' => 'client', 'role_id' => $grantedRoleId]);
        $this->assertTrue(user_can('content.manage'));
    }

    /**
     * @param list<string> $permissionNames
     */
    private function createRole(string $name, array $permissionNames): int
    {
        $roleModel       = model(RoleModel::class);
        $permissionModel = model(PermissionModel::class);

        $roleId = (int) $roleModel->insert(['name' => $name, 'label' => $name]);

        $permissionIds = array_map(
            static fn (string $permissionName): int => (int) $permissionModel->findByName($permissionName)->id,
            $permissionNames,
        );
        $roleModel->syncPermissions($roleId, $permissionIds);

        return $roleId;
    }
}
