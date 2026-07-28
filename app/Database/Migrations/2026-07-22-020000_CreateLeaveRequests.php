<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `leave_requests` — employee leave / late-arrival requests awaiting a manager's
 * decision (see kanban/processing/20260722-02-leave-late-requests.md).
 *
 * An approved row changes how the day is *reported* in attendance (card 01b's
 * `AttendanceCalendar` consumes it), which is the whole point: without that link
 * an employee stays marked absent on a day the company approved off.
 *
 * `cancelled` is a status, not a delete: a withdrawn request is part of the
 * record a manager may later need to see.
 */
class CreateLeaveRequests extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('leave_requests')) {
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
            // `leave` | `late`
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
            ],
            // For `leave`: annual | unpaid | sick. Null for `late`.
            'leave_kind' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
            ],
            'start_date' => [
                'type' => 'DATE',
            ],
            // Range end; the model defaults it to `start_date`.
            'end_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            // full | morning | afternoon — single-day leave only.
            'day_portion' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => 'full',
            ],
            // `late`: the arrival time the employee is asking for.
            'expected_time' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'reason' => [
                'type' => 'TEXT',
            ],
            // pending | approved | rejected | cancelled
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => 'pending',
            ],
            'reviewed_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'null'       => true,
            ],
            'reviewed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'review_note' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
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
        $this->forge->addKey('user_id');
        $this->forge->addKey('status');
        // The calendar looks requests up by date range — index for that access path.
        $this->forge->addKey(['start_date', 'end_date']);
        $this->forge->createTable('leave_requests');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('leave_requests', true);
        }
    }
}
