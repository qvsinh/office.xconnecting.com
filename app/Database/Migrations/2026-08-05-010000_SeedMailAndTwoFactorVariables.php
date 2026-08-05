<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the admin-editable settings for attendance notification mail and the
 * two-factor issuer, so they show up in `/admin/settings` with a description
 * rather than having to be guessed at.
 *
 * `security_2fa_name` replaces the `security.2fa.name` env key that was removed:
 * that key was never read by anything, while the label people actually saw in
 * their authenticator app was hardcoded in `Google2faService`. It is a setting now
 * so the office can rename itself without a deploy.
 *
 * Credentials are deliberately NOT here. `mail.aws.*` stays in `.env`: an AWS
 * secret key has no business in a table that `/admin/settings` prints on screen.
 *
 * Idempotent per row, so it is safe on a live database and re-runnable.
 */
class SeedMailAndTwoFactorVariables extends Migration
{
    private const VARIABLES = [
        [
            'name'        => 'mail_notify_recipient',
            'value'       => 'admin@xconnecting.com',
            'description' => 'Email address that receives check-in / check-out notifications. One address.',
        ],
        [
            'name'        => 'mail_notify_attendance',
            'value'       => '1',
            'description' => 'Set to 1 to email mail_notify_recipient on every check-in and check-out, 0 to stop. Requires mail.sending_enable and the mail.aws.* credentials in .env.',
        ],
        [
            'name'        => 'security_2fa_name',
            'value'       => 'Office.XCN',
            'description' => 'Label shown beside the 6-digit code in the authenticator app. Only affects new enrolments — phones already set up keep the label they were given.',
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
            // Counted including soft-deleted rows: UNIQUE(name) covers those too,
            // so skipping avoids a duplicate-key insert.
            if ($db->table('site_variable')->where('name', $variable['name'])->countAllResults() > 0) {
                continue;
            }

            $db->table('site_variable')->insert($variable + [
                'type'       => 'string',
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Safe-on-live guard: only touch data on the dedicated tests DB group.
     *
     * Rolling back must not delete the recipient an administrator configured —
     * that is settings loss dressed up as a schema rollback. Same guard the other
     * seed migrations use.
     */
    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('site_variable')->whereIn('name', array_column(self::VARIABLES, 'name'))->delete();
        }
    }
}
