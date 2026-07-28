# 02 — Leave / Late-arrival Requests (with approval + leave balance)

**Goal:** Employees submit a **leave** request (single day, half day, or a date
range) or a **late-arrival** request, each with a reason. Requests are `pending`
until a manager **approves** or **rejects** them (with an optional review note).
An approved request changes how the day is reported in attendance, and annual
leave draws down a balance. Web only.

**Architecture:** Same module shape as attendance. One table with a `type`
discriminator.

**Depends on:** card 01 (`requests.approve` permission) and card **01b**
(`AttendanceCalendar` — needed to count leave days correctly and to make an
approved request suppress `absent` / `late`).

> The earlier draft said this card "depends on nothing from module 01". That was
> the plan's biggest functional gap: a leave request that does not affect the
> attendance sheet leaves the employee marked **absent** on a day the company
> approved off, and an approved late arrival still counted as **late**. The
> integration is the point of the feature, not an extra.

---

## 1. Data model

**`leave_requests`**

| Column | Type | Notes |
|---|---|---|
| `id` | INT UAI | |
| `user_id` | BIGINT | |
| `type` | VARCHAR(10) | `leave` \| `late` |
| `leave_kind` | VARCHAR(10) null | for `leave`: `annual` \| `unpaid` \| `sick`; null for `late` |
| `start_date` | DATE | |
| `end_date` | DATE null | range end; defaults to `start_date` |
| `day_portion` | VARCHAR(10) | `full` \| `morning` \| `afternoon` (single-day leave only) |
| `expected_time` | TIME null | `late`: planned arrival |
| `reason` | TEXT | |
| `status` | VARCHAR(10) | `pending` \| `approved` \| `rejected` \| `cancelled`, default `pending` |
| `reviewed_by` | BIGINT null | |
| `reviewed_at` | DATETIME null | |
| `review_note` | VARCHAR(255) null | |
| `created_at` / `updated_at` | DATETIME null | |

Keys: Index(`user_id`), Index(`status`), Index(`start_date`, `end_date`) — the
calendar looks requests up by date range, so index for that access path.

**`cancelled` is a status, not a delete.** The earlier draft floated hard-deleting
an employee's own pending row; don't. A withdrawn request is part of the record a
manager may later need to see, and a `status` transition costs nothing.
`// ponytail: one more enum value beats a soft-delete column.`

**Half days:** `day_portion` only applies to a single-day `leave`
(`start_date == end_date`); a multi-day range is always `full`. Counts as 0.5
days against the balance and reports as `on_leave` for that half.
`// ponytail: three-value enum, no start/end times per leave — nobody asks for
"off from 14:15".`

---

## 2. Leave balance

- Quota: `site_variable` **`leave_annual_days`** default `'12'` (Vietnamese
  statutory minimum), overridable per employee by `users.annual_leave_days`
  BIGINT null (migration; null → the site default).
- **Used** = sum of `day_portion`-weighted days across `approved` requests with
  `leave_kind = annual` whose dates fall in the calendar year — **counting only
  scheduled workdays and skipping holidays**, via `AttendanceCalendar`. A leave
  range spanning a weekend must not burn weekend days.
- **Remaining** = quota − used. Shown on the employee page and on the approval
  screen, so a manager approves with the balance in view.
- `unpaid` and `sick` do not draw down annual leave; they still mark the day
  `on_leave` in attendance.
- Over-drawing is a **warning, not a hard block** — a manager may approve
  unpaid-equivalent overflow deliberately. The approval modal states
  "This exceeds the remaining balance (2.5 days left, request is 4 days)".
- No carry-over between years.
  `// ponytail: quota − used, computed on read; no leave_balances table, no
  accrual schedule. Add one only if carry-over or monthly accrual is required.`

---

## 3. Files

- Migration `2026-07-22-020000_CreateLeaveRequests.php` (guarded, tests-down).
- Migration `2026-07-22-020100_SeedLeaveVariables.php` (`leave_annual_days`).
- Migration `2026-07-22-020200_AddAnnualLeaveDaysToUsers.php`.
- `app/Models/LeaveRequestModel.php`.
- `app/Controllers/Requests.php` (employee), `app/Controllers/Admin/Requests.php`.
- Views `app/Views/requests/index.php`, `app/Views/admin/requests/index.php`.
- Modify: `app/Config/Routes.php`, `app/Views/layouts/app.php` (nav),
  `app/Libraries/AttendanceCalendar.php` (consume approved requests — §5),
  `app/Views/client/dashboard.php` (remaining-balance line).
- Tests: `tests/database/LeaveRequestModelTest.php`,
  `tests/feature/Web/RequestsTest.php`, `tests/feature/Web/AdminRequestsTest.php`,
  `tests/unit/LeaveBalanceTest.php`.

---

## 4. Model methods

- `forUser(int $userId, int $limit, int $offset): array` + `countForUser`.
- `searchAdmin(array $filters, ...)` + `countAdmin` — filters `status`, `type`,
  `leave_kind`, `user_id`, date range; join `users` for the name; pending first,
  then newest. Sort via a `SORT_ALLOWLIST` like `UserModel`.
