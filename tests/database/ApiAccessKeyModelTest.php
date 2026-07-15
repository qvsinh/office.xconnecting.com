<?php

use App\Models\ApiAccessKeyModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ApiAccessKeyModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    // null = migrate/regress every namespace (App's migrations aren't under
    // the default 'Tests\Support' namespace CI4 scopes migrations to).
    protected $namespace = null;

    public function testFindByAccessKeyAndTouchLastAccess(): void
    {
        $model = new ApiAccessKeyModel();
        $model->insert([
            'user_id'    => 1,
            'access_key' => 'test-access-key-123',
            'service_id' => 800,
            'is_enable'  => 1,
            'enable_log' => 1,
        ]);

        $row = $model->findByAccessKey('test-access-key-123');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->user_id);
        $this->assertNull($row->last_access);

        $model->touchLastAccess('test-access-key-123');
        $row = $model->findByAccessKey('test-access-key-123');
        $this->assertNotNull($row->last_access);
    }

    public function testFindByAccessKeyReturnsNullForUnknownKey(): void
    {
        $model = new ApiAccessKeyModel();
        $this->assertNull($model->findByAccessKey('does-not-exist'));
    }

    public function testCreateForUserMintsATokenThatDecodesToUserEnvService(): void
    {
        $user  = (object) ['id' => 42, 'env' => 'TESTING'];
        $model = new ApiAccessKeyModel();

        $accessKey = $model->createForUser($user, 800, 'my key');

        helper('app');
        $decoded = get_hashids(32)->decode($accessKey);

        $this->assertSame(42, $decoded[0]);
        $this->assertSame(0, $decoded[1]); // TESTING -> 0
        $this->assertSame(800, $decoded[2]);

        $row = $model->findByAccessKey($accessKey);
        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row->user_id);
        $this->assertSame(0, (int) $row->user_status);
        $this->assertSame(800, (int) $row->service_id);
        $this->assertSame(1, (int) $row->is_enable);
        $this->assertSame(1, (int) $row->enable_log);
        $this->assertSame('my key', $row->description);
    }

    public function testCreateForUserEncodesLiveEnvAsOne(): void
    {
        $user  = (object) ['id' => 7, 'env' => 'LIVE'];
        $model = new ApiAccessKeyModel();

        $accessKey = $model->createForUser($user, 801);

        helper('app');
        $decoded = get_hashids(32)->decode($accessKey);
        $this->assertSame(1, $decoded[1]);
    }

    public function testCreateForUserRejectsAtTheTwentyKeyCap(): void
    {
        $user  = (object) ['id' => 99, 'env' => 'TESTING'];
        $model = new ApiAccessKeyModel();

        // Seed 19 pre-existing rows directly (unique dummy tokens) rather
        // than minting 19 real ones in the same second — the real encode
        // includes `random_int(1, 1000)`, so 19+ real mints for the same
        // user/env/service in one loop carries a non-trivial birthday-
        // collision chance on the unique `access_key` column and would make
        // this test flaky.
        for ($i = 0; $i < 19; $i++) {
            $model->insert([
                'user_id'    => 99,
                'access_key' => 'seed-key-' . $i,
                'service_id' => 800,
                'is_enable'  => 1,
                'enable_log' => 1,
            ]);
        }
        $this->assertSame(19, $model->countForUser(99));

        // 20th key still succeeds (boundary: count was 19, cap is 20).
        $model->createForUser($user, 800);
        $this->assertSame(20, $model->countForUser(99));

        // The 21st is rejected.
        $this->expectException(RuntimeException::class);
        $model->createForUser($user, 800);
    }

    public function testCountForUserAndListForUserAreScopedToTheGivenUser(): void
    {
        $model = new ApiAccessKeyModel();
        $model->createForUser((object) ['id' => 1, 'env' => 'TESTING'], 800);
        $model->createForUser((object) ['id' => 1, 'env' => 'TESTING'], 801);
        $model->createForUser((object) ['id' => 2, 'env' => 'TESTING'], 800);

        $this->assertSame(2, $model->countForUser(1));
        $this->assertSame(1, $model->countForUser(2));

        $items = $model->listForUser(1);
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame(1, (int) $item->user_id);
        }
    }

    public function testSearchAdminJoinsUserAndFiltersByKeyword(): void
    {
        $userModel = model(UserModel::class);
        $userId    = $userModel->insert([
            'name'     => 'Ada Admin',
            'email'    => 'ada@example.com',
            'password' => 'unused-hash',
            'zone'     => 'client',
            'env'      => 'TESTING',
        ], true);

        $keyModel = new ApiAccessKeyModel();
        $keyModel->createForUser((object) ['id' => $userId, 'env' => 'TESTING'], 800);

        // Email exact match.
        $byEmail = $keyModel->searchAdmin(['keyword' => 'ada@example.com'], 25, 0);
        $this->assertCount(1, $byEmail);
        $this->assertSame('Ada Admin', $byEmail[0]->user_name);

        // Name LIKE match.
        $byName = $keyModel->searchAdmin(['keyword' => 'Ada'], 25, 0);
        $this->assertCount(1, $byName);

        // Numeric match against user_id/service_id/id.
        $byNumeric = $keyModel->searchAdmin(['keyword' => (string) $userId], 25, 0);
        $this->assertCount(1, $byNumeric);

        // Unrelated keyword yields no rows.
        $this->assertCount(0, $keyModel->searchAdmin(['keyword' => 'nobody-matches'], 25, 0));

        $this->assertSame(1, $keyModel->countAdmin(['keyword' => 'ada@example.com']));
    }

    public function testListForConsoleClientZoneScopesToOwnEnabledKeysOfTheGivenProvider(): void
    {
        $model = new ApiAccessKeyModel();

        $model->createForUser((object) ['id' => 1, 'env' => 'TESTING'], 800); // cnr (mine, enabled)
        $model->insert(['user_id' => 1, 'access_key' => 'disabled-key', 'service_id' => 800, 'is_enable' => 0, 'enable_log' => 1]);
        $model->insert(['user_id' => 2, 'access_key' => 'other-user-key', 'service_id' => 800, 'is_enable' => 1, 'enable_log' => 1]);
        $model->createForUser((object) ['id' => 1, 'env' => 'TESTING'], 801); // jprs, different provider

        $keys = $model->listForConsole(1, 'cnr');

        $this->assertCount(1, $keys);
        $this->assertSame(1, (int) $keys[0]->is_enable);
        $this->assertSame(800, (int) $keys[0]->service_id);
        // Console list must NOT load the access_key secret (defense-in-depth).
        $this->assertObjectNotHasProperty('access_key', $keys[0]);
    }

    public function testListForConsoleAdminZoneReturnsEveryUsersKeysWithOwnerInfo(): void
    {
        $userModel = model(UserModel::class);
        $userId    = $userModel->insert([
            'name' => 'Console Owner', 'email' => 'console-owner@example.com',
            'password' => 'unused-hash', 'zone' => 'client', 'env' => 'TESTING',
        ], true);

        $model = new ApiAccessKeyModel();
        $model->createForUser((object) ['id' => $userId, 'env' => 'TESTING'], 800);
        $model->insert(['user_id' => $userId, 'access_key' => 'disabled-admin-key', 'service_id' => 800, 'is_enable' => 0, 'enable_log' => 1]);

        $keys = $model->listForConsole(null, 'cnr');

        // Admin zone: both enabled and disabled keys are listed (the run()
        // endpoint, not the list, is what rejects disabled keys).
        $this->assertCount(2, $keys);
        $this->assertSame('Console Owner', $keys[0]->user_name);
    }

    public function testListForConnectionTestClientZoneScopesToOwnKeysAndIncludesTheAccessKey(): void
    {
        $model = new ApiAccessKeyModel();

        $mine  = $model->createForUser((object) ['id' => 1, 'env' => 'TESTING'], 800);
        $model->insert(['user_id' => 2, 'access_key' => 'other-user-key', 'service_id' => 800, 'is_enable' => 1, 'enable_log' => 1]);

        $keys = $model->listForConnectionTest(1);

        $this->assertCount(1, $keys);
        $this->assertSame($mine, $keys[0]->access_key);
    }

    public function testListForConnectionTestAdminZoneReturnsEveryUsersKeysWithAccessKeyAndOwnerInfo(): void
    {
        $userModel = model(UserModel::class);
        $userId    = $userModel->insert([
            'name' => 'Test Owner', 'email' => 'test-owner@example.com',
            'password' => 'unused-hash', 'zone' => 'client', 'env' => 'TESTING',
        ], true);

        $model     = new ApiAccessKeyModel();
        $accessKey = $model->createForUser((object) ['id' => $userId, 'env' => 'TESTING'], 800);
        // Disabled keys are included on purpose — the browser surfacing the
        // real 401 is the point of the self-test.
        $model->insert(['user_id' => $userId, 'access_key' => 'disabled-test-key', 'service_id' => 800, 'is_enable' => 0, 'enable_log' => 1]);

        $keys = $model->listForConnectionTest(null);

        $this->assertCount(2, $keys);
        $this->assertSame('Test Owner', $keys[0]->user_name);
        $accessKeys = array_column($keys, 'access_key');
        $this->assertContains($accessKey, $accessKeys);
        $this->assertContains('disabled-test-key', $accessKeys);
    }
}
