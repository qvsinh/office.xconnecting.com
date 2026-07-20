<?php

use App\Models\ApiKeyModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ApiKeyModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCreateForUserReturnsPlaintextOnceAndNeverStoresIt(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('key-owner@example.com');

        $result = $model->createForUser($userId, 'CI server', 'read');

        $this->assertStringStartsWith('sk_', $result['plain']);
        $this->assertSame(substr($result['plain'], 0, 12), $result['row']->token_prefix);
        $this->assertNotSame($result['plain'], $result['row']->token_hash);
        $this->assertSame(hash('sha256', $result['plain']), $result['row']->token_hash);
    }

    public function testForUserNeverSelectsTokenHash(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('list-owner@example.com');
        $model->createForUser($userId, 'Listed key');

        $rows = $model->forUser($userId);

        $this->assertCount(1, $rows);
        $this->assertObjectNotHasProperty('token_hash', $rows[0]);
    }

    public function testResolveReturnsRowForAValidToken(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('resolve-owner@example.com');
        $result = $model->createForUser($userId, 'Valid key');

        $resolved = $model->resolve($result['plain']);

        $this->assertNotNull($resolved);
        $this->assertSame($userId, (int) $resolved->user_id);
    }

    public function testResolveRejectsAWrongToken(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('wrong-owner@example.com');
        $model->createForUser($userId, 'Some key');

        $this->assertNull($model->resolve('sk_' . bin2hex(random_bytes(20))));
    }

    public function testResolveRejectsARevokedToken(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('revoked-owner@example.com');
        $result = $model->createForUser($userId, 'Revoked key');

        $model->revoke((int) $result['row']->id, $userId);

        $this->assertNull($model->resolve($result['plain']));
    }

    public function testResolveRejectsAnExpiredToken(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('expired-owner@example.com');
        $result = $model->createForUser($userId, 'Expired key', 'read', '2000-01-01 00:00:00');

        $this->assertNull($model->resolve($result['plain']));
    }

    public function testResolveStampsLastUsedAtOnAHit(): void
    {
        $model  = new ApiKeyModel();
        $userId = $this->createUser('stamp-owner@example.com');
        $result = $model->createForUser($userId, 'Stamped key');

        $this->assertNull($result['row']->last_used_at);

        $model->resolve($result['plain']);

        $this->assertNotNull($model->find($result['row']->id)->last_used_at);
    }

    public function testRevokeIsScopedToTheOwner(): void
    {
        $model   = new ApiKeyModel();
        $ownerId = $this->createUser('real-owner@example.com');
        $otherId = $this->createUser('not-owner@example.com');
        $result  = $model->createForUser($ownerId, 'Owned key');

        $this->assertFalse($model->revoke((int) $result['row']->id, $otherId));
        $this->assertTrue($model->revoke((int) $result['row']->id, $ownerId));
    }

    private function createUser(string $email): int
    {
        return (int) (new UserModel())->insert([
            'name'     => $email,
            'email'    => $email,
            'password' => 'irrelevant-hash',
            'zone'     => 'client',
            'status'   => 1,
        ]);
    }
}
