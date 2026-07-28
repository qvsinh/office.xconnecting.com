<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * `php spark user:password <email> <password>`.
 *
 * @internal
 */
final class UserPasswordCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCommandSetsHashThatMatchesNewPassword(): void
    {
        $userModel = new UserModel();
        $id        = $userModel->insert([
            'name'   => 'CLI User',
            'email'  => 'cli@example.com',
            'password' => 'old-hash',
            'zone'   => 'user',
            'status' => 1,
        ]);

        command('user:password cli@example.com "NewFixtureValue1!"');

        $authConfig = config('Auth');
        $expected   = hash($authConfig->password_algorithm, 'NewFixtureValue1!' . $authConfig->password_salt);

        $this->assertSame($expected, $userModel->find($id)->password);
    }

    public function testCommandFailsLoudlyForUnknownEmail(): void
    {
        command('user:password nobody@example.com "WhateverValue1!"');

        $this->assertStringContainsString('No active user found', $this->getStreamFilterBuffer());
    }
}
