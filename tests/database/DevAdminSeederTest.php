<?php

use App\Database\Seeds\DevAdminSeeder;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class DevAdminSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = DevAdminSeeder::class;

    public function testSeederCreatesTheDevAdmin(): void
    {
        $userModel = new UserModel();
        $admin     = $userModel->findActiveByEmail('admin@xconnecting.com');

        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->zone);
        $this->assertSame(1, (int) $admin->status);
    }

    public function testSeederIsANoOpWhenAdminAlreadyExists(): void
    {
        $userModel = new UserModel();
        $countBefore = $userModel->countAllResults();

        $this->seed(DevAdminSeeder::class);

        $this->assertSame($countBefore, $userModel->countAllResults());
    }
}
