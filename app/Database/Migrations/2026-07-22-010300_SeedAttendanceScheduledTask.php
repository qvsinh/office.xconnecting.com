<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the `attendance.flag_missing_checkout` schedule so a fresh install
 * flags forgotten check-outs without an admin having to wire it up by hand.
 *
 * Hourly rather than once-a-day: the handler is cheap and idempotent (it only
 * looks at rows that are still unflagged), and an hourly cadence means the flag
 * appears the same morning regardless of when the cron host's day rolls over.
 *
 * Idempotent — skips if a row for this handler already exists.
 */
class SeedAttendanceScheduledTask extends Migration
{
    private const HANDLER = 'attendance.flag_missing_checkout';

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('scheduled_tasks')) {
            return;
        }

        if ($db->table('scheduled_tasks')->where('handler', self::HANDLER)->countAllResults() > 0) {
            return;
        }

        $db->table('scheduled_tasks')->insert([
            'name'             => 'Flag attendance days with no check-out',
            'handler'          => self::HANDLER,
            'params'           => null,
            'interval_minutes' => 60,
            'enabled'          => 1,
            'description'      => 'Marks yesterday\'s attendance rows that have a check-in but no check-out so a manager can correct them. Never invents a check-out time.',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        // Safe-on-live guard: only touch data on the dedicated tests DB group.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('scheduled_tasks')->where('handler', self::HANDLER)->delete();
        }
    }
}
