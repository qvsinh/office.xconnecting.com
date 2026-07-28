<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `users.annual_leave_days` — a per-employee override of the company
 * `leave_annual_days` setting (seniority, part-time, contract terms). NULL means
 * "use the company default", so a company on one quota needs no per-user data.
 *
 * Guarded so it no-ops where the column already exists (see AddNoteToUsers for
 * why `resetDataCache()` is required first).
 */
class AddAnnualLeaveDaysToUsers extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('users', [
            'annual_leave_days' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
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

        $this->forge->dropColumn('users', 'annual_leave_days');
    }

    private function columnExists(): bool
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->fieldExists('annual_leave_days', 'users');
    }
}
