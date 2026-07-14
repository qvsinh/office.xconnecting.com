<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `emails` already exists on dev/prod (confirmed via `SHOW CREATE TABLE
 * emails` against db_proxy_dev — see
 * kanban/processing/20260714-admin-emails.md §5) so `up()` is a guarded
 * no-op there. On a greenfield/tests DB this creates a schema-compatible
 * table. `id` is a VARCHAR token (not auto-increment) — matches the live
 * schema and EmailModel's `useAutoIncrement = false`.
 */
class CreateEmails extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() must run before
        // tableExists() — a long-lived connection re-running this migration
        // across the test suite's regress()+latest() cycle can otherwise see
        // a stale "still exists" result and silently skip recreation.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('emails')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'content' => [
                'type' => 'LONGTEXT',
            ],
            'from_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'to_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'    => 'INT',
                'null'    => true,
                'default' => 0,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'views' => [
                'type'    => 'INT',
                'null'    => true,
                'default' => 0,
            ],
            'clicks' => [
                'type'    => 'INT',
                'null'    => true,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        // Matches the live `emails_created_IDX` key.
        $this->forge->addKey('created_at', false, false, 'emails_created_IDX');
        // Test-DB-only addition (no equivalent on the live schema): the admin
        // log list filters/sorts on `status` too (EmailModel::searchAdmin()).
        $this->forge->addKey('status');
        $this->forge->createTable('emails');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('emails', true);
        }
    }
}
