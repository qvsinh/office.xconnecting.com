<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `attendance_days.checkout_reminded_at` — when the employee was reminded that
 * they had not checked out (card 01d).
 *
 * A column rather than timing logic, because OS cron calls `schedule:run` every
 * minute: the reminder handler must be safe to invoke 1 440 times a day, and
 * "have we already told them?" is the only reliable guard. It doubles as evidence
 * if somebody later says they were never told.
 *
 * Guarded so it no-ops where the column already exists (see AddNoteToUsers for
 * why `resetDataCache()` is required first).
 */
class AddCheckoutRemindedAtToAttendanceDays extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('attendance_days', [
            'checkout_reminded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists()) {
            return;
        }

        $this->forge->dropColumn('attendance_days', 'checkout_reminded_at');
    }

    private function columnExists(): bool
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->tableExists('attendance_days') && $db->fieldExists('checkout_reminded_at', 'attendance_days');
    }
}