- `countPending(): int` — admin nav badge.
- `createRequest(array $data): int`, `findRequest(int $id): ?object`.
- `approve(int $id, int $reviewerId, ?string $note): bool` /
  `reject(...)` / `cancelOwn(int $id, int $userId): bool` — each asserts the
  current status is `pending` **inside the update's WHERE clause**, so two
  managers clicking Approve simultaneously cannot both win.
  `// ponytail: WHERE status='pending' is the lock; no transaction needed.`
- **Integration contract required by 01b:**
  - `approvedForRange(int $userId, string $from, string $to): array`
  - `approvedForDate(int $userId, string $date): ?object`
  - `approvedForUsersInRange(array $userIds, string $from, string $to): array`
    — the bulk loader `AttendanceCalendar::evaluateMonth()` uses (one query for
    the whole report).
- `overlapsExisting(int $userId, string $from, string $to, ?int $ignoreId): bool`
  — used by validation (§6).
- `annualDaysUsed(int $userId, int $year): float` — workday-weighted, via the
  calendar.

---

## 5. Attendance integration (the part that matters)

`AttendanceCalendar::evaluate()` receives the approved request for the day and:

| Approved request | Effect on the day |
|---|---|
| `leave`, `day_portion = full` | status `on_leave`; never `absent`; `expected_seconds = 0` |
| `leave`, half day | `on_leave` for that half; `expected_seconds` halved; a check-in for the other half still reports worked hours and is not `late` for the missing half |
| `late` | `late_minutes = 0`, `late_excused = true`, status `present` |

The employee page, today board, monthly report and CSV all inherit this because
they all go through the calendar (01b §4). No second implementation.

**Approving retroactively must work**: approving leave for last Tuesday changes
last Tuesday's status on the next page load — that is exactly why status is
derived rather than stored. But approval **must be refused** when the date falls
in a month closed by `attendance_locked_through` (payroll already paid); surface
that as a 409 with a clear message.

---

## 6. Controller behaviour

### `Requests` (employee, `authFilter`)

- `index()` — my requests (paginated) + create form + **remaining annual leave**.
- `create()` — validation:
  - `type` `in_list[leave,late]`
  - `leave` → `leave_kind` `in_list[annual,unpaid,sick]`; `late` → `leave_kind` empty
  - `reason` required, max 1000
  - `start_date` required `valid_date`
  - `end_date` `permit_empty valid_date`, `>= start_date`, defaults to `start_date`
  - `day_portion` `in_list[full,morning,afternoon]`; must be `full` when
    `end_date > start_date`
  - `late` → `expected_time` `permit_empty` `HH:MM`, and after the schedule's
    `start_time` (a "late" arrival before 08:30 is not late)
  - **no overlap** with the employee's own `pending`/`approved` requests
    (`overlapsExisting`) → "You already have a request covering 2026-07-23."
  - the range must contain **at least one workday** — a leave request for a
    Sunday is a mistake worth catching at submit time
  - month not locked (§5)
  - past dates allowed (sick leave is retroactive by nature) but flagged in the
    UI as backdated so a manager notices
- `cancel($id)` — own row, `pending` only → `cancelled` (IDOR: scope to
  `current_user_id()`, 404 otherwise).

### `Admin\Requests` (`authFilter` + `permission:requests.approve`)

- `index()` — filterable table, `pending` first. Per row: employee, type/kind,
  dates + portion, workdays counted, reason, backdated badge, remaining balance,
  status, reviewer + note.
- `approve($id)` / `reject($id)` — `pending` only (else 409); records reviewer,
  timestamp, optional note; refuses a locked month; notifies the employee via
  `NotificationModel` so the decision is not silent.

---

## 7. UI (TailAdmin, mobile-first)

- **Employee:** a "Remaining annual leave: 8.5 / 12 days" card, then
  "New request" → modal with a `type` toggle (Leave / Late) revealing the right
  fields (Leave → kind, start/end date, portion; Late → date + expected time),
  plus reason. The modal shows the computed **workdays counted** live as dates
  change — the number that will be deducted, before submitting.
  List below with status pills (pending amber, approved green, rejected red,
  cancelled grey) and Cancel on own pending rows.
- **Manager:** filter form + table; Approve / Reject per pending row (reject opens
  a note modal). Approve shows the balance warning when over-drawn.
- Shared toast / confirm / alert components; `esc()` everywhere;
  `npm run build` after view edits.

---

## 8. Nav

- Client "Attendance" group: `My Requests` (`/requests`).
- Management group: `Requests` (`/admin/requests`) with the `countPending()`
  badge; render only when `user_can('requests.approve')`.

---

## 9. Routes

