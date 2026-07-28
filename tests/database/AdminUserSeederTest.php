<?php

use App\Libraries\PasswordHasher;
use App\Database\Seeds\AdminUserSeeder;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `AdminUserSeeder`: creates the first admin so a fresh copy of the app is
 * loginable without manual SQL, wired to the `admin` role, and safe to run
 * more than once. `$migrate = true` also runs `SeedRolesAndPermissions`
 * first, so the `admin`/`user` roles already exist when this seeder looks
 * one up.
 *
 * @internal
 */
final class AdminUserSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AdminUserSeeder::class;

    public function testSeederCreatesAdminWithDefaultsAndAdminRole(): void
    {
        $userModel = new UserModel();
        $admin     = $userModel->findActiveByEmail('admin@example.com');
        $adminRole = (new RoleModel())->findByName('admin');

        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->zone);
        $this->assertSame(1, (int) $admin->status);
        $this->assertSame((int) $adminRole->id, (int) $admin->role_id);

        $this->assertTrue((new PasswordHasher())->verify('admin1234', $admin->password));
    }

    public function testSeederIsANoOpWhenAdminAlreadyExists(): void
    {
        $userModel   = new UserModel();
        $countBefore = $userModel->countAllResults();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame($countBefore, $userModel->countAllResults());
    }
}
