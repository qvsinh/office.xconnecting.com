<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Adds the per-key IP allowlist ("restrict IP usage") column backing
 * `ApiFilter::before()`'s enforcement. Genuine additive change — must run
 * on dev/live, not a test-only backfill. Guarded so re-running is a no-op.
 * Mirrors AddPhoneNumberToUsers/AddNoteToUsers.
 */
class AddAllowedIpsToUsersApiAccessKeys extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('users_api_access_keys', [
            'allowed_ips' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'description',
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists()) {
            return;
        }

        $this->forge->dropColumn('users_api_access_keys', 'allowed_ips');
    }

    private function columnExists(): bool
    {
        // fieldExists() caches column names per connection and never
        // invalidates on ALTER TABLE, so a long-lived connection re-running
        // this migration (e.g. the test suite's regress()+latest() cycle)
        // would otherwise see stale results.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->fieldExists('allowed_ips', 'users_api_access_keys');
    }
}
