<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `users.attendance_exempt` — this employee does not record check-in / check-out.
 *
 * Some people are simply not on the clock: a director, a contractor, an account
 * that exists to receive reports. Before this flag they showed up "absent" every
 * single day, which made the today board and the monthly report read as a wall of
 * red for people nobody expected to see there.
 *
 * A column on `users` rather than a setting, because it is a property of the
 * person, and an admin editing the user is where the decision naturally lands.
 *
 * Defaults to 0: existing employees keep recording attendance. Guarded, so it is
 * safe on a live database and re-runnable.
 */
class AddAttendanceExemptToUsers extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('users') || $db->fieldExists('attendance_exempt', 'users')) {
            return;
        }

        $this->forge->addColumn('users', [
            'attendance_exempt' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'annual_leave_days',
                'comment'    => '1 = does not record check-in/out and is left off the attendance screens',
            ],
        ]);
    }

    public function down(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('users') && $db->fieldExists('attendance_exempt', 'users')) {
            $this->forge->dropColumn('users', 'attendance_exempt');
        }
    }
}
