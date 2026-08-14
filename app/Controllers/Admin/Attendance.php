<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AttendanceCalendar;
use App\Models\AttendanceDayModel;
use App\Models\UserModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;

/**
 * Company-wide attendance review and correction (see
 * kanban/processing/20260722-01-attendance-checkin-checkout.md §8).
 *
 * Gated by `authFilter` + `permission:attendance.manage` at the route, not by
 * `adminFilter`: a department head must be able to manage their team's
 * attendance without holding global admin rights (`PermissionFilter` lets
 * admin-zone users through anyway).
 *
 * Every write goes through `AttendanceDayModel::applyCorrection()`, which is
 * the only place the audit columns are set — attendance feeds payroll, so a
 * changed time must always be traceable to who changed it and what it was.
 */
class Attendance extends BaseController
{
    public function index(): string
    {
        helper('attendance');

        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sort    = (string) $this->request->getGet('sort');
        $order   = (string) $this->request->getGet('order');
        $filters = [
            'user_id'          => (int) $this->request->getGet('user_id'),
            'date_from'        => trim((string) $this->request->getGet('date_from')),
            'date_to'          => trim((string) $this->request->getGet('date_to')),
            'missing_checkout' => (string) $this->request->getGet('missing_checkout'),
        ];

        $model = model(AttendanceDayModel::class);
        $items = $model->searchAdmin($filters, $sort !== '' ? $sort : 'work_date', $order !== '' ? $order : 'desc', $limit, $offset);

        return view('admin/attendance/index', [
            'items'       => $items,
            'evaluations' => $this->evaluateRows($items),
            'total'       => $model->countAdmin($filters),
            'page'        => $page,
            'limit'       => $limit,
            'sort'        => $sort,
            'order'       => $order,
            'filters'     => $filters,
            // Active roster only — the filter dropdown should not offer
            // deactivated accounts. Bounded so a large tenant can't OOM the page.
            'employees'     => model(UserModel::class)->where('status', 1)->orderBy('name', 'ASC')->findAll(500),
            'ipEnforced'    => attendance_ip_enforced(),
            'lockedThrough' => attendance_locked_through(),
            // Shown on the page so the office-IP allowlist can be configured
            // from an observed value instead of a guess. If this reads
            // 127.0.0.1 (or the same address for every employee), nginx is
            // proxying over HTTP and `Config\App::$proxyIPs` needs the
            // trusted-proxy map — see the card's §0.
            'requestIp' => $this->request->getIPAddress(),
        ]);
    }

    /**
     * Today's presence board — the screen a manager opens daily.
     *
     * The roster is LEFT-JOINed conceptually (active users first, then their
     * optional row): "absent" is the *absence* of a row, so an INNER JOIN would
     * hide exactly the people being looked for.
     */
    public function today(): string
    {
        helper('attendance');

        $date     = attendance_today();
        $roster   = model(UserModel::class)->attendanceRoster();
        $userIds  = array_map(static fn (object $user): int => (int) $user->id, $roster);
        $calendar = new AttendanceCalendar();

        $evaluations = $calendar->evaluateSpan($userIds, $date, $date);
        $rows        = [];

        foreach (model(AttendanceDayModel::class)->forUsersInRange($userIds, $date, $date) as $row) {
            $rows[(int) $row->user_id] = $row;
        }

        $board  = [];
        $counts = array_fill_keys([
            AttendanceCalendar::STATUS_PRESENT,
            AttendanceCalendar::STATUS_LATE,
            AttendanceCalendar::STATUS_NOT_YET,
            AttendanceCalendar::STATUS_ON_LEAVE,
            AttendanceCalendar::STATUS_ABSENT,
            AttendanceCalendar::STATUS_MISSING_CHECKOUT,
            AttendanceCalendar::STATUS_HOLIDAY,
            AttendanceCalendar::STATUS_NON_WORKDAY,
        ], 0);

        foreach ($roster as $user) {
            $userId = (int) $user->id;
            // Nobody is absent at 08:00 — soften today's absence until the start
            // time has passed.
            $day = $calendar->withPendingArrival($userId, $date, $evaluations[$userId][$date]);

            $board[] = (object) [
                'user'     => $user,
                'day'      => $day,
                'row'      => $rows[$userId] ?? null,
                'schedule' => $calendar->scheduleFor($userId),
            ];

            $counts[$day->status] = ($counts[$day->status] ?? 0) + 1;
        }

        return view('admin/attendance/today', [
            'date'       => $date,
            'board'      => $board,
            'counts'     => $counts,
            'statusOnly' => (string) $this->request->getGet('status'),
        ]);
    }

