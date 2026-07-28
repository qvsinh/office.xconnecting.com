# 01b — Work schedule, holidays, and derived day status

**Goal:** Give the raw check-in/check-out facts from card **01** a baseline to be
measured against, so the system can answer "who is late", "who is absent", and
"how many paid hours did this person work" instead of only "when did they press
the button".

**Depends on:** card 01 (`attendance_days`, `attendance_helper`,
`attendance.manage`). **Card 02** (leave / late requests) plugs into the status
derivation defined here — see §7.

**Company baseline (given):** start **08:30**, end **18:00**, lunch break
**90 minutes** → **8h00 net** paid per working day, Monday–Friday.

---

## 1. Data model

### `work_schedules`

| Column | Type | Default | Notes |
|---|---|---|---|
| `id` | INT UAI | | |
| `name` | VARCHAR(100) | | e.g. `Office (08:30–18:00)` |
| `start_time` | TIME | `08:30:00` | expected arrival |
| `end_time` | TIME | `18:00:00` | expected departure |
| `break_start` | TIME | `12:00:00` | lunch start |
| `break_minutes` | SMALLINT | `90` | lunch length |
| `grace_minutes` | SMALLINT | `0` | lateness tolerance; `0` = strict |
| `workdays` | VARCHAR(20) | `1,2,3,4,5` | ISO-8601 day numbers, `1` = Monday |
| `is_default` | TINYINT(1) | `0` | exactly one row is the default |
| `created_at` / `updated_at` | DATETIME null | | |

Seed one default row with the company baseline above (guarded seed migration).

`users.work_schedule_id` BIGINT null — added by migration; `null` falls back to
the default schedule. That covers a future second shift without building a
shift-planning system now.
`// ponytail: one schedule per user, no rotation. Add a rota table only when
someone actually rotates.`

**Break as an interval, not a flat subtraction.** Deducting
`break_minutes` unconditionally would over-deduct from a half-day (someone who
leaves at 11:00 never took lunch). Instead deduct the **overlap** between
`[checkin_at, checkout_at]` and `[break_start, break_start + break_minutes]`.
One interval intersection, exactly right for full days, half-days and overtime
alike — no magic thresholds.

### `holidays`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UAI | |
| `holiday_date` | DATE | **UNIQUE** |
| `name` | VARCHAR(100) | e.g. `Tết Nguyên Đán` |
| `created_at` / `updated_at` | DATETIME null | |

No recurrence rules: Vietnamese public holidays are largely lunar and shift every
year, and the compensating-day-off decree is announced annually. An admin enters
the year's list once (a paste-friendly multi-line "add dates" input helps).
`// ponytail: flat date list, no RRULE — the lunar calendar makes recurrence
wrong more often than right.`

---

## 2. Retroactive-edit caveat (read before implementing)

Status and paid hours are **derived at read time** from the current schedule and
holiday list. Consequence: editing the schedule changes how *past* months are
reported.

The protection is already in card 01: `attendance_locked_through`. The workflow
is — close the month → export the CSV (card 01c) → set the lock. After that the
exported file is the payroll record and later schedule edits cannot rewrite what
was paid.

`// ponytail: no temporal versioning of schedules; the monthly lock + export is
the archive. If schedule history must be reconstructable in-app, add
effective_from versioning to work_schedules and resolve by work_date.`

---

## 3. Derived day status

One value per (user, date), computed in priority order — first match wins:

| Status | Condition |
|---|---|
| `holiday` | date is in `holidays` |
| `non_workday` | weekday not in the schedule's `workdays` |
| `on_leave` | an **approved** leave request covers the date (card 02) |
| `absent` | a workday with no `attendance_days` row and no approved leave |
| `missing_checkout` | row has `checkin_at`, no `checkout_at` |
| `late` | `checkin_at` (VN) > `start_time + grace_minutes` |
| `present` | everything else |

Alongside the status, the same call returns:

- `late_minutes` — `max(0, checkin − (start + grace))`; **0 when an approved
  late-arrival request covers the date** (the request is the excuse, so the day
  reads `present` with `late_excused = true` rather than `late`)
- `early_leave_minutes` — `max(0, end − checkout)`
- `worked_seconds` — `(checkout − checkin) − break overlap`
- `expected_seconds` — `(end − start) − break_minutes` (28 800 = 8h at baseline)
- `overtime_seconds` — `max(0, worked_seconds − expected_seconds)`

A day can be both late and have overtime; report both, never net them off.

---

## 4. Files

- Migration `2026-07-22-011000_CreateWorkSchedules.php` (guarded).
- Migration `2026-07-22-011100_SeedDefaultWorkSchedule.php` (08:30 / 18:00 /
  12:00 / 90 / `1,2,3,4,5`).
