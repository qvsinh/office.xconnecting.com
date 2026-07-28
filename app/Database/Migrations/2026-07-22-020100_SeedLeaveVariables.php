<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the annual-leave quota as an admin-editable setting. Default 12 days —
 * the Vietnamese statutory minimum for a full year of service.
 *
 * A per-employee override lives on `users.annual_leave_days`
 * (see AddAnnualLeaveDaysToUsers); this is the company-wide fallback.
 *
 * Idempotent per row, so it is safe on an imported/live DB and re-runnable.
 */
class SeedLeaveVariables extends Migration
{
    private const VARIABLES = [
        [
            'name'        => 'leave_annual_days',
            'value'       => '12',
            'description' => 'Annual paid-leave days per employee per calendar year. Overridable per employee on the user record.',
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
            // Count including soft-deleted rows — UNIQUE(name) covers those too.
            if ($db->table('site_variable')->where('name', $variable['name'])->countAllResults() > 0) {
                continue;
            }

            $db->table('site_variable')->insert($variable + ['type' => 'string', 'created_at' => $now]);
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
