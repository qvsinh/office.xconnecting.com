# 01d — Attendance reminders & notifications

**Goal:** Stop the two failure modes that quietly corrupt attendance data —
employees forgetting to check out, and managers not noticing an absence until
payroll day — by reusing the notification, email-template and scheduled-task
infrastructure the starter already ships.

**Depends on:** card 01 (`attendance_days`, nightly job pattern) and card 01b
(`AttendanceCalendar`, schedule times). Card 02 adds the pending-request
reminder in §3.4.

**Reused infrastructure (no new plumbing):** `ScheduledTaskRunner::registry()`
handler allowlist + `scheduled_tasks` rows (`php spark schedule:run` from cron),
`NotificationModel`, `EmailTemplateModel` + the sent-email log.

---

## 1. Idempotency first

Cron calls `schedule:run` every minute. Anything here that sends must be safe to
call 1 440 times a day.

- **Per-employee reminders** are guarded by a column, not by timing:
  `attendance_days.checkout_reminded_at` DATETIME null (migration) — a row is
  reminded at most once, and the column doubles as evidence when someone claims
  they were never told.
- **Digests** are guarded by the existing `scheduled_tasks.interval_minutes` +
  `next_run_at` bookkeeping the runner already maintains (set `1440`).
- Handlers return a short count string for `last_result`
  (`"Reminded 4 employee(s)."`), so the admin task list shows what happened.

`// ponytail: one nullable column for the only genuinely per-row reminder; the
runner's own scheduling covers the rest.`

---

## 2. Send-time resolution

Reminder times are derived from the schedule (01b), not configured twice:

- check-out reminder fires from `end_time + 15 min` (VN)
- absence digest fires from `start_time + grace + 30 min` (VN)

The `scheduled_tasks` row runs every 15 minutes and each handler decides whether
the VN clock has passed its own cutoff. That keeps one cron entry and no second
copy of the office hours.
`// ponytail: derive from work_schedules; a duplicated "18:15" in settings would
drift the first time hours change.`

---

## 3. Handlers to register

### 3.1 `attendance.remind_checkout`

Employees with today's `checkin_at` set, `checkout_at` null,
`checkout_reminded_at` null, past `end_time + 15 min` → in-app notification
("You have not checked out yet"), optional email if the employee has an address
and `attendance_email_reminders = 1`. Stamp `checkout_reminded_at`.

### 3.2 `attendance.absence_digest`

For the current VN day, past the cutoff: build the roster exactly as the today
board does (LEFT JOIN active users) and collect `absent`, `late` and
`missing_checkout` employees. If the list is non-empty, notify **every user
holding `attendance.manage`** with the counts and names. Empty list → send
nothing (a daily "all good" mail trains people to ignore it).

### 3.3 `attendance.missing_checkout_digest` (weekly)

Rows flagged `missing_checkout` in the last 7 days and still uncorrected →
one notification to `attendance.manage` holders. These block the monthly report,
so surfacing them weekly avoids a month-end scramble.

### 3.4 `requests.pending_digest` (needs card 02)

Leave/late requests still `pending` after 24 h → notify `requests.approve`
holders. Employees are waiting on an answer; silence is the worst outcome.

---

## 4. Site variables (seeded, guarded)

| Name | Default | Meaning |
|---|---|---|
| `attendance_email_reminders` | `'0'` | also send reminders by email, not just in-app |
| `attendance_absence_digest` | `'1'` | daily manager digest on/off |

Each handler checks its flag first and returns `"Disabled."` when off — visible
in the task list, rather than a silently idle job.

---

## 5. Files

- Migration `2026-07-22-014000_AddCheckoutRemindedAtToAttendanceDays.php`.
- Migration `2026-07-22-014100_SeedAttendanceReminderVariables.php`.
- Migration `2026-07-22-014200_SeedAttendanceEmailTemplates.php` — templates for
  the check-out reminder and the manager digest (follow
  `2026-07-14-135000_CreateEmailTemplates.php` and the existing seeded templates).
- `app/Libraries/AttendanceReminders.php` — the handler bodies; keep
  `ScheduledTaskRunner` a thin allowlist + dispatcher, as its docblock demands.
- Modify: `app/Libraries/ScheduledTaskRunner.php` (registry entries),
  `app/Models/AttendanceDayModel.php` (the "needs reminding" / "digest roster"
  queries).
