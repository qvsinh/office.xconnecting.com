<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AttendanceCalendar;
use App\Models\AttendanceDayModel;
use App\Models\GroupMemberModel;
use App\Models\GroupModel;
use App\Models\SiteVariableModel;
use App\Models\UserModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;

/**
 * Monthly attendance report, CSV export, and the payroll close/re-open action
 * (see kanban/processing/20260722-01c-attendance-dashboard-reports.md).
 *
 * Every figure comes from `AttendanceCalendar` — this controller only groups and
 * formats. Gated by `authFilter` + `permission:attendance.manage`.
 *
 * The lock lives here because the report page is where the numbers are approved:
 * export → lock → the CSV is the archived payroll record, and later schedule or
 * correction edits can no longer rewrite what was paid.
 */
class AttendanceReport extends BaseController
{
    public function index(): string
    {
        helper('attendance');

        $month   = $this->resolveMonth();
        $groupId = (int) $this->request->getGet('group_id');
        $userId  = (int) $this->request->getGet('user_id');

        $roster = $this->roster($groupId, $userId);
        $rows   = $this->buildRows($roster, $month);

        return view('admin/attendance/report', [
            'month'          => $month,
            'rows'           => $rows,
            'totals'         => $this->companyTotals($rows),
            'groups'         => model(GroupModel::class)->orderBy('name', 'ASC')->findAll(200),
            'employees'      => model(UserModel::class)->attendanceRoster(),
            'groupId'        => $groupId,
            'userId'         => $userId,
            'lockedThrough'  => attendance_locked_through(),
            'monthLocked'    => attendance_period_locked($month . '-01'),
            'exceptionsOnly' => $this->request->getGet('exceptions') === '1',
        ]);
    }

    /**
     * Day-by-day grid for one employee — the sheet you hand someone who disputes
     * a figure, so it carries the correction note and the editor's name.
     */
    public function user(int $id): string
    {
        helper('attendance');

        $month    = $this->resolveMonth();
        $employee = model(UserModel::class)->findUser($id);

        if ($employee === null) {
            throw PageNotFoundException::forPageNotFound('Employee not found.');
        }

        return view('admin/attendance/report-user', $this->userMonth($employee, $month) + [
            'month'    => $month,
            'employee' => $employee,
        ]);
    }

    /**
     * Same filters as the on-screen report, streamed as a download. An export
     * must never widen access, so it re-derives the roster the same way.
     */
    public function export(): ResponseInterface
    {
        helper('attendance');

        $month  = $this->resolveMonth();
        $detail = $this->request->getGet('detail') === '1';
        $roster = $this->roster((int) $this->request->getGet('group_id'), (int) $this->request->getGet('user_id'));

        $csv      = $detail ? $this->detailCsv($roster, $month) : $this->summaryCsv($roster, $month);
        $filename = 'attendance-' . $month . ($detail ? '-detail' : '') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            // UTF-8 BOM: without it Excel on Windows mangles Vietnamese names,
            // which is the one bug guaranteed to be reported.
            ->setBody("\xEF\xBB\xBF" . $csv);
    }

    /**
     * Close the displayed month for payroll.
     */
    public function lock(): ResponseInterface
    {
        $month = $this->resolveMonth((string) $this->request->getPost('month'));

        $this->setLockedThrough($month);

        return $this->jsonSuccess('Payroll is now closed through ' . $month . '. Corrections in that period and earlier will be refused.');
    }

    /**
     * Re-open: step the marker back to the month before the one displayed.
     */
    public function unlock(): ResponseInterface
    {
        helper('attendance');

        $month    = $this->resolveMonth((string) $this->request->getPost('month'));
        $previous = Time::parse($month . '-01', attendance_tz())->subMonths(1)->format('Y-m');

        $this->setLockedThrough($previous);

        return $this->jsonSuccess('Payroll re-opened for ' . $month . '. Re-export the month after any further corrections.');
    }

