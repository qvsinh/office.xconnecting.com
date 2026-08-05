<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * On/off switches for the attendance reminders (card 01d). Both default to on:
 * the whole point is that nobody has to remember to enable them.
 *
 * There is deliberately no `attendance_email_reminders` flag — this app manages
 * in-app notifications for these; SES exists (see `AttendanceNotifier`) but
 * reminders deliberately do not use it yet (see
 * DEPLOYMENT.md "Email send pipeline"). A setting that silently does nothing is
 * worse than an absent one; add it together with the send pipeline.
 *
 * Idempotent per row.
 */
class SeedAttendanceReminderVariables extends Migration
{
    private const VARIABLES = [
        [
            'name'        => 'attendance_checkout_reminder',
            'value'       => '1',
            'description' => 'Notify employees who have not checked out after their scheduled end time. 0 disables it.',
        ],
        [
            'name'        => 'attendance_absence_digest',
            'value'       => '1',
            'description' => 'Send managers a daily digest of absent / late / missing-check-out employees. 0 disables it.',
        ],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::VARIABLES as $variable) {
            if ($db->table('site_variable')->where('name', $variable['name'])->countAllResults() > 0) {
                continue;
            }

            $db->table('site_variable')->insert($variable + ['type' => 'string', 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('site_variable')->whereIn('name', array_column(self::VARIABLES, 'name'))->delete();
        }
    }
}
