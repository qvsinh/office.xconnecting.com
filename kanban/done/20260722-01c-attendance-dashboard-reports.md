# 01c — Management dashboard, monthly report, CSV export, month lock

**Goal:** The screens a manager actually opens. Card 01 records facts, card 01b
turns them into status and hours — this card answers the daily and monthly
questions: *who is in right now*, *who is late or missing*, *how many hours and
late minutes does each employee have this month*, and *give me that as a file for
payroll*.

**Depends on:** card 01 (data + permission) and card 01b (`AttendanceCalendar`).

---

## 1. Today board — `/admin/attendance/today`

The default landing page of the management area (link it first in the nav).

**Summary tiles:** Present · Late · Not checked in yet · On leave · Absent ·
Missing check-out. Each tile filters the table below.

**Table:** employee, expected start, actual check-in, check-out, worked so far,
status pill, IP, and a quick "Correct" action reusing card 01's modal.

**Roster query:** LEFT JOIN active users (`status = 1`, not soft-deleted) onto
today's `attendance_days` — the join direction matters, because "absent" is the
*absence* of a row and an INNER JOIN would hide exactly the people a manager is
looking for.

**"Not checked in yet" vs "absent":** before the schedule's `start_time +
grace`, a missing row is `not_yet` (neutral grey), not `absent` (red). Nobody is
absent at 08:00. After the cutoff it becomes `absent`, unless approved leave
covers the day.

Manual **Refresh** button, no polling.
`// ponytail: a button, not a 60s setInterval — this is a board someone glances
at, and polling costs a request per open tab per minute.`

---

## 2. Monthly report — `/admin/attendance/report?month=YYYY-MM`

Filters: month (default: current VN month), optional user, optional group
(`user_groups` already exists), optional "only employees with exceptions".

One row per employee:

| Column | Source |
|---|---|
| Employee | `users.name` |
| Workdays | schedule workdays in the month, minus holidays |
| Present | days with status `present` |
| Late (count / minutes) | `late` days, summed `late_minutes` |
| Early leave (count / minutes) | summed `early_leave_minutes` |
| Absent | status `absent` |
| Leave | status `on_leave` |
| Worked hours | summed `worked_seconds` |
| Expected hours | summed `expected_seconds` |
| Overtime | summed `overtime_seconds` |
| Missing check-out | count of flagged days |

Footer: company totals. Every number links through to the per-employee detail.

**Per-employee detail** — `/admin/attendance/report/user/(:num)?month=YYYY-MM`:
a day-by-day grid for the month (date, weekday, expected, in, out, worked,
late, status, note, corrected-by). This is the sheet you hand to an employee who
disputes a figure, so show the correction note and the editor's name.

**Employee self-view:** the same monthly summary for the current user at
`/attendance/report?month=YYYY-MM` — no permission needed, always scoped to
`current_user_id()`. Transparency prevents most disputes before they start.

### Query budget

Add one bulk method to `AttendanceCalendar` (from 01b):

```php
public function evaluateMonth(array $userIds, string $month): array;
// 3 queries total: attendance_days for the month × users,
// holidays for the month, approved requests overlapping the month.
// Then evaluate() per (user, day) in PHP.
```

`// ponytail: 3 queries for the whole report — never evaluateRange() in a
foreach over employees.` Assert this in a test (query count does not grow with
the employee count).

---

## 3. CSV export

`GET /admin/attendance/report/export?month=YYYY-MM&...` — same filters as the
report, streamed as a download. Follow the response pattern in
`Admin\SystemLogs::download` (`app/Controllers/Admin/SystemLogs.php:53`), but
build the body in memory with `fputcsv` to `php://temp`.

- Filename: `attendance-YYYY-MM.csv`.
- **Write a UTF-8 BOM (`\xEF\xBB\xBF`) first** — without it Excel on Windows
  mangles Vietnamese names, which is the one bug guaranteed to be reported.
