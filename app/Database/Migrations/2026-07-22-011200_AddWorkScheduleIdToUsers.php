<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `users.work_schedule_id` — which `work_schedules` row applies to an employee.
 * NULL falls back to the default schedule, so a company on one set of hours
 * needs no per-user data at all; a second shift can be introduced later without
 * building a rota system.
 *
 * Guarded so it no-ops where the column already exists (see AddNoteToUsers for
 * why `resetDataCache()` is required first).
 */
class AddWorkScheduleIdToUsers extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('users', [
            'work_schedule_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists()) {
            return;
        }

        $this->forge->dropColumn('users', 'work_schedule_id');
    }

    private function columnExists(): bool
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->fieldExists('work_schedule_id', 'users');
    }
}
