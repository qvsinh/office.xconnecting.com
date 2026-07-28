<?php

namespace App\Libraries;

use App\Models\LeaveRequestModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;

/**
 * Annual-leave accounting: how many days a request actually costs, and how many
 * an employee has left.
 *
 * The rule that matters: **a leave request only consumes scheduled workdays.** A
 * Friday-to-Monday range costs 2 days, not 4, and a range containing a public
 * holiday does not burn it. Counting calendar days would quietly overcharge every
 * employee who takes leave across a weekend.
 *
 * ponytail: quota − used, computed on read. No `leave_balances` table, no accrual
 * schedule, no carry-over between years — add one only when the company actually
 * accrues monthly or carries days forward.
 */
class LeaveBalance
{
    /**
     * Company-wide default when neither the employee nor the setting says
     * otherwise — the Vietnamese statutory minimum.
     */
    private const FALLBACK_DAYS = 12;

    public function __construct(private ?AttendanceCalendar $calendar = null)
    {
        $this->calendar ??= new AttendanceCalendar();
    }

    /**
     * Workday-weighted cost of a request: full days count 1, a half day 0.5,
     * weekends and holidays 0.
     *
     * A `late` request costs nothing — it is permission to arrive late, not time
     * off.
     */
    public function requestDays(object $request): float
    {
        if (($request->type ?? '') !== LeaveRequestModel::TYPE_LEAVE) {
            return 0.0;
        }

        $userId = (int) $request->user_id;
        $days   = $this->workdaysIn($userId, (string) $request->start_date, (string) ($request->end_date ?? $request->start_date));

        if ($days === 0) {
            return 0.0;
        }

        // Half days only exist on a single-day request (enforced at validation).
        return ($request->day_portion ?? 'full') === 'full' ? (float) $days : 0.5;
    }

    /**
     * Scheduled workdays in an inclusive range for this employee, holidays
     * excluded.
     */
    public function workdaysIn(int $userId, string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }

        helper('attendance');

        $schedule = $this->calendar->scheduleFor($userId);

        if ($schedule === null) {
            return 0;
        }

        $this->calendar->loadHolidays($from, $to);

        $count  = 0;
        $cursor = Time::parse($from, attendance_tz());
        $end    = Time::parse($to, attendance_tz());

        while ($cursor->toDateString() <= $end->toDateString()) {
            $date = $cursor->toDateString();

            if ($this->calendar->isWorkday($date, $schedule) && $this->calendar->holidayName($date) === null) {
                $count++;
            }

            $cursor = $cursor->addDays(1);
        }

        return $count;
    }

    /**
     * The employee's quota: their own `annual_leave_days`, else the
     * `leave_annual_days` setting, else the statutory fallback.
     */
    public function quota(int $userId): float
    {
        $user = model(UserModel::class)->findUser($userId);

        if ($user !== null && ($user->annual_leave_days ?? null) !== null) {
            return (float) $user->annual_leave_days;
        }

        helper('setting');
        $configured = trim((string) get_site_variable('leave_annual_days', ''));

        return is_numeric($configured) ? (float) $configured : (float) self::FALLBACK_DAYS;
    }

    /**
     * Annual-leave days already approved in the calendar year, workday-weighted.
     *
     * A request straddling New Year is clipped to the year being asked about, so
     * neither year is charged for the other's days.
     */
    public function used(int $userId, int $year): float
    {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd   = sprintf('%04d-12-31', $year);

        $used = 0.0;

        foreach (model(LeaveRequestModel::class)->approvedAnnualInYear($userId, $year) as $request) {
            $start = max((string) $request->start_date, $yearStart);
            $end   = min((string) ($request->end_date ?? $request->start_date), $yearEnd);

            $days = $this->workdaysIn($userId, $start, $end);

            if ($days === 0) {
                continue;
            }

            $used += ($request->day_portion ?? 'full') === 'full' ? (float) $days : 0.5;
        }

        return $used;
    }

    /**
     * Everything the employee page and the approval screen need in one call.
     *
     * @return object{quota: float, used: float, remaining: float, year: int}
     */
    public function summary(int $userId, ?int $year = null): object
    {
        helper('attendance');
        $year ??= (int) substr(attendance_today(), 0, 4);

        $quota = $this->quota($userId);
        $used  = $this->used($userId, $year);

        return (object) [
            'year'      => $year,
            'quota'     => $quota,
            'used'      => $used,
            'remaining' => $quota - $used,
        ];
    }

    /**
     * Format days for display: `8.5 days`, `1 day` — no trailing `.0`.
     */
    public static function formatDays(float $days): string
    {
        $rendered = rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');

        return $rendered . ' ' . (abs($days - 1.0) < 0.01 ? 'day' : 'days');
    }
}
