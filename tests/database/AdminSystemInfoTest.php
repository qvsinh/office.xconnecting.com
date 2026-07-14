<?php

use App\Controllers\Admin\System;
use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;
use App\Models\NotificationModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `Admin\System::buildInfoData()` — the system-info whitelist behind
 * `/admin/system`. Exercised directly (needs a real DB connection for the
 * record counts, hence a database test rather than a plain unit test). The
 * HTTP-rendered page itself is covered once `frontend-coder` adds
 * `admin/system/info` (view layer, not yet built — see kanban plan).
 *
 * @internal
 */
final class AdminSystemInfoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCountsMatchRealTableCounts(): void
    {
        model(UserModel::class)->insert([
            'name' => 'Count User', 'email' => 'count@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 1,
        ]);
        model(NotificationModel::class)->insert(['user_id' => 1, 'message' => 'hi', 'is_read' => 0]);

        $data = (new System())->buildInfoData();

        $this->assertSame(model(UserModel::class)->countAllResults(), $data['counts']['users']);
        $this->assertSame(model(ApiAccessKeyModel::class)->countAllResults(), $data['counts']['api_access_keys']);
        $this->assertSame(model(ApiHistoryModel::class)->countAllResults(), $data['counts']['api_history']);
        $this->assertSame(model(NotificationModel::class)->countAllResults(), $data['counts']['notifications']);
        $this->assertGreaterThanOrEqual(1, $data['counts']['users']);
        $this->assertGreaterThanOrEqual(1, $data['counts']['notifications']);
    }

    public function testDbDisplayValuesAreDriverAndDatabaseNameOnly(): void
    {
        $data = (new System())->buildInfoData();

        $this->assertSame('MySQLi', $data['db_driver']);
        $this->assertSame((string) getenv('database.tests.database'), $data['db_database']);
    }

    public function testNoKnownSecretValueLeaksIntoSystemInfo(): void
    {
        $data    = (new System())->buildInfoData();
        $encoded = json_encode($data);

        $secrets = [
            getenv('database.tests.password'),
            getenv('database.default.password'),
            getenv('hashid_salt'),
            getenv('encryption.key'),
            getenv('app.basic.auth.password'),
        ];

        foreach ($secrets as $secret) {
            if ($secret === false || $secret === '') {
                continue;
            }

            $this->assertStringNotContainsString((string) $secret, $encoded);
        }

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('db_password', $data);
        $this->assertArrayNotHasKey('db_username', $data);
    }

    public function testWritableChecksAreBooleans(): void
    {
        $data = (new System())->buildInfoData();

        $this->assertIsBool($data['writable']['writable']);
        $this->assertIsBool($data['writable']['logs']);
        $this->assertIsBool($data['writable']['cache']);
        $this->assertIsBool($data['writable']['session']);
    }
}
