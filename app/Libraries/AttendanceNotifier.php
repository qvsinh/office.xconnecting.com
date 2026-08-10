<?php

namespace App\Libraries;

use App\Models\AccessLogModel;
use App\Models\UserModel;
use Throwable;

/**
 * Emails the office administrator when somebody checks in or out.
 *
 * Who receives it and whether it happens at all are admin-editable settings
 * (`/admin/settings`), not constants — so nobody needs a deploy to change the
 * recipient or to stop the mail during a noisy week:
 *
 *   mail_notify_admin_recipient   the administrator's address
 *   mail_notify_attendance        1 = send, 0 = do not
 *
 * **One recipient, and it is the administrator.** The employee who checked in is
 * never sent a copy — the earlier name (`mail_notify_recipient`) did not say whose
 * address it held, which reads as "the person this is about".
 *
 * Credentials and the master send switch stay in `.env` (`Config\Mail`). That
 * split is deliberate: an AWS secret key does not belong in a database table that
 * the admin UI prints on screen.
 *
 * ## Never breaks the check-in
 *
 * `notify()` cannot throw. An employee tapping "Check in" at 08:29 must not see
 * an error because SES was slow, so every failure ends up in the log and the
 * request carries on. Same rule as `AccessLogger`.
 *
 * Note this sends inline, on the employee's request. One SES call is fast, but if
 * the office grows to where that latency is felt, move it behind the existing
 * `scheduled_tasks` runner rather than making the controller wait.
 */
class AttendanceNotifier
{
    public const SETTING_RECIPIENT = 'mail_notify_admin_recipient';
    public const SETTING_ENABLED   = 'mail_notify_attendance';

    /**
     * Fallback recipient if the setting was deleted outright — better that the
     * mail lands somewhere real than nowhere.
     */
    public const DEFAULT_RECIPIENT = 'admin@xconnecting.com';

    private Mailer $mailer;

    public function __construct(?Mailer $mailer = null)
    {
        $this->mailer = $mailer ?? new Mailer();
    }

    /**
     * The employee is looked up by id rather than passed in, so this works the
     * same from a controller, a command or a scheduled task — nothing here
     * depends on the session.
     *
     * @param string $event  AccessLogModel::EVENT_CHECKIN | EVENT_CHECKOUT
     * @param int    $userId the employee who recorded it
     * @param object $day    the attendance_days row, for the recorded times
     */
    public function notify(string $event, int $userId, object $day): void
    {
        try {
            if (! in_array($event, [AccessLogModel::EVENT_CHECKIN, AccessLogModel::EVENT_CHECKOUT], true)) {
                return;
            }

            if (! $this->enabled()) {
                return;
            }

            $user = model(UserModel::class)->findUser($userId);

            if ($user === null) {
                return;
            }

            helper(['setting', 'attendance']);

            $isCheckin = $event === AccessLogModel::EVENT_CHECKIN;
            $employee  = trim((string) ($user->name ?? '')) ?: (string) ($user->email ?? 'Unknown employee');
            $at        = attendance_local_time($isCheckin ? ($day->checkin_at ?? null) : ($day->checkout_at ?? null));

            $subject = sprintf(
                '[%s] %s %s at %s',
                site_name(),
                $employee,
                $isCheckin ? 'checked in' : 'checked out',
                $at,
            );

            $body = view('emails/attendance_event', [
                'event'      => $event,
                'isCheckin'  => $isCheckin,
                'employee'   => $employee,
                'email'      => (string) ($user->email ?? ''),
                'workDate'   => (string) ($day->work_date ?? ''),
                'checkinAt'  => attendance_local_time($day->checkin_at ?? null),
                'checkoutAt' => attendance_local_time($day->checkout_at ?? null),
                'at'         => $at,
                'timezone'   => attendance_tz(),
            ]);

            $this->mailer->send($this->recipient(), $subject, $body, 'attendance.' . $event);
        } catch (Throwable $e) {
            log_message('error', 'Attendance notification failed ({event}): {msg}', [
                'event' => $event,
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin-editable on/off. Defaults to on: the point of the feature is that the
     * administrator hears about attendance, so a missing setting should not mean
     * silence.
     */
    public function enabled(): bool
    {
        helper('setting');

        return filter_var(get_site_variable(self::SETTING_ENABLED, '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function recipient(): string
    {
        helper('setting');

        $value = trim((string) get_site_variable(self::SETTING_RECIPIENT, self::DEFAULT_RECIPIENT));

        return $value === '' ? self::DEFAULT_RECIPIENT : $value;
    }
}
