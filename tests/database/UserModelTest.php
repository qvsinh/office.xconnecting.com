<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class UserModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testFindActiveByEmailReturnsActiveUser(): void
    {
        $model = new UserModel();
        $model->insert([
            'name'   => 'Active User',
            'email'  => 'active@example.com',
            'password' => 'irrelevant-hash',
            'zone'   => 'client',
            'status' => 1,
        ]);

        $user = $model->findActiveByEmail('active@example.com');

        $this->assertNotNull($user);
        $this->assertSame('active@example.com', $user->email);
    }

    public function testFindActiveByEmailReturnsNullForSoftDeletedUser(): void
    {
        $model = new UserModel();
        $id    = $model->insert([
            'name'   => 'Deleted User',
            'email'  => 'deleted@example.com',
            'password' => 'irrelevant-hash',
            'zone'   => 'client',
            'status' => 1,
        ]);
        $model->delete($id);

        $this->assertNull($model->findActiveByEmail('deleted@example.com'));
    }

    public function testFindActiveByEmailReturnsNullForUnknownEmail(): void
    {
        $model = new UserModel();

        $this->assertNull($model->findActiveByEmail('nobody@example.com'));
    }

    public function testStampLoginSetsLoginAt(): void
    {
        $model = new UserModel();
        $id    = $model->insert([
            'name'   => 'Login User',
            'email'  => 'login@example.com',
            'password' => 'irrelevant-hash',
            'zone'   => 'client',
            'status' => 1,
        ]);

        $this->assertNull($model->find($id)->login_at);

        $model->stampLogin($id);

        $this->assertNotNull($model->find($id)->login_at);
    }
}
