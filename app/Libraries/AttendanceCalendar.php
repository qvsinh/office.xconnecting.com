<?php

namespace App\Libraries;

use App\Models\AttendanceDayModel;
use App\Models\HolidayModel;
use App\Models\LeaveRequestModel;
use App\Models\UserModel;
use App\Models\WorkScheduleModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Database;

/**
 * Turns raw attendance facts (card 01) into the numbers a manager reads: day
 * status, lateness, worked/expected/overtime hours — measured against the
 * employee's `work_schedules` row and the company `holidays` list (card 01b).
 *
 * This is the ONLY place these values are computed. The employee page, the
 * management table, the today board and the monthly report/CSV (card 01c) all
 * call in here — three copies of "am I late" is how payroll disputes start.
 *
 * Two deliberate design choices:
 *
 * 1. **Derived, never stored.** Approving leave retroactively, or correcting a
 *    time, changes the day's status on the next page load. The protection
 *    against a *paid* month being rewritten is the payroll lock from card 01
 *    (`attendance_locked_through`), not frozen columns.
 *
 * 2. **Whole-minute clock arithmetic for lateness.** 08:30:30 is not late when
 *    the day starts at 08:30 — humans read a clock to the minute, and a
 *    seconds-based comparison would flag arrivals nobody considers late.
 *    Worked/overtime time, by contrast, uses real second-level timestamps.
 */
class AttendanceCalendar
{
    public const STATUS_HOLIDAY          = 'holiday';
    public const STATUS_NON_WORKDAY      = 'non_workday';
    public const STATUS_ON_LEAVE         = 'on_leave';
    public const STATUS_ABSENT           = 'absent';
    public const STATUS_MISSING_CHECKOUT = 'missing_checkout';
    public const STATUS_LATE             = 'late';
    public const STATUS_PRESENT          = 'present';

    /**
     * Today only: a workday with no check-in yet, but the start time has not
     * passed. Nobody is absent at 08:00 — see `withPendingArrival()`.
     */
    public const STATUS_NOT_YET = 'not_yet';

    /**
     * Per-request schedule cache, keyed by schedule id, plus the resolved
     * default. Employees overwhelmingly share one schedule, so a report over 50
     * people does one or two schedule queries, not 50.
     *
     * @var array<int, object>
     */
    private array $schedules = [];

    private ?object $defaultSchedule = null;

    /**
     * Per-request holiday cache: one flat `date => name` map plus the ranges
     * already loaded into it.
     *
     * Keyed by date rather than by range on purpose — an earlier version cached
     * per `from|to` string, so a range load and a single-date lookup missed each
     * other's cache and a 7-day range cost 7 extra queries.
     *
     * @var array<string, string>
     */
    private array $holidays = [];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $loadedRanges = [];

    /**
     * Per-request schedule-per-employee cache, so repeated `evaluate()` calls
     * for the same person don't re-read the user row.
     *
     * @var array<int, object|null>
     */
    private array $userSchedules = [];

    private ?BaseConnection $db = null;

    /**
     * The schedule that applies to an employee: their own `work_schedule_id`,
     * else the company default.
     */
    public function scheduleFor(int $userId): ?object
    {
        if (array_key_exists($userId, $this->userSchedules)) {
            return $this->userSchedules[$userId];
        }

        $user       = model(UserModel::class)->find($userId);
        $scheduleId = $user === null ? null : ($user->work_schedule_id ?? null);

        if ($scheduleId === null) {
            return $this->userSchedules[$userId] = $this->defaultScheduleCached();
        }

        $scheduleId = (int) $scheduleId;

        if (! isset($this->schedules[$scheduleId])) {
            $schedule = model(WorkScheduleModel::class)->findSchedule($scheduleId);

            // A deleted schedule must not blank out a whole employee's history.
            if ($schedule === null) {
                return $this->userSchedules[$userId] = $this->defaultScheduleCached();
            }

            $this->schedules[$scheduleId] = $schedule;
        }

        return $this->userSchedules[$userId] = $this->schedules[$scheduleId];
    }

