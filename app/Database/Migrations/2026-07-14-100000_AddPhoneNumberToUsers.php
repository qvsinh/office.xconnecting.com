<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `db_proxy_dev`/prod already carry a `phone_number` VARCHAR column (ported
 * from the legacy schema, see ../proxy.services.vn/setup/db_proxy_service.sql)
 * — this migration only backfills it on DBs created fresh by `CreateUsers`
 * (e.g. the `tests` group), guarded so it's a no-op where the column already
 * exists. Mirrors AddNoteToUsers.
 */
class AddPhoneNumberToUsers extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('users', [
            'phone_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'company',
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists()) {
            return;
        }

        $this->forge->dropColumn('users', 'phone_number');
    }

    private function columnExists(): bool
    {
        // See AddNoteToUsers for why resetDataCache() is required here:
        // fieldExists() caches column names per connection and never
        // invalidates on ALTER TABLE, so a long-lived connection re-running
        // this migration (e.g. the test suite's regress()+latest() cycle)
        // would otherwise see stale results.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->fieldExists('phone_number', 'users');
    }
}
