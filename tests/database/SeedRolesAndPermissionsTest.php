<?php

use App\Database\Migrations\SeedRolesAndPermissions;
use App\Models\PermissionModel;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `SeedRolesAndPermissions` migration: the default roles/permissions exist
 * after `migrate` and existing users' `role_id` gets backfilled from `zone`.
 * `$migrate = true` + `$namespace = null` already runs it once per test via
 * the DatabaseTestTrait regress()+latest() cycle.
 *
 * @internal
 */
final class SeedRolesAndPermissionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testDefaultRolesExistAfterMigrate(): void
    {
        $roleModel = new RoleModel();

        $admin = $roleModel->findByName('admin');
        $user  = $roleModel->findByName('user');

        $this->assertNotNull($admin);
        $this->assertSame('Administrator', $admin->label);
        $this->assertNotNull($user);
        $this->assertSame('User', $user->label);
    }

    public function testStarterPermissionSetExistsAfterMigrate(): void
    {
        $permissionModel = new PermissionModel();

        foreach (['users.manage', 'settings.manage', 'system.manage', 'content.manage'] as $name) {
            $this->assertNotNull($permissionModel->findByName($name), "missing seeded permission: {$name}");
        }
    }

    public function testAdminRoleIsGrantedEveryPermissionAndUserRoleGetsNone(): void
    {
        $roleModel       = new RoleModel();
        $permissionModel = new PermissionModel();

        $adminRole = $roleModel->findByName('admin');
        $userRole  = $roleModel->findByName('user');

        $allPermissionNames = array_map(static fn ($row) => $row->name, $permissionModel->findAll());
        $adminPermissions   = $roleModel->permissionsFor((int) $adminRole->id);

        sort($allPermissionNames);
        sort($adminPermissions);
        $this->assertSame($allPermissionNames, $adminPermissions);

        $this->assertSame([], $roleModel->permissionsFor((int) $userRole->id));
    }

    public function testExistingGlobalZoneUserGotTheAdminRoleId(): void
    {
        // The regress()+latest() cycle in setUp() already ran this
        // migration once against an empty `users` table (nothing to
        // backfill). Insert a row with `role_id` still null and re-run just
        // the backfill: it's guarded by `role_id IS NULL`, so this only
        // ever touches rows the first pass could not have seen — mirrors
        // "an existing global-zone user" from the plan.
        $userModel = new UserModel();
        $userId    = $userModel->insert([
            'name'     => 'Legacy Admin',
            'email'    => 'legacy-admin@example.com',
            'password' => 'irrelevant-hash',
            'zone'     => 'global',
            'status'   => 1,
        ]);

        (new SeedRolesAndPermissions())->up();

        $adminRole = (new RoleModel())->findByName('admin');
        $user      = $userModel->find($userId);

        $this->assertSame((int) $adminRole->id, (int) $user->role_id);
    }

    public function testExistingClientZoneUserGotTheUserRoleId(): void
    {
        $userModel = new UserModel();
        $userId    = $userModel->insert([
            'name'     => 'Legacy Client',
            'email'    => 'legacy-client@example.com',
            'password' => 'irrelevant-hash',
            'zone'     => 'client',
            'status'   => 1,
        ]);

        (new SeedRolesAndPermissions())->up();

        $userRole = (new RoleModel())->findByName('user');
        $user     = $userModel->find($userId);

        $this->assertSame((int) $userRole->id, (int) $user->role_id);
    }
}
