<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `attendance_days` — one row per user per working day (see
 * kanban/processing/20260722-01-attendance-checkin-checkout.md §2).
 *
 * All DATETIME values are UTC, matching every other table in this app
 * (`$appTimezone = 'UTC'`); the Vietnam business day is applied when deriving
 * `work_date` and when rendering — see `attendance_helper.php`.
 *
 * No stored `work_seconds`: worked time is derived from `checkin_at`/
 * `checkout_at` (minus the schedule's break overlap, card 01b) so an admin
 * correction can never leave a stale total behind.
 *
 * The `original_*` / `edited_*` columns are the correction audit trail —
 * attendance feeds payroll, so a changed time must always be traceable back
 * to the value the employee actually recorded.
 */
class CreateAttendanceDays extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('attendance_days')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            'work_date' => [
                'type' => 'DATE',
            ],
            'checkin_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'checkout_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'checkin_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'checkout_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'missing_checkout' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'note' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'edited_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'null'       => true,
            ],
            'edited_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'original_checkin_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'original_checkout_at' => [
                'type' => 'DATETIME',
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
        // The unique key is the real one-check-in-per-day guard; the
        // controller's pre-check only exists to produce a friendly message.
        $this->forge->addUniqueKey(['user_id', 'work_date']);
        $this->forge->addKey('work_date');
        $this->forge->addKey('missing_checkout');
        $this->forge->createTable('attendance_days');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('attendance_days', true);
        }
    }
}
