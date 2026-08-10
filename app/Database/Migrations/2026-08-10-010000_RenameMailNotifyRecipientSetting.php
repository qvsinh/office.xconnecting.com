<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Renames the `mail_notify_recipient` setting to `mail_notify_admin_recipient`.
 *
 * The old name said *that* there was a recipient but not *whose* address it was,
 * which reads as "the person this notification is about" — the employee who
 * checked in. It is the administrator's address: attendance mail goes to exactly
 * one place, and the employee is never sent a copy.
 *
 * The stored value is carried across, not re-defaulted. Renaming a setting must
 * never quietly reset an address somebody configured.
 *
 * Handles every starting state so it is safe on a live database, a fresh install
 * and a re-run:
 *
 *  - old row present, new absent -> rename in place, value intact
 *  - both present                -> keep the new one, drop the stale old row
 *  - neither present             -> insert with the default
 */
class RenameMailNotifyRecipientSetting extends Migration
{
    private const OLD_NAME = 'mail_notify_recipient';

    private const NEW_NAME = 'mail_notify_admin_recipient';

    private const DESCRIPTION = 'Administrator address that receives check-in / check-out notifications. One address. The employee who checked in is never sent a copy.';

    private const DEFAULT_VALUE = 'admin@xconnecting.com';

    /**
     * The sibling switch's description names this setting, so it goes stale with
     * the rename. Editing the original seed migration would only help a fresh
     * install — the row already exists on every database that ran it.
     */
    private const SIBLING = 'mail_notify_attendance';

    private const SIBLING_DESCRIPTION = 'Set to 1 to email mail_notify_admin_recipient on every check-in and check-out, 0 to stop. Requires mail.sending_enable and the mail.aws.* credentials in .env.';

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        $db->table('site_variable')
            ->where('name', self::SIBLING)
            ->update(['description' => self::SIBLING_DESCRIPTION]);

        $table = $db->table('site_variable');

        $hasNew = $table->where('name', self::NEW_NAME)->countAllResults() > 0;
        $old    = $db->table('site_variable')->where('name', self::OLD_NAME)->get()->getRow();

        if ($hasNew) {
            // Already renamed. Clear any leftover old row so the settings screen
            // does not list two knobs for one behaviour.
            if ($old !== null) {
                $db->table('site_variable')->where('name', self::OLD_NAME)->delete();
            }

            return;
        }

        if ($old !== null) {
            $db->table('site_variable')
                ->where('name', self::OLD_NAME)
                ->update(['name' => self::NEW_NAME, 'description' => self::DESCRIPTION]);

            return;
        }

        $db->table('site_variable')->insert([
            'name'        => self::NEW_NAME,
            'value'       => self::DEFAULT_VALUE,
            'description' => self::DESCRIPTION,
            'type'        => 'string',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Reverses the rename, again preserving whatever value is stored.
     */
    public function down(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        if ($db->table('site_variable')->where('name', self::OLD_NAME)->countAllResults() > 0) {
            return;
        }

        $db->table('site_variable')
            ->where('name', self::NEW_NAME)
            ->update([
                'name'        => self::OLD_NAME,
                'description' => 'Email address that receives check-in / check-out notifications. One address.',
            ]);
    }
}