- Tests: `tests/database/AttendanceRemindersTest.php`,
  `tests/unit/AttendanceReminderCutoffTest.php`.

Also insert the `scheduled_tasks` rows (interval 15 for the two daily handlers,
`1440`/`10080` for the digests) in a guarded seed migration so a fresh install
has them without manual setup.

---

## 6. Tests

- Running a handler twice in a row sends **once** (the column guard).
- Before the cutoff: nothing sent. After: sent.
- An employee who checked out is never reminded.
- Absence digest with an empty roster sends nothing.
- Digest recipients are exactly the `attendance.manage` holders (a plain
  employee receives nothing).
- `attendance_email_reminders = 0` → in-app notification only, no row in the
  sent-email log.
- Disabled flag → handler returns `"Disabled."` and sends nothing.
- Cutoff maths uses VN time (freeze the clock at a UTC instant where the VN date
  differs).

---

## 7. Definition of done

- [ ] Every handler idempotent under minute-by-minute cron
- [ ] Reminder times derived from `work_schedules`, not duplicated in settings
- [ ] Digests go only to permission holders
- [ ] Nothing sent when there is nothing to say
- [ ] `scheduled_tasks` rows seeded; visible and runnable from the admin screen
- [ ] `php-cs-fixer`, `phpstan`, `phpunit` clean

---

## Implementation log — 2026-07-25

**Status: code complete, tested, migrated on dev. Review pass pending.**

Shipped:
- `2026-07-22-014000_AddCheckoutRemindedAtToAttendanceDays` ·
  `-014100_SeedAttendanceReminderVariables` · `-014200_SeedAttendanceReminderTasks`
  (4 schedules, enabled)
- `app/Libraries/AttendanceReminders.php` — the four handler bodies
- Registered in `ScheduledTaskRunner::registry()`: `attendance.remind_checkout`,
  `attendance.absence_digest`, `attendance.missing_checkout_digest`,
  `requests.pending_digest`
- `UserModel::withPermission()` — digest recipients, mirroring `user_can()`
  semantics (admin-zone users are implicit holders)
- `AttendanceDayModel::needingCheckoutReminder()` / `markCheckoutReminded()` /
  `uncorrectedMissingCheckouts()`; `LeaveRequestModel::pendingOlderThan()`

Tests: 16 new (`AttendanceRemindersTest`). Full suite: **740 tests**, only the 4
documented `BasicAuthGateTest` failures. `phpstan` + `php-cs-fixer` clean;
`php spark migrate` applied.

Decisions taken during implementation (departures from the plan):
- **No `attendance_email_reminders` flag, and no email templates.** This app
  manages templates and a sent-email log but has **no SMTP dispatch** yet
  (DEPLOYMENT.md, "Email send pipeline"). Shipping a switch that silently does
  nothing is worse than not shipping it — delivery is in-app notifications only
  until the send pipeline lands. The plan's §3.1/§4/§5 email items are dropped
  rather than faked.
- **Cutoffs are derived per employee from their own schedule**, so someone on a
  10:00 start is not reported late at 09:15. The absence digest skips employees
  whose day has not begun rather than bailing out of the whole run.
- **Every handler reports why it did nothing** (`Disabled.`, `Too early…`,
  `Nothing to report…`, `Nobody holds attendance.manage…`) so the admin
  scheduled-tasks screen never shows a silently idle job.

Two things the tests caught:
- The absence digest originally `return`ed out of the whole handler on the first
  employee whose day had not started, so one late-shift worker could suppress the
  entire digest.
- Managers are on the same roster, so a manager who has not checked in appears in
  their own digest. That is correct behaviour (the test fixtures were wrong, not
  the code) — worth knowing before someone reports it as a bug.

**Operational requirement:** none of this fires without the OS cron entry —
`* * * * * cd /path/to/app && php spark schedule:run >> writable/logs/schedule.log 2>&1`.
Until that exists on the EC2 host, the four schedules sit idle and the
"Run now" button in `/admin/scheduled-tasks` is the only trigger.

Open:
1. Review pass (`reviewer` + `ponytail-review`) across cards 01 / 01b / 01c / 01d / 02.
2. `better-interface` pass over the eight attendance/leave screens.