    /**
     * `?month=YYYY-MM`, defaulting to the current Vietnam month. Anything
     * malformed falls back rather than reaching a date parser.
     */
    private function resolveMonth(?string $candidate = null): string
    {
        helper('attendance');

        $month = trim($candidate ?? (string) $this->request->getGet('month'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : substr(attendance_today(), 0, 7);
    }

    /**
     * The employees the report covers, narrowed by the group/user filters.
     *
     * @return list<object>
     */
    private function roster(int $groupId, int $userId): array
    {
        $roster = model(UserModel::class)->attendanceRoster();

        if ($groupId > 0) {
            $memberIds = array_map(
                static fn (object $member): int => (int) $member->id,
                model(GroupMemberModel::class)->membersOf($groupId),
            );

            $roster = array_values(array_filter($roster, static fn (object $user): bool => in_array((int) $user->id, $memberIds, true)));
        }

        if ($userId > 0) {
            $roster = array_values(array_filter($roster, static fn (object $user): bool => (int) $user->id === $userId));
        }

        return $roster;
    }

    /**
     * One summary row per employee. Three queries for the whole report — the
     * bulk loader is the point (see `AttendanceCalendar::evaluateMonth()`).
     *
     * @param list<object> $roster
     *
     * @return list<object>
     */
    private function buildRows(array $roster, string $month): array
    {
        if ($roster === []) {
            return [];
        }

        $calendar = new AttendanceCalendar();
        $userIds  = array_map(static fn (object $user): int => (int) $user->id, $roster);
        $months   = $calendar->evaluateMonth($userIds, $month);

        $rows = [];

        foreach ($roster as $user) {
            $rows[] = (object) [
                'user'    => $user,
                'summary' => $calendar->summarise($months[(int) $user->id] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * Company totals across the report's rows.
     *
     * @param list<object> $rows
     */
    private function companyTotals(array $rows): object
    {
        $fields = [
            'workdays', 'present', 'late', 'late_minutes', 'early_leave', 'early_leave_minutes',
            'absent', 'on_leave', 'missing_checkout', 'worked_seconds', 'expected_seconds', 'overtime_seconds',
        ];

        $totals = array_fill_keys($fields, 0);

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] += (int) $row->summary->{$field};
            }
        }

        return (object) $totals;
    }

    /**
     * One employee's month: per-day evaluations, their attendance rows (for the
     * recorded times and correction notes) and the rolled-up summary.
     *
     * @return array{days: array<string, object>, rows: array<string, object>, summary: object}
     */
    private function userMonth(object $employee, string $month): array
    {
        $calendar = new AttendanceCalendar();
        $userId   = (int) $employee->id;
        $days     = $calendar->evaluateMonth([$userId], $month)[$userId] ?? [];

        $from = $month . '-01';
        $to   = array_key_last($days) ?? $from;

        $rows = [];

        foreach (model(AttendanceDayModel::class)->forUsersInRange([$userId], $from, (string) $to) as $row) {
            $rows[(string) $row->work_date] = $row;
        }

        return [
            'days'    => $days,
            'rows'    => $rows,
            'summary' => $calendar->summarise($days),
        ];
    }

    /**
     * @param list<object> $roster
     */
    private function summaryCsv(array $roster, string $month): string
    {
        $rows = $this->buildRows($roster, $month);

        $lines = [[
            'Employee', 'Email', 'Month', 'Workdays', 'Present', 'Late', 'Late minutes',
            'Early leave', 'Early leave minutes', 'Absent', 'On leave', 'Missing check-out',
            'Worked hours', 'Expected hours', 'Overtime hours',
        ]];

        foreach ($rows as $row) {
            $lines[] = [
                (string) ($row->user->name ?? ''),
                (string) ($row->user->email ?? ''),
                $month,
                $row->summary->workdays,
                $row->summary->present,
                $row->summary->late,
                $row->summary->late_minutes,
                $row->summary->early_leave,
                $row->summary->early_leave_minutes,
                $row->summary->absent,
                $row->summary->on_leave,
                $row->summary->missing_checkout,
                $this->decimalHours($row->summary->worked_seconds),
                $this->decimalHours($row->summary->expected_seconds),
                $this->decimalHours($row->summary->overtime_seconds),
            ];
        }

        return $this->toCsv($lines);
    }

    /**
     * One row per (employee, day) for payroll systems that want the raw grid.
     *
     * @param list<object> $roster
     */
    private function detailCsv(array $roster, string $month): string
    {
        helper('attendance');

        if ($roster === []) {
            return $this->toCsv([['Employee', 'Date', 'Status']]);
        }

        $calendar = new AttendanceCalendar();
        $userIds  = array_map(static fn (object $user): int => (int) $user->id, $roster);
        $months   = $calendar->evaluateMonth($userIds, $month);

        $attendance = [];

        foreach (model(AttendanceDayModel::class)->forUsersInRange($userIds, $month . '-01', Time::parse($month . '-01', attendance_tz())->addMonths(1)->subDays(1)->toDateString()) as $row) {
            $attendance[(int) $row->user_id][(string) $row->work_date] = $row;
        }

        $lines = [[
            'Employee', 'Email', 'Date', 'Status', 'Check-in', 'Check-out',
            'Worked hours', 'Expected hours', 'Late minutes', 'Early leave minutes',
            'Overtime hours', 'Note', 'Corrected',
        ]];

        foreach ($roster as $user) {
            $userId = (int) $user->id;

            foreach ($months[$userId] ?? [] as $date => $day) {
                $row = $attendance[$userId][$date] ?? null;

                $lines[] = [
                    (string) ($user->name ?? ''),
                    (string) ($user->email ?? ''),
                    (string) $date,
                    $day->status,
                    $this->localTime($row->checkin_at ?? null),
                    $this->localTime($row->checkout_at ?? null),
                    $this->decimalHours((int) ($day->worked_seconds ?? 0)),
                    $this->decimalHours($day->expected_seconds),
                    $day->late_minutes,
                    $day->early_leave_minutes,
                    $this->decimalHours($day->overtime_seconds),
                    (string) ($row->note ?? ''),
                    ($row->edited_at ?? null) === null ? '' : 'yes',
                ];
            }
        }

        return $this->toCsv($lines);
    }

    /**
     * Decimal hours to two places — spreadsheets cannot sum `8h 05m`.
     */
    private function decimalHours(int $seconds): string
    {
        return number_format($seconds / 3600, 2, '.', '');
    }

    private function localTime(?string $utc): string
    {
        helper('attendance');
        $local = attendance_local($utc);

        return $local === null ? '' : $local->format('H:i');
    }

    /**
     * @param list<list<int|string>> $lines
     */
    private function toCsv(array $lines): string
    {
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            return '';
        }

        foreach ($lines as $line) {
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function setLockedThrough(string $value): void
    {
        model(SiteVariableModel::class)->setValue('attendance_locked_through', $value);
    }
}
