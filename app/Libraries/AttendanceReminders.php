<?php

namespace App\Libraries;

use App\Models\AttendanceDayModel;
use App\Models\LeaveRequestModel;
use App\Models\NotificationModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;

/**
 * The scheduled reminders and manager digests (card 01d). `ScheduledTaskRunner`
 * stays a thin allowlist + dispatcher, as its own docblock demands; the bodies
 * live here so they are testable directly.
 *
 * Two rules run through all of it:
 *
 * 1. **Idempotent under minute-by-minute cron.** OS cron calls `schedule:run`
 *    every minute, so nothing here may depend on being invoked once. The
 *    per-employee reminder is guarded by `attendance_days.checkout_reminded_at`;
 *    the digests rely on the runner's own `interval_minutes` bookkeeping.
 *
 * 2. **Nothing to say means nothing sent.** A daily "all good" notification
 *    trains managers to ignore the channel, so an empty roster sends no message
 *    and reports that plainly in `last_result`.
 *
 * Cutoff times are derived from `work_schedules` rather than configured again —
 * a duplicated "18:15" would drift the first time the office hours change.
 *
 * Delivery is in-app (`NotificationModel`) only. This app manages email templates
 * and a sent-email log but has no SMTP dispatch yet (DEPLOYMENT.md, "Email send
 * pipeline"), so there is deliberately no email flag to switch on.
 */
class AttendanceReminders
{
    /**
     * Minutes after the scheduled end time before nagging someone who is still
     * checked in — enough that they are actually leaving, not just at the printer.
     */
    private const CHECKOUT_GRACE_MINUTES = 15;

    /**
     * Minutes after the scheduled start (plus the schedule's own grace) before
     * the day is considered begun for digest purposes.
     */
    private const DIGEST_DELAY_MINUTES = 30;

    public function __construct(private ?AttendanceCalendar $calendar = null)
    {
        $this->calendar ??= new AttendanceCalendar();
    }

    /**
     * Notify employees who are still checked in past their end time.
     *
     * @param array<string, mixed> $params
     */
    public function remindCheckout(array $params = []): string
    {
        helper(['attendance', 'setting']);

        if (get_site_variable('attendance_checkout_reminder', '1') !== '1') {
            return 'Disabled.';
        }

        $date  = $this->workDate($params);
        $model = model(AttendanceDayModel::class);
        $rows  = $model->needingCheckoutReminder($date);

        if ($rows === []) {
            return "Nobody to remind on {$date}.";
        }

        $now      = $this->now();
        $reminded = [];

        foreach ($rows as $row) {
            $schedule = $this->calendar->scheduleFor((int) $row->user_id);

            if ($schedule === null || ! $this->pastCutoff($now, (string) $schedule->end_time, self::CHECKOUT_GRACE_MINUTES)) {
                continue;
            }

            model(NotificationModel::class)->insert([
                'user_id' => (int) $row->user_id,
                'message' => 'You have not checked out today. Open My Attendance to check out.',
            ]);

            $reminded[] = (int) $row->id;
        }

        if ($reminded === []) {
            return "Too early to remind anyone on {$date}.";
        }

        $model->markCheckoutReminded($reminded, Time::now('UTC')->format('Y-m-d H:i:s'));

        return 'Reminded ' . count($reminded) . ' employee(s) to check out.';
    }

    /**
     * Tell the managers who is absent, late, or missing a check-out today.
     *
     * @param array<string, mixed> $params
     */
    public function absenceDigest(array $params = []): string
    {
        helper(['attendance', 'setting']);

        if (get_site_variable('attendance_absence_digest', '1') !== '1') {
            return 'Disabled.';
        }

        $date   = $this->workDate($params);
        $roster = model(UserModel::class)->activeRoster();

        if ($roster === []) {
            return 'No active employees.';
        }

        $userIds     = array_map(static fn (object $user): int => (int) $user->id, $roster);
        $evaluations = $this->calendar->evaluateSpan($userIds, $date, $date);
        $now         = $this->now();

        $buckets = [
            AttendanceCalendar::STATUS_ABSENT           => [],
            AttendanceCalendar::STATUS_LATE             => [],
            AttendanceCalendar::STATUS_MISSING_CHECKOUT => [],
        ];

        // The cutoff is per employee, because shifts can differ — someone on a
        // 10:00 start is not late at 09:15. An employee whose day has not begun
        // is skipped, not reported.
        $anyDayBegun = false;

        foreach ($roster as $user) {
            $userId   = (int) $user->id;
            $schedule = $this->calendar->scheduleFor($userId);

            if ($schedule === null || ! $this->pastCutoff($now, (string) $schedule->start_time, (int) $schedule->grace_minutes + self::DIGEST_DELAY_MINUTES)) {
                continue;
            }

            $anyDayBegun = true;
            $status      = $evaluations[$userId][$date]->status ?? null;

            if ($status !== null && array_key_exists($status, $buckets)) {
                $buckets[$status][] = (string) ($user->name ?? $user->email);
            }
        }

        if (! $anyDayBegun) {
            return "Too early for a digest on {$date}.";
        }

        $parts = [];

        foreach ($buckets as $status => $names) {
            if ($names !== []) {
                $parts[] = count($names) . ' ' . str_replace('_', ' ', $status) . ': ' . implode(', ', $names);
            }
        }

        if ($parts === []) {
            // Everybody accounted for — say nothing.
            return "Nothing to report on {$date}.";
        }

        $sent = $this->notifyHolders('attendance.manage', "Attendance {$date} — " . implode(' · ', $parts));

        return $sent === 0
            ? 'Nobody holds attendance.manage — digest not sent.'
            : "Digest sent to {$sent} manager(s).";
    }