- Migration `2026-07-22-011200_AddWorkScheduleIdToUsers.php`.
- Migration `2026-07-22-011300_CreateHolidays.php` (guarded).
- `app/Models/WorkScheduleModel.php`, `app/Models/HolidayModel.php`.
- `app/Libraries/AttendanceCalendar.php` — the **only** place status/minutes/hours
  are computed. Everything else (employee page, dashboard, report, export, API-less
  views) calls it.
  `// ponytail: one class, one source of truth — three copies of "am I late" is
  how payroll disputes start.`
- `app/Controllers/Admin/WorkSchedules.php`, `app/Controllers/Admin/Holidays.php`.
- Views `app/Views/admin/work-schedules/index.php`, `app/Views/admin/holidays/index.php`.
- Modify: `app/Config/Routes.php`, `app/Views/layouts/app.php` (nav),
  `app/Views/attendance/index.php` (show status + late/overtime on each row),
  `app/Controllers/Admin/Attendance.php` (status column + status filter).
- Tests: `tests/unit/AttendanceCalendarTest.php`,
  `tests/database/WorkScheduleModelTest.php`, `tests/database/HolidayModelTest.php`,
  `tests/feature/Web/AdminWorkSchedulesTest.php`,
  `tests/feature/Web/AdminHolidaysTest.php`.

---

## 5. `AttendanceCalendar` API

```php
final class AttendanceCalendar
{
    public function scheduleFor(int $userId): object;
    // users.work_schedule_id, else the is_default row. Cached per request.

    public function holidayName(string $date): ?string;
    // whole-range lookup loaded once per request, not one query per day

    public function isWorkday(string $date, object $schedule): bool;

    public function evaluate(int $userId, string $date, ?object $row, ?object $approvedRequest = null): object;
    // -> { status, late_minutes, late_excused, early_leave_minutes,
    //      worked_seconds, expected_seconds, overtime_seconds, holiday_name }

    public function evaluateRange(int $userId, string $from, string $to): array;
    // one attendance_days query + one holidays query + one requests query,
    // then evaluate() per day. // ponytail: no N+1 — the range loaders exist
    // precisely so the monthly report stays 3 queries.
}
```

`evaluate()` takes the row and the approved request as **arguments** rather than
querying inside, so the monthly report (card 01c) can load a whole month in three
queries and the unit tests need no database.

---

## 6. Admin UI

- **Work schedules** — small CRUD table (name, hours, break, grace, workdays,
  default). Validation: `end_time > start_time`; break window inside the working
  window; `workdays` a non-empty subset of `1..7`; exactly one default (setting a
  new default clears the old one in the same transaction).
- **Holidays** — year filter + list + add/delete. Bulk add: a textarea of
  `YYYY-MM-DD  Name` lines, one per row, so a whole year goes in at once.
  Duplicate dates are reported, not silently swallowed.
- Both live under the existing management nav group and
  `permission:attendance.manage`.

---

## 7. Card 02 integration contract

Card 02 must expose on `LeaveRequestModel`:

```php
approvedForRange(int $userId, string $from, string $to): array   // rows covering the range
approvedForDate(int $userId, string $date): ?object              // single day
```

`AttendanceCalendar` consumes those:
- approved `leave` covering the date → status `on_leave` (never `absent`)
- approved `late` for the date → `late_minutes = 0`, `late_excused = true`

Until card 02 ships, the argument is `null` and the calendar behaves exactly as
if nobody has requested anything — so **this card is shippable before 02**.

---

## 8. Routes

```php
// inside the permission:attendance.manage group from card 01
$routes->get('attendance/schedules', 'Admin\WorkSchedules::index');
$routes->post('attendance/schedules/create', 'Admin\WorkSchedules::create', ['filter' => 'csrf']);
$routes->get('attendance/schedules/edit/(:num)', 'Admin\WorkSchedules::edit/$1');
$routes->post('attendance/schedules/update', 'Admin\WorkSchedules::update', ['filter' => 'csrf']);
$routes->post('attendance/schedules/delete', 'Admin\WorkSchedules::delete', ['filter' => 'csrf']);

$routes->get('attendance/holidays', 'Admin\Holidays::index');
$routes->post('attendance/holidays/create', 'Admin\Holidays::create', ['filter' => 'csrf']);
$routes->post('attendance/holidays/delete', 'Admin\Holidays::delete', ['filter' => 'csrf']);
```

Deleting a schedule that users reference must be refused (409) — or reassign
those users to the default first. Never orphan a foreign key silently.

---

## 9. Tests — the arithmetic is the feature, cover it properly

`AttendanceCalendarTest` (unit, no DB — pass rows in):

