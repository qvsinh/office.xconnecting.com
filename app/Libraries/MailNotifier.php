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
     * The administrator address(es), admin-editable on `/admin/settings`.
     * Comma-separated for an office where more than one person watches this.
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

    /**
     * Every administrator address the setting names, in the order written.
     *
     * The setting is one free-text field, so it is parsed defensively: split on
     * commas, trim each part (`a@x , b@y` is what people actually type), drop the
     * empties a trailing comma leaves behind, and drop duplicates so nobody is
     * mailed twice for one event. Falls back to the built-in address when the
     * setting is blank — better that the mail lands somewhere real than nowhere.
     *
     * Addresses are *not* validated here: `Mailer` refuses an undeliverable one
     * and records the attempt, which is how a typo becomes visible on
     * `/admin/emails` instead of vanishing.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        helper('setting');

        $addresses = array_filter(
            array_map('trim', explode(',', (string) get_site_variable(static::SETTING_RECIPIENT, static::DEFAULT_RECIPIENT))),
            static fn (string $address): bool => $address !== '',
        );

        $addresses = array_values(array_unique($addresses));

        return $addresses === [] ? [static::DEFAULT_RECIPIENT] : $addresses;
    }

    /**
     * The first administrator address — for the places that can only mean one,
     * such as matching an emailed decision back to an account.
     */
    public function recipient(): string
    {
        return $this->recipients()[0];
    }

    /**
     * Send one message to every administrator address.
     *
     * One send per address rather than one message with several recipients: each
     * gets its own row in `emails`, so a typo in the third address is visible and
     * cannot suppress the first two.
     *
     * @param array<string, mixed> $facts
     */
    protected function dispatchToAdmins(string $subject, string $template, array $facts, string $label): void
    {
        foreach ($this->recipients() as $address) {
            $this->dispatch('admin', $address, $subject, $template, $facts, $label);
        }
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
