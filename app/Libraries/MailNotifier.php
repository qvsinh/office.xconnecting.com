<?php

namespace App\Libraries;

use Throwable;

/**
 * Shared mechanics for the notifiers that mail the office about something that
 * just happened (`AttendanceNotifier`, `RequestNotifier`).
 *
 * What they have in common, and why it lives in one place:
 *
 *  - **The administrator's address.** One setting, `mail_notify_admin_recipient`,
 *    so an office that changes where its notifications land changes it once.
 *  - **Never break the action.** An employee tapping "Check in" at 08:29 or
 *    submitting a leave request must not see an error because SES was slow, so
 *    each send is wrapped and failures end up in the log and the `emails` table.
 *  - **Every message dispatched separately.** When one event mails two audiences,
 *    a broken template or a refused address on one side must not suppress the
 *    other.
 *
 * What stays in the subclasses: which settings switch them on, who the audiences
 * are, and the facts each template needs. Those genuinely differ.
 */
abstract class MailNotifier
{
    /**
     * The administrator's address, admin-editable on `/admin/settings`.
     */
    public const SETTING_RECIPIENT = 'mail_notify_admin_recipient';

    /**
     * Fallback if the setting was deleted outright — better that the mail lands
     * somewhere real than nowhere.
     */
    public const DEFAULT_RECIPIENT = 'admin@xconnecting.com';

    protected Mailer $mailer;

    public function __construct(?Mailer $mailer = null)
    {
        $this->mailer = $mailer ?? new Mailer();
    }

    public function recipient(): string
    {
        helper('setting');

        $value = trim((string) get_site_variable(static::SETTING_RECIPIENT, static::DEFAULT_RECIPIENT));

        return $value === '' ? static::DEFAULT_RECIPIENT : $value;
    }

    /**
     * Render and hand one message to the mailer, swallowing anything that goes
     * wrong so a sibling send still happens and the caller never sees an
     * exception.
     *
     * @param array<string, mixed> $facts
     */
    protected function dispatch(string $audience, string $to, string $subject, string $template, array $facts, string $label): void
    {
        try {
            $this->mailer->send($to, $subject, view($template, $facts), $label);
        } catch (Throwable $e) {
            log_message('error', 'Notification to {audience} failed ({label}): {msg}', [
                'audience' => $audience,
                'label'    => $label,
                'msg'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * An admin-editable on/off switch, defaulting to **on**: the point of these
     * features is that people hear about things, so a missing row must not mean
     * silence.
     */
    protected function switchedOn(string $setting): bool
    {
        helper('setting');

        return filter_var(get_site_variable($setting, '1'), FILTER_VALIDATE_BOOLEAN);
    }
}
