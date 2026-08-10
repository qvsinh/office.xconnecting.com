<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds `mail_notify_employee` — whether the employee also receives their own
 * check-in / check-out receipt.
 *
 * Separate from `mail_notify_attendance` (the master switch) because it doubles the
 * number of messages SES sends: one per event becomes two. Defaulting it on, since
 * the person who tapped the button is the one entitled to a record of it, and this
 * was asked for; turning it off is a one-field edit on `/admin/settings`.
 *
 * Idempotent, so it is safe on a live database and re-runnable.
 */
class SeedEmployeeMailCopySetting extends Migration
{
    private const NAME = 'mail_notify_employee';

    private const VALUE = '1';

    private const DESCRIPTION = 'Set to 1 to also email the employee their own check-in / check-out receipt, 0 for admin-only. Doubles the messages sent. Requires mail_notify_attendance to be on.';

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        // Counted including soft-deleted rows: UNIQUE(name) covers those too.
        if ($db->table('site_variable')->where('name', self::NAME)->countAllResults() > 0) {
            return;
        }

        $db->table('site_variable')->insert([
            'name'        => self::NAME,
            'value'       => self::VALUE,
            'description' => self::DESCRIPTION,
            'type'        => 'string',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Safe-on-live guard: only touch data on the dedicated tests DB group, so a
     * rollback cannot discard a choice an administrator made.
     */
    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('site_variable')->where('name', self::NAME)->delete();
        }
    }
}