    /**
     * Weekly nudge about days still flagged as missing a check-out. They block
     * the monthly report, so surfacing them weekly avoids a month-end scramble.
     *
     * @param array<string, mixed> $params
     */
    public function missingCheckoutDigest(array $params = []): string
    {
        helper('attendance');

        $days = max(1, (int) ($params['days'] ?? 7));
        $to   = $this->workDate($params);
        $from = Time::parse($to, attendance_tz())->subDays($days - 1)->toDateString();

        $rows = model(AttendanceDayModel::class)->uncorrectedMissingCheckouts($from, $to);

        if ($rows === []) {
            return "No uncorrected missing check-outs between {$from} and {$to}.";
        }

        $lines = array_map(
            static fn (object $row): string => $row->work_date . ' ' . ($row->user_name ?? 'unknown'),
            array_slice($rows, 0, 20),
        );

        $message = count($rows) . ' day(s) still missing a check-out: ' . implode('; ', $lines);

        if (count($rows) > 20) {
            $message .= ' … and ' . (count($rows) - 20) . ' more.';
        }

        $sent = $this->notifyHolders('attendance.manage', $message);

        return $sent === 0
            ? 'Nobody holds attendance.manage — digest not sent.'
            : 'Reported ' . count($rows) . " day(s) to {$sent} manager(s).";
    }

    /**
     * Nudge approvers about requests still pending after a day. Employees are
     * waiting on an answer; silence is the worst outcome.
     *
     * @param array<string, mixed> $params
     */
    public function pendingRequestsDigest(array $params = []): string
    {
        $hours = max(1, (int) ($params['hours'] ?? 24));
        $model = model(LeaveRequestModel::class);

        $stale = $model->pendingOlderThan($hours);

        if ($stale === []) {
            return "No requests pending longer than {$hours}h.";
        }

        $sent = $this->notifyHolders(
            'requests.approve',
            count($stale) . " leave/late request(s) have been waiting more than {$hours}h for a decision.",
        );

        return $sent === 0
            ? 'Nobody holds requests.approve — digest not sent.'
            : count($stale) . " pending request(s) reported to {$sent} approver(s).";
    }

    /**
     * Notify every active holder of a permission. Returns how many were told, so
     * a handler can report "nobody holds this permission" rather than silently
     * doing nothing.
     */
    private function notifyHolders(string $permission, string $message): int
    {
        $holders = model(UserModel::class)->withPermission($permission);

        foreach ($holders as $holder) {
            model(NotificationModel::class)->insert([
                'user_id' => (int) $holder->id,
                'message' => $message,
            ]);
        }

        return count($holders);
    }

    /**
     * Whether the Vietnam clock has passed `$time` plus `$offsetMinutes`.
     */
    private function pastCutoff(Time $now, string $time, int $offsetMinutes): bool
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        $cutoff  = $hours * 60 + $minutes + $offsetMinutes;
        $nowMins = ((int) $now->format('G')) * 60 + ((int) $now->format('i'));

        return $nowMins >= $cutoff;
    }

    /**
     * `params.date` for a manual re-run, else today (Vietnam).
     *
     * @param array<string, mixed> $params
     */
    private function workDate(array $params): string
    {
        $date = trim((string) ($params['date'] ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : attendance_today();
    }

    private function now(): Time
    {
        return Time::now(attendance_tz());
    }
}
