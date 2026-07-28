<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * `php spark user:reset-password <id|email> [password]` — real routing through
 * the command layer, asserting the stored hash matches Auth::login()'s scheme
 * (so the reset password actually verifies on login), for both id and email
 * lookups, plus the not-found path.
 *
 * @internal
 */
final class ResetUserPasswordCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function expectedHash(string $plain): string
    {
        $cfg = config('Auth');

        return hash($cfg->password_algorithm, $plain . $cfg->password_salt);
    }

    private function seedUser(string $email): int
    {
        return (int) (new UserModel())->insert([
            'name'     => 'Reset Target',
            'email'    => $email,
            'password' => 'old-hash',
            'zone'     => 'user',
            'status'   => 1,
        ], true);
    }

    public function testResetsByNumericId(): void
    {
        $id = $this->seedUser('by-id@example.com');

        command('user:reset-password ' . $id . ' NewPass123!');

        $user = (new UserModel())->find($id);
        $this->assertSame($this->expectedHash('NewPass123!'), $user->password);
    }

    public function testResetsByEmail(): void
    {
        $id = $this->seedUser('by-email@example.com');

        command('user:reset-password by-email@example.com AnotherPass1!');

        $user = (new UserModel())->find($id);
        $this->assertSame($this->expectedHash('AnotherPass1!'), $user->password);
    }

    public function testUnknownIdentifierLeavesNoChangeAndReports(): void
    {
        command('user:reset-password nobody@example.com Whatever1!');

        $this->assertStringContainsString('No user found', $this->getStreamFilterBuffer());
    }

    public function testRejectsAPasswordThatFailsTheSharedPolicy(): void
    {
        $id = $this->seedUser('short@example.com');

        // Long enough was never the only rule — this one has no special char.
        command('user:reset-password ' . $id . ' NoSpecial123');

        // Password unchanged (rejected before the update).
        $user = (new UserModel())->find($id);
        $this->assertSame('old-hash', $user->password);
        $this->assertStringContainsString('special character', $this->getStreamFilterBuffer());
    }
}
