<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Corrects two `site_variable` descriptions that the employee-copy feature made
 * untrue.
 *
 * `mail_notify_admin_recipient` said "The employee who checked in is never sent a
 * copy" — true when it was written, the opposite of true now that
 * `mail_notify_employee` exists. These strings are what an administrator reads on
 * `/admin/settings`, so a stale one is a wrong answer in the UI, not a comment.
 *
 * A separate migration rather than an edit to the seeds that wrote them: those
 * already ran everywhere, so editing them would only ever help a fresh install.
 *
 * Writes descriptions only, never values — an administrator's choices are not this
 * migration's business.
 */
class FixMailNotifyDescriptions extends Migration
{
    /**
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'mail_notify_admin_recipient' => 'Administrator address that receives the check-in / check-out report. One address. Whether the employee also gets their own receipt is mail_notify_employee.',
        'mail_notify_attendance'      => 'Master switch for check-in / check-out mail: 1 to send, 0 to stop — 0 means nobody is mailed. Who receives what: mail_notify_admin_recipient (the report) and mail_notify_employee (the employee receipt). Requires mail.sending_enable and the mail.aws.* credentials in .env.',
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        foreach (self::DESCRIPTIONS as $name => $description) {
            $db->table('site_variable')
                ->where('name', $name)
                ->update(['description' => $description]);
        }
    }

    /**
     * Nothing to undo: reinstating a description that is factually wrong would be
     * the only thing to do here, and it would help nobody.
     */
    public function down(): void
    {
    }
}
