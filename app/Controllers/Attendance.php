<?php

namespace App\Controllers;

use App\Libraries\AccessLogger;
use App\Libraries\AttendanceCalendar;
use App\Models\AccessLogModel;
use App\Models\AttendanceDayModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;

/**
 * Employee self-service attendance: today's card plus the personal history
 * (see kanban/processing/20260722-01-attendance-checkin-checkout.md §8).
 *
 * Every read and write is scoped to `current_user_id()`; the owning `user_id`
 * is never accepted from request input, so there is no IDOR surface here.
 *
 * Timestamps are stored UTC; the Vietnam business day decides `work_date` and
 * all display (see `attendance_helper.php`).
 */
class Attendance extends BaseController
{
    /**
     * History page size for the personal list.
     */
    private const PAGE_SIZE = 15;

    public function index(): string
    {
        helper('attendance');

        $userId  = current_user_id();
        $model   = model(AttendanceDayModel::class);
        $page    = max(1, (int) $this->request->getGet('page'));
        $offset  = ($page - 1) * self::PAGE_SIZE;
        $history = $model->historyFor($userId, self::PAGE_SIZE, $offset);
        $today   = $model->todayFor($userId);

        return view('attendance/index', [
            'today'         => $today,
            'todayDate'     => attendance_today(),
            'history'       => $history,
            'evaluations'   => $this->evaluateRows($userId, $history, $today),
            'total'         => $model->countForUser($userId),
            'page'          => $page,
            'perPage'       => self::PAGE_SIZE,
            'ipAllowed'     => office_ip_allowed(),
            'ipEnforced'    => attendance_ip_enforced(),
            'impersonating' => is_impersonating(),
        ]);
    }

    /**
     * The employee's own monthly summary.
     *
     * There is deliberately no user parameter on this route — it is always
     * `current_user_id()`, so there is no query-string escape hatch to another
     * employee's figures. Transparency here prevents most disputes before they
     * start.
     */
    public function report(): string
    {
        helper('attendance');

        $month = trim((string) $this->request->getGet('month'));
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : substr(attendance_today(), 0, 7);

        $userId   = current_user_id();
        $calendar = new AttendanceCalendar();
        $days     = $calendar->evaluateMonth([$userId], $month)[$userId] ?? [];

        $rows = [];

        foreach (model(AttendanceDayModel::class)->forUsersInRange([$userId], $month . '-01', (string) (array_key_last($days) ?? $month . '-01')) as $row) {
            $rows[(string) $row->work_date] = $row;
        }

        return view('attendance/report', [
            'month'   => $month,
            'days'    => $days,
            'rows'    => $rows,
            'summary' => $calendar->summarise($days),
        ]);
    }

    /**
     * Record an arrival for today (Vietnam calendar).
     */
    public function checkin(): ResponseInterface
    {
        helper('attendance');

        if (($blocked = $this->guardRecording()) !== null) {
            return $blocked;
        }

        $userId   = current_user_id();
        $model    = model(AttendanceDayModel::class);
        $workDate = attendance_today();
        $existing = $model->dayFor($userId, $workDate);

        if ($existing !== null && $existing->checkin_at !== null) {
            return $this->jsonFailed('You have already checked in today.', 409);
        }

        try {
            $row = $model->checkin($userId, $this->request->getIPAddress(), $workDate, $this->nowUtc());
        } catch (DatabaseException $e) {
            // The UNIQUE(user_id, work_date) key is the real guard: a
            // double-submit or a second browser tab lands here instead of
            // producing a 500 or a duplicate row.
            log_message('info', 'Duplicate check-in blocked for user #{id}: {msg}', ['id' => $userId, 'msg' => $e->getMessage()]);

            return $this->jsonFailed('You have already checked in today.', 409);
        }

        (new AccessLogger())->record(AccessLogModel::EVENT_CHECKIN, $userId, $this->request);

        return $this->jsonSuccess('Checked in at ' . attendance_local_time($row->checkin_at) . '.', [
            'day' => $this->dayPayload($row),
        ]);
    }

