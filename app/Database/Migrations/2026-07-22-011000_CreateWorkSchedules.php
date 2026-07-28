<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `work_schedules` — the expected working day an attendance row is measured
 * against (see kanban/processing/20260722-01b-work-schedule-holidays-day-status.md).
 *
 * `break_start` + `break_minutes` describe the lunch window as an *interval*,
 * not a flat deduction: worked time subtracts the overlap between the employee's
 * [check-in, check-out] span and [break_start, break_start + break_minutes]. A
 * half day that ends at 11:00 never took lunch and must not be docked for it.
 *
 * All times are Vietnam local wall-clock (the schedule is a human rule, not a
 * timestamp) — the UTC conversion happens against each `work_date`.
 */
class CreateWorkSchedules extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('work_schedules')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'start_time' => [
                'type'    => 'TIME',
                'default' => '08:30:00',
            ],
            'end_time' => [
                'type'    => 'TIME',
                'default' => '18:00:00',
            ],
            'break_start' => [
                'type'    => 'TIME',
                'default' => '12:00:00',
            ],
            'break_minutes' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'unsigned'   => true,
                'default'    => 90,
            ],
            // Lateness tolerance. 0 = strict: 08:31 is late.
            'grace_minutes' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'unsigned'   => true,
                'default'    => 0,
            ],
            // ISO-8601 day numbers, 1 = Monday. Default Mon–Fri.
            'workdays' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => '1,2,3,4,5',
            ],
            'is_default' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
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
        $this->forge->addKey('is_default');
        $this->forge->createTable('work_schedules');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('work_schedules', true);
        }
    }
}
