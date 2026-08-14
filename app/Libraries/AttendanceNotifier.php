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
 *   mail_notify_attendance        master switch: 0 means nobody is mailed
 *   mail_notify_employee          also send the employee their own receipt
 *
 * Two audiences, two templates. The administrator's copy reports *about* somebody;
 * the employee's is a receipt addressed *to* them. One template with conditionals
 * would read oddly to whichever side was not the primary reader.
 *
 * The employee copy has its own switch because it doubles the messages SES sends,
 * and because a quiet week for the admin is not the same decision as a quiet week
 * for everyone.
 *
 * Credentials and the master send switch stay in `.env` (`Config\Mail`). That
 * split is deliberate: an AWS secret key does not belong in a database table that
 * the admin UI prints on screen.
 *
 * ## Never breaks the check-in
 *
 * `notify()` cannot throw — see `MailNotifier`, which owns that rule and the
 * administrator's address for every notifier.
 *
 * Note this sends inline, on the employee's request. One SES call is fast, but if
 * the office grows to where that latency is felt, move it behind the existing
 * `scheduled_tasks` runner rather than making the controller wait.
 */
class AttendanceNotifier extends MailNotifier
{
    /**
     * Master switch. Off means nobody is mailed, admin or employee.
     */
    public const SETTING_ENABLED = 'mail_notify_attendance';

    /**
     * Whether the employee also gets their own receipt. Separate from the master
     * switch because it doubles the number of messages SES sends.
     */
    public const SETTING_EMPLOYEE_COPY = 'mail_notify_employee';

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

            $facts = [
                'event'      => $event,
                'isCheckin'  => $isCheckin,
                'employee'   => $employee,
                'name'       => $employee,
                'email'      => (string) ($user->email ?? ''),
                'workDate'   => (string) ($day->work_date ?? ''),
                'checkinAt'  => attendance_local_time($day->checkin_at ?? null),
                'checkoutAt' => attendance_local_time($day->checkout_at ?? null),
                'at'         => attendance_local_time($isCheckin ? ($day->checkin_at ?? null) : ($day->checkout_at ?? null)),
                'timezone'   => attendance_tz(),
            ];

            // Dispatched separately, each guarded, so a broken template or a
            // refused address on one side cannot suppress the other.
            $this->dispatch(
                'admin',
                $this->recipient(),
                sprintf('[%s] %s %s at %s', site_name(), $employee, $isCheckin ? 'checked in' : 'checked out', $facts['at']),
                'emails/attendance_event',
                $facts,
                'attendance.' . $event,
            );

            if ($this->employeeCopyEnabled()) {
                $this->dispatch(
                    'employee',
                    $facts['email'],
                    sprintf('%s %s at %s', site_name(), $isCheckin ? '— checked in' : '— checked out', $facts['at']),
                    'emails/attendance_event_employee',
                    $facts,
                    'attendance.' . $event . '.employee',
                );
            }
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
        return $this->switchedOn(self::SETTING_ENABLED);
    }

    /**
     * Does the employee get their own receipt?
     *
     * Defaults to on — it was asked for, and somebody who just tapped a button is
     * the one person entitled to a record of it. Turn it off if the SES volume or
     * the inbox noise stops being worth it.
     */
    public function employeeCopyEnabled(): bool
    {
        return $this->switchedOn(self::SETTING_EMPLOYEE_COPY);
    }
}
