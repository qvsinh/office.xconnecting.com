<?php

namespace App\Controllers;

use App\Libraries\AttendanceCalendar;
use App\Libraries\LeaveBalance;
use App\Models\LeaveRequestModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Employee self-service leave / late-arrival requests (see
 * kanban/processing/20260722-02-leave-late-requests.md).
 *
 * Every read and write is scoped to `current_user_id()`; `user_id` is never
 * accepted from request input. Submitting while impersonating is refused for the
 * same reason attendance recording is — a request filed under somebody else's
 * name is a statement they never made.
 */
class Requests extends BaseController
{
    private const PAGE_SIZE = 15;

    public function index(): string
    {
        helper('attendance');

        $userId  = current_user_id();
        $model   = model(LeaveRequestModel::class);
        $page    = max(1, (int) $this->request->getGet('page'));
        $offset  = ($page - 1) * self::PAGE_SIZE;
        $balance = new LeaveBalance();

        $requests = $model->forUser($userId, self::PAGE_SIZE, $offset);
        $days     = [];

        foreach ($requests as $request) {
            $days[(int) $request->id] = $balance->requestDays($request);
        }

        return view('requests/index', [
            'items'         => $requests,
            'requestDays'   => $days,
            'total'         => $model->countForUser($userId),
            'page'          => $page,
            'perPage'       => self::PAGE_SIZE,
            'balance'       => $balance->summary($userId),
            'schedule'      => (new AttendanceCalendar())->scheduleFor($userId),
            'today'         => attendance_today(),
            'impersonating' => is_impersonating(),
        ]);
    }