    /**
     * Resolve the schedule for many employees in a fixed number of queries: one
     * over `users`, one over `work_schedules`, plus the default lookup.
     *
     * Without this, `scheduleFor()` reads one `users` row per employee and a
     * 20-person report costs 20 extra queries — the N+1 `evaluateMonth()` exists
     * to avoid. A test asserts the query count does not grow with the roster.
     *
     * @param list<int> $userIds
     */
    public function warmSchedules(array $userIds): void
    {
        $pending = array_values(array_filter($userIds, fn (int $id): bool => ! array_key_exists($id, $this->userSchedules)));

        if ($pending === []) {
            return;
        }

        $userScheduleIds = model(UserModel::class)->scheduleAssignments($pending);

        $wanted = array_values(array_unique(array_filter(
            $userScheduleIds,
            fn (?int $scheduleId): bool => $scheduleId !== null && ! isset($this->schedules[$scheduleId]),
        )));

        $this->schedules += model(WorkScheduleModel::class)->findManyById($wanted);

        foreach ($pending as $userId) {
            $scheduleId = $userScheduleIds[$userId] ?? null;

            // Unknown user, no assignment, or an assignment pointing at a
            // deleted schedule all fall back to the company default.
            $this->userSchedules[$userId] = $scheduleId !== null && isset($this->schedules[$scheduleId])
                ? $this->schedules[$scheduleId]
                : $this->defaultScheduleCached();
        }
    }

    /**
     * Whether the weekday of `$date` is a working day under `$schedule`.
     */
    public function isWorkday(string $date, object $schedule): bool
    {
        $isoDay = (int) Time::parse($date, attendance_tz())->format('N'); // 1 = Monday

        return in_array($isoDay, $this->workdayNumbers($schedule), true);
    }

    /**
     * @return list<int>
     */
    public function workdayNumbers(object $schedule): array
    {
        $parts = array_filter(array_map('trim', explode(',', (string) ($schedule->workdays ?? ''))), static fn (string $p): bool => $p !== '');

        return array_values(array_unique(array_map('intval', $parts)));
    }

    public function holidayName(string $date): ?string
    {
        $this->loadHolidays($date, $date);

        return $this->holidays[$date] ?? null;
    }

    /**
     * Warm the holiday cache for a whole range in one query. Idempotent: a range
     * already covered by a previous load is a no-op, which is what keeps a
     * monthly report at one holiday query rather than one per day.
     */
    public function loadHolidays(string $from, string $to): void
    {
        foreach ($this->loadedRanges as [$loadedFrom, $loadedTo]) {
            if ($from >= $loadedFrom && $to <= $loadedTo) {
                return;
            }
        }

        $this->holidays       = model(HolidayModel::class)->mapForRange($from, $to) + $this->holidays;
        $this->loadedRanges[] = [$from, $to];
    }