| Case | Expected (baseline 08:30/18:00, 12:00+90m, grace 0) |
|---|---|
| in 08:25, out 18:05 | `present`, worked 8h05m, overtime 5m, late 0 |
| in 08:31, out 18:00 | `late`, late 1m, worked 7h59m |
| in 08:30 exactly | `present`, late 0 (boundary is not late) |
| grace 10, in 08:39 / 08:41 | `present` / `late` 11m |
| in 08:30, out 11:00 (half day) | worked 2h30m — **no break deducted** |
| in 08:30, out 12:45 | break overlap 45m → worked 3h30m |
| in 13:30, out 18:00 (afternoon only) | no overlap → worked 4h30m |
| in 08:30, no out | `missing_checkout`, worked null |
| Saturday | `non_workday` even with a check-in (hours still reported → overtime) |
| date in `holidays` | `holiday`, wins over `non_workday` |
| workday, no row | `absent` |
| workday, no row, approved leave | `on_leave` |
| approved late request + in 09:30 | `present`, `late_excused = true`, late 0 |
| out < in (corrupt/corrected) | worked 0, never negative |

Plus: `evaluateRange` over a month issues a bounded number of queries (assert
with the DB query log, or at least assert it does not scale with day count);
schedule/holiday CRUD gating and validation in the feature tests.

---

## 10. Definition of done

- [ ] Default schedule seeded at 08:30 / 18:00 / 90-minute break / Mon–Fri
- [ ] Break computed as interval overlap, verified by the half-day cases
- [ ] Status priority order matches §3, holiday beating non_workday
- [ ] `AttendanceCalendar` is the only place these numbers are computed
- [ ] Monthly range evaluation does not N+1
- [ ] Retroactive-edit caveat (§2) recorded in the admin UI as a short note
- [ ] `npm run build`; `php-cs-fixer`, `phpstan`, `phpunit` clean

---

## Implementation log — 2026-07-25

**Status: code complete, tested, migrated on dev. Review pass pending.**

Shipped:
- `2026-07-22-011000_CreateWorkSchedules` · `-011100_SeedDefaultWorkSchedule`
  (08:30–18:00 · lunch 12:00 +90m · Mon–Fri · grace 0 → **8h00 net**) ·
  `-011200_AddWorkScheduleIdToUsers` · `-011300_CreateHolidays`
- `app/Models/WorkScheduleModel.php` (incl. `makeDefault()` single-default
  invariant, `usageCount()`), `app/Models/HolidayModel.php` (incl. `mapForRange()`
  / `existingDates()` bulk loaders)
- `app/Libraries/AttendanceCalendar.php` — the single source of status, lateness,
  worked/expected/overtime hours
- `app/Controllers/Admin/WorkSchedules.php`, `app/Controllers/Admin/Holidays.php`
- `app/Views/admin/work-schedules/index.php`, `app/Views/admin/holidays/index.php`,
  `app/Views/attendance/_status_pill.php` (shared pill — 01c reuses it)
- Status column wired into the employee history table and the management table;
  nav gained "Work schedules" + "Holidays"; `users.work_schedule_id` added to
  `UserModel::$allowedFields`

Tests: 47 new, all green — `AttendanceCalendarTest` (21),
`AdminWorkSchedulesTest` (14), `AdminHolidaysTest` (12). Full suite: 663 tests,
only the 4 documented `BasicAuthGateTest` failures. `phpstan` + `php-cs-fixer`
clean; `npm run build` run; `php spark migrate` applied on `db_office_xcn_vn`.

Decisions taken during implementation (beyond the plan):
- **`grace_minutes` decides the flag, not the number.** `late_minutes` always
  counts from the official start time; grace only decides whether the day reads
  `late`. Counting from the grace edge would mean raising the tolerance silently
  shrank every accumulated lateness total in the monthly report.
- **Whole-minute clock arithmetic for lateness** (08:30:45 is not late at an 08:30
  start); second-level arithmetic for worked/overtime, which comes from real
  timestamps.
- **First schedule on a fresh install auto-becomes the default**, and deleting the
  default promotes another — a roster with no default schedule has nothing to
  measure against. Deleting the last schedule, or one still assigned to
  employees, is refused (409).
- **Bad bulk holiday line fails the whole paste** rather than importing a subset:
  a partially-applied holiday list is worse than none.
- `attendance/_status_pill.php` is included with `view()`, not `$this->include()`
  — CI4's `include()` takes *options*, not data, so the partial would have
  received no `$day`.

Two bugs the tooling caught (worth remembering):
- phpstan found `resolveWorkdays()` returning `string` for both success and
  error, which made `validateTimes()` unreachable — schedule create/update would
  have surfaced the workday CSV as a validation error and skipped every time
  check. Now returns `array{workdays: string}|string`.
- The query-count assertion in `AttendanceCalendarTest` caught a holiday cache
  keyed by `from|to`, so a range load and a single-date lookup missed each
  other's cache and a 7-day range cost 7 extra queries. Cache is now keyed by
  date with a loaded-ranges list.

Open:
1. Review pass (`reviewer` + `ponytail-review`) not yet run — card stays in
   `processing/`.
2. A `better-interface` pass over all attendance screens is better run once, after
   card 01c adds the today board and the report, rather than per card.