```php
// authFilter group
$routes->get('requests', 'Requests::index');
$routes->post('requests/create', 'Requests::create', ['filter' => 'csrf']);
$routes->post('requests/cancel/(:num)', 'Requests::cancel/$1', ['filter' => 'csrf']);

// management group
$routes->group('admin', ['filter' => ['authFilter', 'permission:requests.approve']], static function ($routes) {
    $routes->get('requests', 'Admin\Requests::index');
    $routes->post('requests/approve/(:num)', 'Admin\Requests::approve/$1', ['filter' => 'csrf']);
    $routes->post('requests/reject/(:num)', 'Admin\Requests::reject/$1', ['filter' => 'csrf']);
});
```

---

## 10. Tests

**Employee**
- create leave (range), half-day leave, late request with time
- validation: bad type; empty reason; `end_date < start_date`; half day on a
  range; late time before `start_time`; range with no workday; overlapping an
  existing pending/approved request; locked month
- cancel own pending only → `cancelled` (another user's → 404; non-pending → 409)
- balance line reflects approved annual leave only (unpaid/sick do not deduct)

**Manager**
- gating: a user without `requests.approve` is redirected
- approve sets status + reviewer + timestamp; reject stores the note
- approving an already-reviewed request → 409
- two concurrent approvals: exactly one succeeds (WHERE-clause lock)
- approval inside a locked month → 409
- the employee receives a notification on decision

**Balance (`LeaveBalanceTest`, unit)**
- a Mon–Fri range = 5 days; a Fri–Mon range = 2 days (weekend skipped)
- a range containing a holiday excludes it
- half day = 0.5
- per-user `annual_leave_days` override beats the site default
- over-draw is reported, not blocked

**Integration with attendance (the regression that matters most)**
- approved full-day leave → that date reports `on_leave`, not `absent`
- approved half-day leave + a real check-in → hours recorded, not `late` for the
  absent half, `expected_seconds` halved
- approved late request → `late_minutes = 0`, `late_excused = true`
- a `pending` (not yet approved) request changes **nothing** in attendance
- a `cancelled` request changes nothing
- the monthly report's leave column matches the approved requests

---

## 11. Definition of done

- [ ] Approved leave suppresses `absent`; approved late suppresses `late`
- [ ] Leave days counted on workdays only, holidays excluded
- [ ] Balance visible to both employee and approver before deciding
- [ ] Overlapping requests rejected at submit time
- [ ] Concurrent approval cannot double-review a request
- [ ] Locked months refuse approval
- [ ] Employee notified of the decision
- [ ] `php-cs-fixer`, `phpstan`, `phpunit` clean

---

## Implementation log — 2026-07-25

**Status: code complete, tested, migrated on dev. Review pass pending.**

Shipped:
- `2026-07-22-020000_CreateLeaveRequests` · `-020100_SeedLeaveVariables`
  (`leave_annual_days = 12`) · `-020200_AddAnnualLeaveDaysToUsers`
- `app/Models/LeaveRequestModel.php` — incl. `review()` / `cancelOwn()` (WHERE-clause
  locks), `approvedForUsersInRange()` bulk loader, `overlapsExisting()`
- `app/Libraries/LeaveBalance.php` — workday-weighted cost, quota, used, remaining
- `app/Controllers/Requests.php` (employee, + a `preview` endpoint for the live
  day counter), `app/Controllers/Admin/Requests.php` (approval queue)
- Views `requests/index.php`, `admin/requests/index.php`; routes on
  `permission:requests.approve`; nav "My Requests" + "Approve requests"
- **`AttendanceCalendar` now consumes approved requests** in `evaluateSpan()`
  (one query per span), so every attendance screen inherits the integration

Tests: 43 new — `LeaveBalanceTest` (20), `RequestsTest` (23). Full suite:
**724 tests**, only the 4 documented `BasicAuthGateTest` failures. `phpstan` +
`php-cs-fixer` clean; `npm run build` run; `php spark migrate` applied.

Decisions taken during implementation (beyond the plan):
- **`evaluateRange()` now delegates to `evaluateSpan()`.** Two implementations of
  "load rows + holidays + schedules + requests" would drift; the single-employee
  case is just a one-element span.
- **The calendar guards on `tableExists('leave_requests')`** so an install that has
  not migrated card 02 yet degrades to "nobody requested anything" instead of
  breaking every attendance screen.
- **Submitting is refused while impersonating**, same reasoning as check-in: a
  request filed under someone else's name is a statement they never made.
- **A `leave` beats a `late` on the same date** — being off beats arriving late.
- **The day counter in the form asks the server** (`/requests/preview`) rather than
  re-implementing the workday/holiday rule in JavaScript.
- **`leave_kind` is validated in the controller**, not the rule set: "required only
  when type = leave" is a conditional CI4's rules can't express.

One bug the tests caught:
- `annual_leave_days` was missing from `UserModel::$allowedFields`, so a
  per-employee quota override would have been silently discarded — the same class
  of bug as `work_schedule_id` in card 01b. Both are now listed with comments.

Open:
1. Review pass (`reviewer` + `ponytail-review`) across cards 01 / 01b / 01c / 02.
2. `better-interface` pass over the eight attendance/leave screens.
3. Card 01d (reminders & digests) is still in `kanban/plan/` — the last piece.
