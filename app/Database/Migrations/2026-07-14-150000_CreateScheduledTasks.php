<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `scheduled_tasks` — rebuilt recurring-schedule table (see
 * kanban/processing/20260714-admin-scheduled-tasks.md §5/§7). Confirmed no
 * clash on `db_proxy_dev`: the legacy `tasks_scheduled` job-queue table is a
 * different name/shape and is not reused here. Guarded idempotent `up()`,
 * tests-only `down()` — mirrors `CreateTranslation`.
 */
class CreateScheduledTasks extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() must run before
        // tableExists() — a long-lived connection re-running this migration
        // across the test suite's regress()+latest() cycle can otherwise see
        // a stale "still exists" result and silently skip recreation.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('scheduled_tasks')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'handler' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'params' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'interval_minutes' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 60,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'last_run_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'next_run_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'last_result' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'last_duration_ms' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        // Covers dueTasks()'s `WHERE enabled=1 AND (next_run_at IS NULL OR next_run_at <= NOW())`.
        $this->forge->addKey(['enabled', 'next_run_at'], false, false, 'idx_scheduled_tasks_enabled_next_run');
        $this->forge->createTable('scheduled_tasks');

        $this->seedDefaultPurgeJobs($db);
    }

    /**
     * Pre-create the in-scope purge jobs, disabled, only on a fresh table
     * (never re-run — `up()` already returned early if the table existed).
     * Discoverable defaults for a fresh install; admins opt in via the
     * `toggle` action. See plan §7.
     */
    private function seedDefaultPurgeJobs(BaseConnection $db): void
    {
        $now = date('Y-m-d H:i:s');

        $db->table('scheduled_tasks')->insertBatch([
            [
                'name'             => 'Purge old sent emails',
                'handler'          => 'emails.purge',
                'params'           => json_encode(['days' => 7], JSON_THROW_ON_ERROR),
                'interval_minutes' => 1440,
                'enabled'          => 0,
                'description'      => 'Deletes rows from `emails` older than the configured number of days.',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'name'             => 'Purge old notifications',
                'handler'          => 'notifications.purge',
                'params'           => json_encode(['days' => 30], JSON_THROW_ON_ERROR),
                'interval_minutes' => 1440,
                'enabled'          => 0,
                'description'      => 'Deletes rows from `notifications` older than the configured number of days.',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
        ]);
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('scheduled_tasks', true);
        }
    }
}
