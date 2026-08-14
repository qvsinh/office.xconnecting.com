<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `mail_notify_admin_recipient` now accepts several addresses, comma-separated.
 *
 * Only the description changes — the stored value is somebody's configuration and
 * is none of this migration's business. The description is what an administrator
 * reads on `/admin/settings`, and it still said "One address.", which is now the
 * opposite of true.
 *
 * A separate migration rather than an edit to the seed that wrote it: that one has
 * already run everywhere, so editing it would only ever help a fresh install.
 */
class AllowSeveralAdminMailRecipients extends Migration
{
    private const NAME = 'mail_notify_admin_recipient';

    private const DESCRIPTION = 'Administrator address(es) that receive attendance reports and leave-request mail. Several are allowed, separated by commas (spaces around them are ignored) — each address gets its own copy. Whether the employee also gets their own attendance receipt is mail_notify_employee.';

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        $db->table('site_variable')
            ->where('name', self::NAME)
            ->update(['description' => self::DESCRIPTION]);
    }

    /**
     * Nothing to undo: reinstating a description that is factually wrong would
     * help nobody.
     */
    public function down(): void
    {
    }
}
