<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the company's baseline working day, confirmed by the user on
 * 2026-07-25: **08:30–18:00, 90-minute lunch from 12:00, Monday–Friday**
 * → 9h30 on site − 1h30 break = **8h00 net** per working day.
 *
 * Idempotent: only inserts when no schedule row exists at all, so it can't
 * duplicate the default or overwrite an admin's edits.
 */
class SeedDefaultWorkSchedule extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('work_schedules')) {
            return;
        }

        if ($db->table('work_schedules')->countAllResults() > 0) {
            return;
        }

        $db->table('work_schedules')->insert([
            'name'          => 'Office (08:30–18:00)',
            'start_time'    => '08:30:00',
            'end_time'      => '18:00:00',
            'break_start'   => '12:00:00',
            'break_minutes' => 90,
            'grace_minutes' => 0,
            'workdays'      => '1,2,3,4,5',
            'is_default'    => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        // Safe-on-live guard: only touch data on the dedicated tests DB group.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('work_schedules')->where('is_default', 1)->delete();
        }
    }
}
