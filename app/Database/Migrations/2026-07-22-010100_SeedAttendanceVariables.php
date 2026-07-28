<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the three admin-editable attendance settings so they are visible (and
 * documented) in `/admin/settings` without an admin having to guess the names.
 *
 * `attendance_ip_enforce` defaults to '0' deliberately: enforcement stays OFF
 * until someone switches it on knowingly. The alternative — treating an empty
 * allowlist as "allow everyone" — means forgetting to configure it leaves zero
 * access control, silently and forever. While it is off the management page
 * shows a persistent warning banner.
 *
 * Idempotent per row (skips names that already exist), so it is safe on an
 * imported/live DB and re-runnable.
 */
class SeedAttendanceVariables extends Migration
{
    private const VARIABLES = [
        [
            'name'        => 'attendance_office_ips',
            'value'       => '',
            'description' => 'Comma-separated office IPs or CIDR ranges allowed to check in/out (e.g. "203.0.113.10, 10.0.0.0/24"). Only enforced when attendance_ip_enforce is 1.',
        ],
        [
            'name'        => 'attendance_ip_enforce',
            'value'       => '0',
            'description' => 'Set to 1 to block check-in/out from outside attendance_office_ips. 0 records the IP but allows any network.',
        ],
        [
            'name'        => 'attendance_locked_through',
            'value'       => '',
            'description' => 'Payroll close marker, YYYY-MM. Attendance corrections for that month and earlier are refused. Leave empty for no lock.',
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
            // Count including any soft-deleted row — UNIQUE(name) covers those
            // too, so this avoids a duplicate-key insert (see
            // SeedSiteNameVariable::up()).
            if ($db->table('site_variable')->where('name', $variable['name'])->countAllResults() > 0) {
                continue;
            }

            $db->table('site_variable')->insert($variable + [
                'type'       => 'string',
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Safe-on-live guard: only touch data on the dedicated tests DB group.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('site_variable')->whereIn('name', array_column(self::VARIABLES, 'name'))->delete();
        }
    }
}
