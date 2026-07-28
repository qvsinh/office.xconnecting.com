<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the reminder/digest schedules so a fresh install has them without an
 * admin wiring anything up (card 01d).
 *
 * The two daily handlers run every 15 minutes and decide for themselves whether
 * the Vietnam clock has passed their cutoff — the cutoff is derived from
 * `work_schedules`, so the office hours are not duplicated here and can't drift.
 * The digests carry their own interval instead.
 *
 * Idempotent per handler.
 */
class SeedAttendanceReminderTasks extends Migration
{
    private const TASKS = [
        [
            'name'             => 'Remind employees who have not checked out',
            'handler'          => 'attendance.remind_checkout',
            'interval_minutes' => 15,
            'description'      => 'Notifies anyone still checked in 15 minutes after their scheduled end time. Reminds at most once per day per person.',
        ],
        [
            'name'             => 'Daily absence digest for managers',
            'handler'          => 'attendance.absence_digest',
            'interval_minutes' => 1440,
            'description'      => 'Once the working day has started, notifies attendance.manage holders about absent / late / missing-check-out employees. Sends nothing when there is nothing to report.',
        ],
        [
            'name'             => 'Weekly uncorrected missing check-outs',
            'handler'          => 'attendance.missing_checkout_digest',
            'interval_minutes' => 10080,
            'description'      => 'Reminds managers about days still flagged as missing a check-out in the last 7 days — these block the monthly report.',
        ],
        [
            'name'             => 'Pending leave requests digest',
            'handler'          => 'requests.pending_digest',
            'interval_minutes' => 1440,
            'description'      => 'Notifies requests.approve holders about requests still pending after 24 hours. Employees are waiting on an answer.',
        ],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('scheduled_tasks')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::TASKS as $task) {
            if ($db->table('scheduled_tasks')->where('handler', $task['handler'])->countAllResults() > 0) {
                continue;
            }

            $db->table('scheduled_tasks')->insert($task + [
                'params'     => null,
                'enabled'    => 1,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('scheduled_tasks')->whereIn('handler', array_column(self::TASKS, 'handler'))->delete();
        }
    }
}