    /**
     * Evaluate one (employee, date).
     *
     * The row and the approved request are passed IN rather than queried here,
     * so a monthly report can load a whole month in a handful of queries and the
     * unit tests need no database at all.
     *
     * `$approvedRequest` is the card-02 contract: an object with `type`
     * (`leave`|`late`) and `day_portion` (`full`|`morning`|`afternoon`). Null
     * until card 02 ships, which is why this card is shippable on its own.
     *
     * @return object{
     *     status: string, holiday_name: string|null,
     *     late_minutes: int, late_excused: bool, early_leave_minutes: int,
     *     worked_seconds: int|null, expected_seconds: int, overtime_seconds: int
     * }
     */
    public function evaluate(int $userId, string $date, ?object $row, ?object $approvedRequest = null, ?object $schedule = null): object
    {
        helper('attendance');

        $schedule ??= $this->scheduleFor($userId);

        if ($schedule === null) {
            // No schedule configured at all: report the facts, judge nothing.
            return $this->result(
                $row === null ? self::STATUS_ABSENT : self::STATUS_PRESENT,
                workedSeconds: attendance_worked_seconds($row->checkin_at ?? null, $row->checkout_at ?? null),
            );
        }

        $holidayName = $this->holidayName($date);
        $isWorkday   = $this->isWorkday($date, $schedule);

        $checkinLocal  = attendance_local($row->checkin_at ?? null);
        $checkoutLocal = attendance_local($row->checkout_at ?? null);

        $workedSeconds = $this->workedSeconds($row, $checkinLocal, $checkoutLocal, $date, $schedule);

        $leave = $approvedRequest !== null && ($approvedRequest->type ?? '') === 'leave' ? $approvedRequest : null;
        $late  = $approvedRequest !== null && ($approvedRequest->type ?? '') === 'late' ? $approvedRequest : null;

        $expectedSeconds = $this->expectedSeconds($schedule, $isWorkday, $holidayName !== null, $leave);
        $overtime        = $workedSeconds === null ? 0 : max(0, $workedSeconds - $expectedSeconds);

        // Lateness/early-leave only mean something on a day the employee was
        // expected in at a particular time.
        $lateMinutes      = 0;
        $earlyLeaveMinute = 0;

        if ($isWorkday && $holidayName === null && $checkinLocal !== null) {
            $lateMinutes = $this->lateMinutes($checkinLocal, $schedule);

            if ($checkoutLocal !== null) {
                $earlyLeaveMinute = $this->earlyLeaveMinutes($checkoutLocal, $schedule);
            }
        }

        $flaggedLate = $lateMinutes > 0 && $this->isLate($lateMinutes, $schedule);

        // An approved late-arrival request is the excuse — the day reads present.
        $lateExcused = $late !== null && $flaggedLate;

        if ($lateExcused) {
            $lateMinutes = 0;
            $flaggedLate = false;
        }

        return $this->result(
            $this->resolveStatus($row, $holidayName, $isWorkday, $leave, $flaggedLate),
            holidayName: $holidayName,
            lateMinutes: $lateMinutes,
            lateExcused: $lateExcused,
            earlyLeaveMinutes: $earlyLeaveMinute,
            workedSeconds: $workedSeconds,
            expectedSeconds: $expectedSeconds,
            overtimeSeconds: $overtime,
        );
    }

    /**
     * Every day between `$from` and `$to` for one employee, keyed by `Y-m-d`.
     * Three queries total regardless of the range length.
     *
     * @return array<string, object>
     */
    public function evaluateRange(int $userId, string $from, string $to): array
    {
        // One employee is just a one-element span — keeping a single
        // implementation means the request/holiday/schedule loading can't drift
        // between the two entry points.
        return $this->evaluateSpan([$userId], $from, $to)[$userId] ?? [];
    }

    /**
     * Soften today's `absent` into `not_yet` while the start time (plus grace)
     * has not passed yet.
     *
     * Only ever applies to the current Vietnam date: a past day with no check-in
     * really is an absence, and marking the whole roster red at 08:00 would train
     * managers to ignore the colour. Lives here rather than in the today board so
     * the rule is testable and stated once.
     */
    public function withPendingArrival(int $userId, string $date, object $evaluation, ?Time $now = null): object
    {
        helper('attendance');

        if ($evaluation->status !== self::STATUS_ABSENT || $date !== attendance_today()) {
            return $evaluation;
        }

        $schedule = $this->scheduleFor($userId);

        if ($schedule === null) {
            return $evaluation;
        }

        $now ??= Time::now(attendance_tz());
        $cutoff = $this->minuteOfDay((string) ($schedule->start_time ?? '08:30:00')) + (int) ($schedule->grace_minutes ?? 0);

        if ($this->minuteOfDayFromTime($now) > $cutoff) {
            return $evaluation;
        }

        $pending         = clone $evaluation;
        $pending->status = self::STATUS_NOT_YET;

        return $pending;
    }