    /**
     * Status + hours for the rows on this page, keyed by row id.
     *
     * The holiday range is warmed once and each employee's schedule is resolved
     * once, so a 25-row page stays a handful of queries rather than one per row.
     *
     * @param list<object> $rows
     *
     * @return array<int, object>
     */
    private function evaluateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates    = array_map(static fn (object $row): string => (string) $row->work_date, $rows);
        $calendar = new AttendanceCalendar();
        $calendar->loadHolidays(min($dates), max($dates));

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->id] = $calendar->evaluate((int) $row->user_id, (string) $row->work_date, $row);
        }

        return $out;
    }

    /**
     * JSON attendance record for the correction modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        helper('attendance');

        $row = model(AttendanceDayModel::class)->findDay($id);

        if ($row === null) {
            return $this->jsonFailed('Attendance record not found.', 404);
        }

        return $this->jsonSuccess('OK', [
            'day' => [
                'id'        => (int) $row->id,
                'user_id'   => (int) $row->user_id,
                'work_date' => $row->work_date,
                // The modal edits Vietnam local time; the controller converts
                // back to UTC on save.
                'checkin_at'  => $this->toLocalInput($row->checkin_at),
                'checkout_at' => $this->toLocalInput($row->checkout_at),
                'note'        => $row->note,
                'locked'      => attendance_period_locked($row->work_date),
            ],
        ]);
    }

    /**
     * Correct an existing day's times and/or note.
     */
    public function update(): ResponseInterface
    {
        helper('attendance');

        if (! $this->validate($this->correctionRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $model = model(AttendanceDayModel::class);
        $row   = $model->findDay((int) $this->request->getPost('id'));

        if ($row === null) {
            return $this->jsonFailed('Attendance record not found.', 404);
        }

        if (attendance_period_locked($row->work_date)) {
            return $this->jsonFailed('That month is closed for payroll (locked through ' . attendance_locked_through() . ').', 409);
        }

        $times = $this->resolveTimes($row->work_date);

        if (is_string($times)) {
            return $this->jsonFailed($times, 400);
        }

        $model->applyCorrection((int) $row->id, $times + [
            'note' => trim((string) $this->request->getPost('note')) ?: null,
        ], current_user_id(), Time::now('UTC')->format('Y-m-d H:i:s'));

        return $this->jsonSuccess('Attendance record has been updated successfully.');
    }

    /**
     * Manual entry for a day the employee never recorded (missed check-in,
     * offline day). Written through the same audited path, so a
     * manager-created row is visibly manager-created.
     */
    public function create(): ResponseInterface
    {
        helper('attendance');

        if (! $this->validate($this->createRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $userId   = (int) $this->request->getPost('user_id');
        $workDate = (string) $this->request->getPost('work_date');

        if (model(UserModel::class)->findUser($userId) === null) {
            return $this->jsonFailed('Employee not found.', 404);
        }

        if (attendance_period_locked($workDate)) {
            return $this->jsonFailed('That month is closed for payroll (locked through ' . attendance_locked_through() . ').', 409);
        }

        $times = $this->resolveTimes($workDate);

        if (is_string($times)) {
            return $this->jsonFailed($times, 400);
        }

        $model  = model(AttendanceDayModel::class);
        $nowUtc = Time::now('UTC')->format('Y-m-d H:i:s');

        try {
            $id = $model->createManual($userId, $workDate);
        } catch (DatabaseException $e) {
            // UNIQUE(user_id, work_date): surface the collision as a message,
            // not a 500.
            log_message('info', 'Duplicate manual attendance entry blocked: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonFailed('That employee already has a record for ' . $workDate . '. Edit it instead.', 409);
        }

        $model->applyCorrection($id, $times + [
            'note' => trim((string) $this->request->getPost('note')) ?: null,
        ], current_user_id(), $nowUtc);

        return $this->jsonSuccess('Attendance record has been created successfully.');
    }

    /**
     * Turn the modal's Vietnam-local `H:i` inputs into UTC DATETIME values for
     * storage, rejecting a check-out that precedes the check-in.
     *
     * Returns the update fragment, or an error string for the caller to
     * surface. Shared by `update()`/`create()` so the two paths cannot validate
     * differently.
     *
     * @return array{checkin_at: string|null, checkout_at: string|null}|string
     */
    private function resolveTimes(string $workDate): array|string
    {
        $checkin  = $this->toUtc($workDate, (string) $this->request->getPost('checkin_time'));
        $checkout = $this->toUtc($workDate, (string) $this->request->getPost('checkout_time'));

        if ($checkin === null && $checkout !== null) {
            return 'A check-out needs a check-in on the same day.';
        }

        if ($checkin !== null && $checkout !== null && $checkout < $checkin) {
            return 'Check-out must not be earlier than check-in.';
        }

        return ['checkin_at' => $checkin, 'checkout_at' => $checkout];
    }

    /**
     * `H:i` in Vietnam time on `$workDate` -> stored UTC DATETIME. Blank input
     * clears the column (an admin can un-set a wrongly recorded check-out).
     */
    private function toUtc(string $workDate, string $localTime): ?string
    {
        $localTime = trim($localTime);

        if ($localTime === '') {
            return null;
        }

        return Time::parse($workDate . ' ' . $localTime . ':00', attendance_tz())
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Stored UTC DATETIME -> `H:i` for the modal's time inputs.
     */
    private function toLocalInput(?string $utcDatetime): string
    {
        $local = attendance_local($utcDatetime);

        return $local === null ? '' : $local->format('H:i');
    }

    /**
     * @return array<string, string>
     */
    private function correctionRules(): array
    {
        return [
            'id'            => 'required|is_natural_no_zero',
            'checkin_time'  => 'permit_empty|regex_match[/^([01][0-9]|2[0-3]):[0-5][0-9]$/]',
            'checkout_time' => 'permit_empty|regex_match[/^([01][0-9]|2[0-3]):[0-5][0-9]$/]',
            'note'          => 'permit_empty|max_length[255]',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function createRules(): array
    {
        // Same time/note rules as a correction, but keyed on user+date instead
        // of an existing row id (`+` keeps the left operand's keys, so the
        // borrowed `id` rule has to go explicitly).
        $rules = $this->correctionRules();
        unset($rules['id']);

        return [
            'user_id'   => 'required|is_natural_no_zero',
            'work_date' => 'required|valid_date[Y-m-d]',
        ] + $rules;
    }
}