    /**
     * Record a departure for today. Re-checking out overwrites the previous
     * time (last one wins), so an accidental lunchtime tap is self-healing
     * rather than locking the rest of the day.
     */
    public function checkout(): ResponseInterface
    {
        helper('attendance');

        if (($blocked = $this->guardRecording()) !== null) {
            return $blocked;
        }

        $userId = current_user_id();
        $model  = model(AttendanceDayModel::class);
        $row    = $model->dayFor($userId, attendance_today());

        if ($row === null || $row->checkin_at === null) {
            return $this->jsonFailed('You have not checked in today.', 409);
        }

        $updated = $model->checkout((int) $row->id, $this->request->getIPAddress(), $this->nowUtc());

        if ($updated === null) {
            return $this->jsonFailed('Could not record your check-out. Please try again.', 500);
        }

        (new AccessLogger())->record(AccessLogModel::EVENT_CHECKOUT, $userId, $this->request);

        return $this->jsonSuccess('Checked out at ' . attendance_local_time($updated->checkout_at) . '.', [
            'day' => $this->dayPayload($updated),
        ]);
    }

    /**
     * Status + hours for the rows on screen, keyed by `work_date`.
     *
     * The holiday range is warmed once so a 15-row page costs one holiday query,
     * not fifteen; the schedule is resolved once and reused.
     *
     * @param list<object> $rows
     *
     * @return array<string, object>
     */
    private function evaluateRows(int $userId, array $rows, ?object $today): array
    {
        $dates = array_map(static fn (object $row): string => (string) $row->work_date, $rows);

        if ($today !== null) {
            $dates[] = (string) $today->work_date;
        }

        if ($dates === []) {
            return [];
        }

        $calendar = new AttendanceCalendar();
        $calendar->loadHolidays(min($dates), max($dates));
        $schedule = $calendar->scheduleFor($userId);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->work_date] = $calendar->evaluate($userId, (string) $row->work_date, $row, null, $schedule);
        }

        if ($today !== null && ! isset($out[(string) $today->work_date])) {
            $out[(string) $today->work_date] = $calendar->evaluate($userId, (string) $today->work_date, $today, null, $schedule);
        }

        return $out;
    }

    /**
     * The two conditions that stop an employee recording attendance at all,
     * shared by check-in and check-out so they can never diverge.
     */
    private function guardRecording(): ?ResponseInterface
    {
        if (is_impersonating()) {
            return $this->jsonFailed('You cannot record attendance while acting as another user.', 403);
        }

        if (! office_ip_allowed()) {
            return $this->jsonFailed('You can only record attendance from the office network.', 403);
        }

        return null;
    }

    /**
     * Current UTC timestamp for storage — one place, so a stray `date()` call
     * in local time can't sneak into a row.
     */
    private function nowUtc(): string
    {
        return Time::now('UTC')->format('Y-m-d H:i:s');
    }

    /**
     * `H:i` in Vietnam time, or **null** when the moment has not happened.
     *
     * Null, never a dash: this feeds the Alpine card's state, and the card
     * decides which button to show from whether these are set. A `'—'`
     * placeholder is truthy in JavaScript and would offer "Check out" to
     * somebody who has not checked in. Display fallbacks belong in the view.
     */
    private function localTime(?string $utcDatetime): ?string
    {
        return attendance_local($utcDatetime)?->format('H:i');
    }

    /**
     * The row shape the Alpine card re-renders itself from after a successful
     * check-in/out — pre-formatted in Vietnam time so the view does no
     * timezone maths.
     *
     * @return array<string, string|null>
     */
    private function dayPayload(object $row): array
    {
        return [
            'work_date'   => $row->work_date,
            'checkin_at'  => $this->localTime($row->checkin_at),
            'checkout_at' => $this->localTime($row->checkout_at),
            'worked'      => attendance_format_duration(attendance_worked_seconds($row->checkin_at, $row->checkout_at)),
        ];
    }
}