    public function create(): ResponseInterface
    {
        helper('attendance');

        if (is_impersonating()) {
            return $this->jsonFailed('You cannot submit a request while acting as another user.', 403);
        }

        if (! $this->validate($this->rules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $userId  = current_user_id();
        $type    = (string) $this->request->getPost('type');
        $isLeave = $type === LeaveRequestModel::TYPE_LEAVE;

        // `leave_kind` is required for leave but meaningless for a late arrival —
        // a conditional the rule set can't express, so it is checked here.
        if ($isLeave && ! in_array((string) $this->request->getPost('leave_kind'), LeaveRequestModel::KINDS, true)) {
            return $this->jsonFailed('Pick a leave type: annual, unpaid or sick.', 400);
        }

        $startDate = (string) $this->request->getPost('start_date');
        $endDate   = trim((string) $this->request->getPost('end_date'));
        $endDate   = $isLeave && $endDate !== '' ? $endDate : $startDate;
        $portion   = $isLeave ? (string) ($this->request->getPost('day_portion') ?: 'full') : 'full';

        if ($endDate < $startDate) {
            return $this->jsonFailed('The end date cannot be before the start date.', 400);
        }

        if ($portion !== 'full' && $endDate !== $startDate) {
            return $this->jsonFailed('A half day applies to a single date only.', 400);
        }

        if (($error = $this->checkPeriodLock($startDate, $endDate)) !== null) {
            return $this->jsonFailed($error, 409);
        }

        if (($error = $this->checkWorkdays($userId, $startDate, $endDate)) !== null) {
            return $this->jsonFailed($error, 400);
        }

        if (($error = $this->checkExpectedTime($userId, $type)) !== null) {
            return $this->jsonFailed($error, 400);
        }

        if (model(LeaveRequestModel::class)->overlapsExisting($userId, $startDate, $endDate)) {
            return $this->jsonFailed('You already have a pending or approved request covering those dates.', 409);
        }

        model(LeaveRequestModel::class)->createRequest([
            'user_id'       => $userId,
            'type'          => $type,
            'leave_kind'    => $isLeave ? (string) $this->request->getPost('leave_kind') : null,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'day_portion'   => $portion,
            'expected_time' => $isLeave ? null : $this->expectedTime(),
            'reason'        => trim((string) $this->request->getPost('reason')),
        ]);

        return $this->jsonSuccess('Your request has been submitted and is awaiting approval.');
    }

    /**
     * Withdraw one of the employee's own still-pending requests.
     */
    public function cancel(int $id): ResponseInterface
    {
        $model = model(LeaveRequestModel::class);
        $row   = $model->findRequest($id);

        // Another employee's request is indistinguishable from a missing one.
        if ($row === null || (int) $row->user_id !== current_user_id()) {
            return $this->jsonFailed('Request not found.', 404);
        }

        if (! $model->cancelOwn($id, current_user_id())) {
            return $this->jsonFailed('Only a pending request can be withdrawn.', 409);
        }

        return $this->jsonSuccess('Your request has been withdrawn.');
    }

    /**
     * How many leave days a range would cost, for the live counter in the form.
     * Read-only, always scoped to the current employee.
     */
    public function preview(): ResponseInterface
    {
        $start = (string) $this->request->getGet('start_date');
        $end   = trim((string) $this->request->getGet('end_date'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1) {
            return $this->jsonFailed('Pick a start date.', 400);
        }

        if ($end === '' || $end < $start) {
            $end = $start;
        }

        $days = (new LeaveBalance())->workdaysIn(current_user_id(), $start, $end);

        return $this->jsonSuccess('OK', ['workdays' => $days]);
    }

    /**
     * A leave range with no scheduled workday in it is a mistake worth catching
     * before a manager is asked to approve it.
     */
    private function checkWorkdays(int $userId, string $startDate, string $endDate): ?string
    {
        if ((string) $this->request->getPost('type') !== LeaveRequestModel::TYPE_LEAVE) {
            return null;
        }

        if ((new LeaveBalance())->workdaysIn($userId, $startDate, $endDate) === 0) {
            return 'Those dates contain no working day — check the dates, or the holiday calendar.';
        }

        return null;
    }

    /**
     * A request touching a month already closed for payroll cannot be honoured,
     * so it must not be accepted either.
     */
    private function checkPeriodLock(string $startDate, string $endDate): ?string
    {
        if (attendance_period_locked($startDate) || attendance_period_locked($endDate)) {
            return 'That period is closed for payroll (locked through ' . attendance_locked_through() . ').';
        }

        return null;
    }

    /**
     * A "late arrival" earlier than the schedule's start time is not late.
     */
    private function checkExpectedTime(int $userId, string $type): ?string
    {
        if ($type !== LeaveRequestModel::TYPE_LATE) {
            return null;
        }

        $expected = $this->expectedTime();

        if ($expected === null) {
            return null;
        }

        $schedule = (new AttendanceCalendar())->scheduleFor($userId);

        if ($schedule === null) {
            return null;
        }

        if (substr($expected, 0, 5) <= substr((string) $schedule->start_time, 0, 5)) {
            return 'That arrival time is not later than your normal start (' . substr((string) $schedule->start_time, 0, 5) . ').';
        }

        return null;
    }

    private function expectedTime(): ?string
    {
        $time = trim((string) $this->request->getPost('expected_time'));

        return $time === '' ? null : $time . ':00';
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'type'          => 'required|in_list[' . implode(',', [LeaveRequestModel::TYPE_LEAVE, LeaveRequestModel::TYPE_LATE]) . ']',
            'leave_kind'    => 'permit_empty|in_list[' . implode(',', LeaveRequestModel::KINDS) . ']',
            'start_date'    => 'required|valid_date[Y-m-d]',
            'end_date'      => 'permit_empty|valid_date[Y-m-d]',
            'day_portion'   => 'permit_empty|in_list[' . implode(',', LeaveRequestModel::PORTIONS) . ']',
            'expected_time' => 'permit_empty|regex_match[/^([01][0-9]|2[0-3]):[0-5][0-9]$/]',
            'reason'        => 'required|max_length[1000]',
        ];
    }
}
