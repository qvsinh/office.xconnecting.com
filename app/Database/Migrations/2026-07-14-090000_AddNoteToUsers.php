<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `db_proxy_dev`/prod already carry a `note` TEXT column (ported from the
 * legacy schema, see ../proxy.services.vn/setup/db_proxy_service.sql) — this
 * migration only backfills it on DBs created fresh by `CreateUsers` (e.g.
 * the `tests` group), guarded so it's a no-op where the column already
 * exists.
 */
class AddNoteToUsers extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('users', [
            'note' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'currency', // 'currency' is the last column both dev and test schemas share
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists()) {
            return;
        }

        $this->forge->dropColumn('users', 'note');
    }

    private function columnExists(): bool
    {
        // Migration::$db is typed as the generic ConnectionInterface, which
        // doesn't declare fieldExists() — go through Database::connect()
        // (typed @return BaseConnection) for the same connection instead.
        // `fieldExists()` caches column names per table on the connection
        // (BaseConnection::$dataCache) and never invalidates on ALTER TABLE,
        // so a long-lived connection re-running this migration (e.g. the
        // test suite's regress()+latest() cycle between every test method)
        // would see stale results — reset the cache first.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->fieldExists('note', 'users');
    }
}
