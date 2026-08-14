<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds `mail_notify_requests` — the switch for leave / late-arrival mail.
 *
 * One switch covering both directions (the administrator hearing about a new or
 * withdrawn request, the employee hearing the decision), because an office that
 * finds this noisy wants silence in both, not half of it. The address it goes to
 * is the existing `mail_notify_admin_recipient`.
 *
 * Defaults to on: a request nobody is told about sits unseen until somebody
 * happens to open the approval queue, which is the problem this exists to fix.
 *
 * Idempotent, so it is safe on a live database and re-runnable.
 */
class SeedRequestMailSetting extends Migration
{
    private const NAME        = 'mail_notify_requests';
    private const VALUE       = '1';
    private const DESCRIPTION = 'Set to 1 to email leave / late-arrival requests: the administrator (mail_notify_admin_recipient) when one is submitted or withdrawn, and the employee when it is approved or rejected. 0 stops both.';

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