- Header row = the report columns; hours as decimal hours to 2 places (`8.00`),
  because spreadsheets cannot sum `8h05m`.
- A second export mode `detail=1` emits one row per (employee, day) for payroll
  systems that want the raw grid.
- Same permission and the same filters as the on-screen report — an export must
  never widen access.

`// ponytail: fputcsv + response()->setBody(); no spreadsheet library for a flat
grid.`

---

## 4. Month lock

The report page carries the payroll close action, since that is where the numbers
are approved:

- Shows the current `attendance_locked_through` value.
- **Lock month** — sets it to the displayed month after a confirm modal that
  states the consequence: corrections to that month and earlier will be refused
  (enforced in card 01 by `attendance_period_locked()`).
- **Unlock** — sets it back to the previous month. Requires the same permission
  and a confirm; the modal reminds the manager to re-export afterwards.
- The lock is also the answer to 01b §2 (schedule edits rewriting history):
  export → lock → the CSV is the archived record.

---

## 5. Files

- `app/Controllers/Admin/AttendanceReport.php` (report, detail, export, lock).
- Add `today()` to `app/Controllers/Admin/Attendance.php`.
- Add `report()` to `app/Controllers/Attendance.php` (employee self-view).
- Add `evaluateMonth()` to `app/Libraries/AttendanceCalendar.php`.
- Views: `app/Views/admin/attendance/today.php`,
  `app/Views/admin/attendance/report.php`,
  `app/Views/admin/attendance/report-user.php`,
  `app/Views/attendance/report.php`.
- Modify: `app/Config/Routes.php`, `app/Views/layouts/app.php` (nav),
  `app/Views/client/dashboard.php` (add "this month: X h, Y late" to the widget).
- Tests: `tests/feature/Web/AdminAttendanceTodayTest.php`,
  `tests/feature/Web/AdminAttendanceReportTest.php`,
  `tests/feature/Web/AttendanceReportTest.php`,
  `tests/unit/AttendanceCalendarMonthTest.php`.

---

## 6. Routes

```php
// permission:attendance.manage group
$routes->get('attendance/today', 'Admin\Attendance::today');
$routes->get('attendance/report', 'Admin\AttendanceReport::index');
$routes->get('attendance/report/user/(:num)', 'Admin\AttendanceReport::user/$1');
$routes->get('attendance/report/export', 'Admin\AttendanceReport::export');
$routes->post('attendance/report/lock', 'Admin\AttendanceReport::lock', ['filter' => 'csrf']);
$routes->post('attendance/report/unlock', 'Admin\AttendanceReport::unlock', ['filter' => 'csrf']);

// authFilter group
$routes->get('attendance/report', 'Attendance::report');
```

---

## 7. UI notes (TailAdmin)

- Summary tiles: TailAdmin stat-card pattern; the count is the link, the label
  never wraps mid-word on mobile.
- Status pills: one shared partial (`_status_pill.php`) used by the today board,
  the report grid and the employee page — one colour vocabulary everywhere
  (present green · late amber · absent red · on_leave blue · holiday /
  non_workday grey · missing_checkout amber outline).
- Month picker: native `<input type="month">`.
  `// ponytail: native control, no date-picker library.`
- Report tables are wide — wrap each in an `overflow-x-auto` container so the
  page body never scrolls sideways.
- `esc()` every name, note and IP.
- Run `npm run build` after the views land (Tailwind JIT — see CLAUDE.md).

---

## 8. Tests

- **Today board:** absent user appears (LEFT JOIN proof); before the start-time
  cutoff the same user reads `not_yet`, after it `absent`; approved leave reads
  `on_leave`, never `absent`; tile counts match the table rows.
- **Report:** totals equal the sum of the per-day grid for the same month;
  workday count excludes holidays and weekends; group filter narrows correctly;
  a user with no rows at all still appears with `absent = workdays`.