    /**
     * Evaluate a whole month for a set of employees — the monthly report's and
     * today board's loader.
     *
     * **Three queries total, regardless of how many employees or days**: one for
     * the attendance rows, one for the holidays, one (at most two) for the
     * schedules. Never call `evaluateRange()` in a loop over employees; that is
     * the N+1 this method exists to prevent, and a test asserts the query count
     * does not grow with the roster size.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array<string, object>> `[userId => [date => evaluation]]`
     */
    public function evaluateMonth(array $userIds, string $month): array
    {
        return $this->evaluateSpan($userIds, $month . '-01', Time::parse($month . '-01', attendance_tz())->addMonths(1)->subDays(1)->toDateString());
    }

    /**
     * `evaluateMonth()` over an arbitrary date span — the today board passes a
     * single day, the report a month.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array<string, object>>
     */
    public function evaluateSpan(array $userIds, string $from, string $to): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = model(AttendanceDayModel::class)->forUsersInRange($userIds, $from, $to);
        $this->loadHolidays($from, $to);

        $byUserDate = [];

        foreach ($rows as $row) {
            $byUserDate[(int) $row->user_id][(string) $row->work_date] = $row;
        }

        // Resolve every schedule up front, in bulk, so neither this loop nor the
        // per-day loop below touches a query.
        $this->warmSchedules($userIds);

        $schedules = [];

        foreach ($userIds as $userId) {
            $schedules[$userId] = $this->scheduleFor($userId);
        }

        $dates  = [];
        $cursor = Time::parse($from, attendance_tz());
        $end    = Time::parse($to, attendance_tz());

        while ($cursor->toDateString() <= $end->toDateString()) {
            $dates[] = $cursor->toDateString();
            $cursor  = $cursor->addDays(1);
        }

        // Approved leave / late requests for the whole span in one query. Without
        // this the sheet would mark an employee absent on a day the company
        // approved off — the integration is the point of card 02, not an extra.
        $requests = $this->approvedRequestMap($userIds, $from, $to);

        $out = [];

        foreach ($userIds as $userId) {
            foreach ($dates as $date) {
                $out[$userId][$date] = $this->evaluate(
                    $userId,
                    $date,
                    $byUserDate[$userId][$date] ?? null,
                    $requests[$userId][$date] ?? null,
                    $schedules[$userId],
                );
            }
        }