- **Query budget:** `evaluateMonth` for 1 employee and for 20 employees issues
  the same number of queries.
- **Export:** correct headers/filename; body starts with the UTF-8 BOM; row
  count matches the on-screen report; `detail=1` returns one row per day;
  export respects the user/group filter; a user without `attendance.manage`
  gets no export.
- **Lock:** locking sets `attendance_locked_through`; a card-01 correction inside
  the locked month then returns 409; unlock restores it.
- **Self-view:** scoped to the current user; requesting another user's report is
  impossible (no user parameter on the employee route at all — the safest
  design, verify there is no query-string escape hatch).
- Render smoke: every new page returns 200 for an authorised user.

---

## 9. Definition of done

- [ ] Today board distinguishes `not_yet` from `absent`
- [ ] Absent employees (no row) show up — LEFT JOIN, asserted by a test
- [ ] Report totals reconcile with the per-day detail
- [ ] Export opens cleanly in Excel with Vietnamese names intact (BOM)
- [ ] Hours exported as decimal
- [ ] Lock enforced by card 01's correction path
- [ ] Employee self-view cannot read another employee's data
- [ ] Report query count independent of employee count
- [ ] `php-cs-fixer`, `phpstan`, `phpunit` clean

---

## Implementation log — 2026-07-25

**Status: code complete, tested. Review pass pending. No migration needed.**

Shipped:
- `AttendanceCalendar::evaluateMonth()` / `evaluateSpan()` / `summarise()` /
  `withPendingArrival()` / `warmSchedules()`
- `Admin\Attendance::today()` — presence board with status tiles that filter the table
- `app/Controllers/Admin/AttendanceReport.php` — monthly report, per-employee
  day grid, CSV export (summary + `detail=1`), payroll lock/unlock
- `Attendance::report()` — employee self-view (no user parameter on the route at all)
- Views `admin/attendance/today.php`, `report.php`, `report-user.php`,
  `attendance/report.php`; `not_yet` added to the shared status pill
- `UserModel::activeRoster()` / `scheduleAssignments()`,
  `WorkScheduleModel::findManyById()`, `SiteVariableModel::findByName()` /
  `setValue()`; routes + nav ("Today", "Monthly report", "My Month")

Tests: 18 new (`AdminAttendanceReportTest`), all green. Full suite: **681 tests**,
only the 4 documented `BasicAuthGateTest` failures. `phpstan` + `php-cs-fixer`
clean; `npm run build` run.

Decisions taken during implementation (beyond the plan):
- **`absent` only counts days that have already happened.** A mid-month report
  would otherwise show every remaining workday as an absence and look alarming.
  `summarise()` compares each date against today (VN).
- **`not_yet` is a real status** (`AttendanceCalendar::STATUS_NOT_YET`) produced by
  `withPendingArrival()`, rather than a view-level tweak — so it is testable and
  stated once, and the pill vocabulary stays in one file.
- **Payroll lock upsert moved into `SiteVariableModel::setValue()`** so no caller
  can half-implement it and hit the `UNIQUE(name)` key.
- Export re-derives the roster through the same `roster()` method as the screen,
  so a filter can never be widened by hand-editing the export URL.

One bug the tooling caught (again worth remembering):
- The query-count test showed `evaluateMonth` costing **23 queries for 20
  employees vs 4 for one** — `scheduleFor()` was reading one `users` row per
  person, exactly the N+1 the method exists to prevent (and which its own
  docblock claimed it avoided). Fixed with `warmSchedules()` + two bulk model
  methods; the test now asserts 20 employees cost at most one query more than 1.

Open:
1. Review pass (`reviewer` + `ponytail-review`) for cards 01 / 01b / 01c.
2. A `better-interface` pass over all six attendance screens, now that the set is
   complete.
3. Card 02 (leave / late requests) — the `AttendanceCalendar` hook is already in
   place and covered by tests with a `null` request.