        return $out;
    }

    /**
     * Expand approved requests into `[userId][date] => request`.
     *
     * A `leave` beats a `late` on the same date — being off beats arriving late.
     * `leave_requests` is optional at runtime (the table only exists once card 02
     * has migrated), so a missing table degrades to "nobody has requested
     * anything" rather than breaking every attendance screen.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array<string, object>>
     */
    private function approvedRequestMap(array $userIds, string $from, string $to): array
    {
        if (! $this->db()->tableExists('leave_requests')) {
            return [];
        }

        $map = [];

        foreach (model(LeaveRequestModel::class)->approvedForUsersInRange($userIds, $from, $to) as $request) {
            $userId = (int) $request->user_id;
            $cursor = Time::parse(max((string) $request->start_date, $from), attendance_tz());
            $end    = Time::parse(min((string) ($request->end_date ?? $request->start_date), $to), attendance_tz());

            while ($cursor->toDateString() <= $end->toDateString()) {
                $date     = $cursor->toDateString();
                $existing = $map[$userId][$date] ?? null;

                if ($existing === null || ($existing->type !== LeaveRequestModel::TYPE_LEAVE && $request->type === LeaveRequestModel::TYPE_LEAVE)) {
                    $map[$userId][$date] = $request;
                }

                $cursor = $cursor->addDays(1);
            }
        }

        return $map;
    }

    /**
     * Cached DB handle for the `tableExists()` guard above.
     */
    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /**
     * Roll a month's per-day evaluations up into the figures the report shows.
     *
     * `absent` deliberately counts only days already past: an employee is not
     * absent for the rest of the month, and counting future workdays as absence
     * would make every mid-month report look alarming.
     *
     * @param array<string, object> $days
     *
     * @return object{
     *     workdays: int, present: int, late: int, late_minutes: int,
     *     early_leave: int, early_leave_minutes: int, absent: int, on_leave: int,
     *     missing_checkout: int, worked_seconds: int, expected_seconds: int,
     *     overtime_seconds: int
     * }
     */
    public function summarise(array $days, ?string $today = null): object
    {
        helper('attendance');
        $today ??= attendance_today();

        $totals = [
            'workdays'       => 0, 'present' => 0, 'late' => 0, 'late_minutes' => 0,
            'early_leave'    => 0, 'early_leave_minutes' => 0, 'absent' => 0,
            'on_leave'       => 0, 'missing_checkout' => 0,
            'worked_seconds' => 0, 'expected_seconds' => 0, 'overtime_seconds' => 0,
        ];

        foreach ($days as $date => $day) {
            $isCountableWorkday = ! in_array($day->status, [self::STATUS_HOLIDAY, self::STATUS_NON_WORKDAY], true);

            if ($isCountableWorkday) {
                $totals['workdays']++;
                $totals['expected_seconds'] += $day->expected_seconds;
            }

            $totals['worked_seconds'] += (int) ($day->worked_seconds ?? 0);
            $totals['overtime_seconds'] += $day->overtime_seconds;
            $totals['late_minutes'] += $day->late_minutes;
            $totals['early_leave_minutes'] += $day->early_leave_minutes;

            if ($day->early_leave_minutes > 0) {
                $totals['early_leave']++;
            }

            match ($day->status) {
                self::STATUS_PRESENT          => $totals['present']++,
                self::STATUS_LATE             => $totals['late']++,
                self::STATUS_ON_LEAVE         => $totals['on_leave']++,
                self::STATUS_MISSING_CHECKOUT => $totals['missing_checkout']++,
                // Only days that have actually happened can be absences.
                self::STATUS_ABSENT => (string) $date <= $today ? $totals['absent']++ : null,
                default             => null,
            };
        }

        return (object) $totals;
    }

    /**
     * Worked seconds minus the overlap with the schedule's lunch window.
     *
     * Interval overlap, not a flat deduction: an employee who leaves at 11:00
     * never took lunch and must not be docked 90 minutes for it, and an
     * afternoon-only day (13:30 onwards) has no overlap either.
     */
    private function workedSeconds(?object $row, ?Time $checkinLocal, ?Time $checkoutLocal, string $date, object $schedule): ?int
    {
        if ($row === null || $checkinLocal === null || $checkoutLocal === null) {
            return null;
        }

        $gross = attendance_worked_seconds($row->checkin_at, $row->checkout_at);

        if ($gross === null || $gross === 0) {
            return $gross;
        }

        $breakMinutes = (int) ($schedule->break_minutes ?? 0);

        if ($breakMinutes <= 0) {
            return $gross;
        }

        $breakStart = Time::parse($date . ' ' . $this->timeOfDay($schedule->break_start ?? '12:00:00'), attendance_tz());
        $breakEnd   = $breakStart->addMinutes($breakMinutes);

        $overlapStart = max($checkinLocal->getTimestamp(), $breakStart->getTimestamp());
        $overlapEnd   = min($checkoutLocal->getTimestamp(), $breakEnd->getTimestamp());
        $overlap      = max(0, $overlapEnd - $overlapStart);

        return max(0, $gross - $overlap);
    }

    /**
     * Paid time the schedule expects: on-site span minus the whole lunch break.
     * 08:30–18:00 with a 90-minute break = 8h00. Zero on a holiday, a
     * non-working weekday, or a full day of approved leave; halved for a
     * half-day leave.
     */
    private function expectedSeconds(object $schedule, bool $isWorkday, bool $isHoliday, ?object $leave): int
    {
        if (! $isWorkday || $isHoliday) {
            return 0;
        }

        $span = $this->minuteOfDay((string) ($schedule->end_time ?? '18:00:00'))
            - $this->minuteOfDay((string) ($schedule->start_time ?? '08:30:00'));

        $expected = max(0, ($span - (int) ($schedule->break_minutes ?? 0)) * 60);

        if ($leave === null) {
            return $expected;
        }

        return ($leave->day_portion ?? 'full') === 'full' ? 0 : intdiv($expected, 2);
    }

    /**
     * Minutes after the schedule's official start time.
     *
     * `grace_minutes` decides only *whether* the day is flagged `late` (see
     * `isLate()`), never how many minutes are reported: an employee who arrives
     * 11 minutes after an 08:30 start is 11 minutes late whatever the tolerance
     * is. Counting from the grace edge instead would mean raising the tolerance
     * silently shrank every accumulated lateness total in the monthly report.
     */
    private function lateMinutes(Time $checkinLocal, object $schedule): int
    {
        return max(0, $this->minuteOfDayFromTime($checkinLocal) - $this->minuteOfDay((string) ($schedule->start_time ?? '08:30:00')));
    }

    /**
     * Whether the arrival is late *enough to be flagged* — i.e. past the
     * schedule's grace tolerance.
     */
    private function isLate(int $lateMinutes, object $schedule): bool
    {
        return $lateMinutes > (int) ($schedule->grace_minutes ?? 0);
    }

    private function earlyLeaveMinutes(Time $checkoutLocal, object $schedule): int
    {
        return max(0, $this->minuteOfDay((string) ($schedule->end_time ?? '18:00:00')) - $this->minuteOfDayFromTime($checkoutLocal));
    }

    /**
     * Status priority, first match wins: holiday beats a non-working weekday
     * (a holiday on a Saturday is still a holiday), approved leave beats absent
     * (that is the whole point of approving it), and a missing check-out is
     * flagged before lateness because it blocks the month's totals.
     */
    private function resolveStatus(?object $row, ?string $holidayName, bool $isWorkday, ?object $leave, bool $flaggedLate): string
    {
        if ($holidayName !== null) {
            return self::STATUS_HOLIDAY;
        }

        if (! $isWorkday) {
            return self::STATUS_NON_WORKDAY;
        }

        if ($leave !== null && ($leave->day_portion ?? 'full') === 'full') {
            return self::STATUS_ON_LEAVE;
        }

        if ($row === null || $row->checkin_at === null) {
            return $leave !== null ? self::STATUS_ON_LEAVE : self::STATUS_ABSENT;
        }

        if ($row->checkout_at === null) {
            return self::STATUS_MISSING_CHECKOUT;
        }

        return $flaggedLate ? self::STATUS_LATE : self::STATUS_PRESENT;
    }

    private function defaultScheduleCached(): ?object
    {
        return $this->defaultSchedule ??= model(WorkScheduleModel::class)->defaultSchedule();
    }

    /**
     * Minutes since midnight for a stored `HH:MM[:SS]` schedule time.
     */
    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $this->timeOfDay($time))), 2, 0);

        return $hours * 60 + $minutes;
    }

    private function minuteOfDayFromTime(Time $time): int
    {
        return ((int) $time->format('G')) * 60 + ((int) $time->format('i'));
    }

    /**
     * Normalise a schedule time to `HH:MM:SS` (MySQL TIME can come back either
     * way depending on driver settings).
     */
    private function timeOfDay(string $time): string
    {
        $time = trim($time);

        return substr_count($time, ':') === 1 ? $time . ':00' : $time;
    }

    /**
     * @return object{
     *     status: string, holiday_name: string|null,
     *     late_minutes: int, late_excused: bool, early_leave_minutes: int,
     *     worked_seconds: int|null, expected_seconds: int, overtime_seconds: int
     * }
     */
    private function result(
        string $status,
        ?string $holidayName = null,
        int $lateMinutes = 0,
        bool $lateExcused = false,
        int $earlyLeaveMinutes = 0,
        ?int $workedSeconds = null,
        int $expectedSeconds = 0,
        int $overtimeSeconds = 0,
    ): object {
        return (object) [
            'status'              => $status,
            'holiday_name'        => $holidayName,
            'late_minutes'        => $lateMinutes,
            'late_excused'        => $lateExcused,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'worked_seconds'      => $workedSeconds,
            'expected_seconds'    => $expectedSeconds,
            'overtime_seconds'    => $overtimeSeconds,
        ];
    }
}
