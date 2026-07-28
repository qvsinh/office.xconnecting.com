# 01 — Attendance (check-in / check-out)

**Goal:** Employees check in (arrival) and check out (leave) once per day from
the web, only from an allowlisted office IP. Business day/times are Vietnam
time. Managers can view the whole company's attendance and manually correct
entries, with an audit trail. No API (employees must use the web).

**Architecture:** Additive module, same shape as `Admin\Groups`/`Settings`
(controller + model + view + guarded migration + tests). One row per
user-per-day. Office IPs and enforcement flags live in the existing
`site_variable` settings store (admin edits them at `/admin/settings`), so no
new "IP config" screen is needed.

**Scope note:** this card records *facts* (who pressed the button, when, from
where). Lateness, absence, holidays and paid-hours arithmetic need a work
schedule — that is card **01b**, which this card must not pre-empt. Management
dashboard, monthly report and CSV export are card **01c**.

---

## 0. Trusted-proxy prerequisite — RESOLVED 2026-07-25

The whole office-IP feature depends on `IncomingRequest::getIPAddress()`
returning the *employee's* address.

**Deployment (confirmed by the user): nginx directly on the AWS EC2 host, no CDN
in front.** With nginx → php-fpm over **FastCGI**, `REMOTE_ADDR` is already the
visitor's real public address, so `Config\App::$proxyIPs` must stay **empty** —
and CI4 then ignores any client-supplied `X-Forwarded-For` outright, which is
exactly what we want. **No config change needed. Enforcement can be switched on.**

The one thing that would change this: nginx serving the app through
`proxy_pass http://127.0.0.1:…` (a separate HTTP app server or container) instead
of `fastcgi_pass`. Then `REMOTE_ADDR` is `127.0.0.1` for everybody and the
allowlist becomes meaningless until `$proxyIPs` is set.

**Verify in five seconds, don't assume:** `/admin/attendance` prints *"Your
address as the server sees it: X"*. Correct if X is the office's public IP.
Broken if X is `127.0.0.1` (or identical for every employee) — then set the map
form (CI4 4.7 requires **`['proxy-ip' => 'Header']`**; a plain list throws
`ConfigException`):

```php
public array $proxyIPs = ['127.0.0.1' => 'X-Forwarded-For'];   // nginx proxy_pass
public array $proxyIPs = ['10.0.0.0/16' => 'X-Forwarded-For']; // AWS ALB in the VPC
```

Also re-check this if Cloudflare or an ALB is ever put in front: every employee
would suddenly appear to come from the edge, silently. The reminder lives as a
comment at `app/Config/App.php:$proxyIPs`.

Covered by a feature test (`AttendanceTest::testForgedForwardedForHeaderDoesNot
BypassTheAllowlist`): a forged `X-Forwarded-For` / `Client-IP` from an untrusted
client does **not** get past the allowlist. If that test ever starts failing, the
restriction has become spoofable.

To switch enforcement on: set `attendance_office_ips` to the office's public IP
(or CIDR) and `attendance_ip_enforce` to `1` in `/admin/settings`.

---

## 1. Global constraints

- **Storage timezone: UTC.** `app/Config/App.php:136` sets
  `$appTimezone = 'UTC'`, and every existing model writes timestamps with
  `date('Y-m-d H:i:s')` / `useTimestamps`. All new DATETIME columns follow that,
  so `checkin_at` and `created_at` are never 7 hours apart.
  *(The earlier draft of this plan claimed the existing columns were local time.
  They are not.)*
- **Business timezone: `Asia/Ho_Chi_Minh`.** Used for (a) deciding which
  calendar day a timestamp belongs to and (b) all display. Exposed once as
  `attendance_tz()` in the helper — `// ponytail: single-region company, a
  constant; make it a site_variable only if a second country is onboarded`.
- `work_date` is the **VN calendar date of the check-in**, not the UTC date. A
  17:10 UTC check-out on the 21st still belongs to `work_date = 2026-07-22`.
- Access: employee endpoints behind `authFilter`; management endpoints behind
  `authFilter` + `permission:attendance.manage` (see §4). **Not** bare
  `adminFilter` — a department head must be able to manage attendance without
  being a global admin. `PermissionFilter` already lets admin-zone users pass.
- Escape output with `esc()`; AJAX via `postForm()` + CSRF; controllers return
  `jsonSuccess()` / `jsonFailed()`.
- Migrations guarded (`tableExists` no-op) + tests-DB-only `down()` — copy
  `2026-07-14-120000_CreateSiteVariable.php`.
- **Employee roster** = active, non-soft-deleted users (`status = 1`), both
  zones. Admins are employees too and also check in.

---

## 2. Data model

**`attendance_days`** (soft-delete off):

| Column | Type | Notes |
|---|---|---|
| `id` | INT UAI | |
| `user_id` | BIGINT | matches `users.id` |
| `work_date` | DATE | VN calendar date |
| `checkin_at` | DATETIME null | UTC; first check-in wins |
| `checkout_at` | DATETIME null | UTC; **last** check-out wins |
| `checkin_ip` | VARCHAR(45) null | |
| `checkout_ip` | VARCHAR(45) null | |
| `missing_checkout` | TINYINT(1) default 0 | set by the nightly job (§6) |
| `note` | VARCHAR(255) null | correction reason |
| `edited_by` | BIGINT null | audit — who corrected |
| `edited_at` | DATETIME null | audit — when |
| `original_checkin_at` | DATETIME null | audit — value before the *first* correction |
| `original_checkout_at` | DATETIME null | audit — value before the *first* correction |
| `created_at` / `updated_at` | DATETIME null | |

Keys: **UNIQUE(`user_id`, `work_date`)**, Index(`work_date`),
Index(`missing_checkout`).

**No stored `work_seconds`.** Worked time is derived (`checkout_at −
checkin_at`, minus the schedule's break overlap in 01b). Storing it would drift
the moment an admin corrects a time or a schedule changes, and would need a
recompute path in three places.
`// ponytail: derived at read time; add a nightly rollup table only if monthly
reports measurably slow down (>1s at ~50 employees × 31 days).`

**One row per day** (first check-in → last check-out). Employees are not asked
to check out for a client visit and back in afterwards.
`// ponytail: one row/day. If per-trip tracking is ever required, add
attendance_sessions and keep this table as the daily rollup — the columns above
stay valid.`

**Audit rule:** `original_*` is written **only on the first correction**
(`edited_at IS NULL`). Later corrections overwrite `edited_by`/`edited_at`/`note`
but never the originals, so the employee-submitted value is always recoverable.
`// ponytail: two columns, not a history table; add attendance_edits if a
full audit log is ever required.`

### Site variables (seeded, guarded)

| Name | Default | Meaning |
|---|---|---|
| `attendance_office_ips` | `''` | comma-separated IPv4/IPv6 or CIDR, e.g. `203.0.113.10, 10.0.0.0/24` |
| `attendance_ip_enforce` | `'0'` | `'1'` = block non-office check-in; `'0'` = record the IP but allow |
| `attendance_locked_through` | `''` | `YYYY-MM`; corrections to that month and earlier are refused (payroll closed) |

Seed all three as rows (like `2026-07-15-010000_SeedSiteNameVariable.php`) so
they show up in `/admin/settings` without the admin having to guess the names.

**Explicit enforce flag, no silent fail-open.** The previous draft treated an
empty allowlist as "allow everyone" — which means forgetting to configure it
leaves zero control, silently, forever. Now: enforcement is off until switched
on deliberately, and the management page shows a persistent amber alert banner
while `attendance_ip_enforce = 0` ("IP enforcement is off — check-ins are
accepted from any network").

---

## 3. Files

- Migration `app/Database/Migrations/2026-07-22-010000_CreateAttendanceDays.php`.
- Migration `app/Database/Migrations/2026-07-22-010100_SeedAttendanceVariables.php`.
- Migration `app/Database/Migrations/2026-07-22-010200_SeedAttendancePermissions.php`
  — follow `2026-07-16-094000_SeedRolesAndPermissions.php`.
- `app/Models/AttendanceDayModel.php`.
- `app/Helpers/attendance_helper.php`.
- `app/Controllers/Attendance.php` (employee), `app/Controllers/Admin/Attendance.php`.
- Views `app/Views/attendance/index.php`, `app/Views/admin/attendance/index.php`.
- Modify: `app/Config/Routes.php`, `app/Views/layouts/app.php` (nav),
  `app/Views/client/dashboard.php` (widget),
  `app/Libraries/ScheduledTaskRunner.php` (§6 handler), `app/Config/App.php` (§0).
- Tests: `tests/database/AttendanceDayModelTest.php`,
  `tests/feature/Web/AttendanceTest.php`,
  `tests/feature/Web/AdminAttendanceTest.php`,
  `tests/unit/AttendanceHelperTest.php`.

---

## 4. Permissions

Seed two permissions and grant both to the `admin` role:

- `attendance.manage` — view every employee's attendance, correct entries.
- `requests.approve` — approve/reject leave & late requests (used by card 02;
  seeded here so both cards share one migration).

Employees need no permission to see or record **their own** attendance —
`authFilter` is enough, and every query is scoped to `current_user_id()`.

---

## 5. Helper — `app/Helpers/attendance_helper.php`

```php
function attendance_tz(): string   // 'Asia/Ho_Chi_Minh'

function attendance_today(): string
// VN calendar date, e.g. Time::now(attendance_tz())->toDateString()

function attendance_ip_enforced(): bool
// get_site_variable('attendance_ip_enforce', '0') === '1'

function office_ip_allowed(?string $ip = null): bool
// ! attendance_ip_enforced()            -> true
// allowlist empty                       -> true (nothing to match against)
// else match $ip (default: service('request')->getIPAddress()) against each rule

function attendance_ip_matches(string $rule, string $ip): bool
// plain IP  -> equality (normalise with inet_pton so 010.0.0.1 == 10.0.0.1)
// CIDR      -> mask compare on inet_pton bytes; works for IPv4 and IPv6
// malformed rule -> false, never an exception
// ponytail: byte-mask compare, no library

function attendance_period_locked(string $workDate): bool
// substr($workDate, 0, 7) <= attendance_locked_through (when that is non-empty)
```

`get_site_variable()` already exists (`app/Helpers/setting_helper.php:17`);
`helper('setting')` before using it.

---

## 6. Nightly job — flag missing check-outs

Register one handler in `ScheduledTaskRunner::registry()` (alongside
`emails.purge` / `notifications.purge`), then create the `scheduled_tasks` row:

- key `attendance.flag_missing_checkout`
- for the **previous VN day**: rows with `checkin_at IS NOT NULL AND checkout_at
  IS NULL` → `missing_checkout = 1`
- returns a short result string (`"Flagged 3 day(s) without check-out."`) for
  `last_result`

**It does not invent a check-out time.** Auto-filling 18:00 would fabricate
payroll data; flagging surfaces the row in the management filter
("Missing check-out") so a human corrects it through the audited path. The
employee's own page also shows a warning on such rows.

---

## 7. Model methods (`AttendanceDayModel`)

- `todayFor(int $userId): ?object` — row for `user_id` + `attendance_today()`.
- `dayFor(int $userId, string $workDate): ?object`.
- `historyFor(int $userId, int $limit, int $offset): array` + `countForUser(int $userId): int`.
- `checkin(int $userId, string $ip): object` — insert today's row with
  `checkin_at = now (UTC)`; relies on the unique key as the real guard (see §8).
- `checkout(int $userId, string $ip): object` — set `checkout_at = now (UTC)`,
  `checkout_ip`, `missing_checkout = 0`; returns the fresh row.
- `searchAdmin(array $filters, string $sort, string $order, int $limit, int $offset): array`
  + `countAdmin(array $filters): int` — filters `user_id`, `date_from`,
  `date_to`, `missing_checkout`; join `users` for the name; newest first.
  Follow `UserModel`'s `SORT_ALLOWLIST` pattern — never interpolate the sort
  column.
- `findDay(int $id): ?object`.
- `applyCorrection(int $id, array $data, int $editorId): bool` — the single
  place that writes `edited_by` / `edited_at` and back-fills `original_*` on the
  first edit. `// ponytail: one method so no caller can bypass the audit trail.`

---

## 8. Controller behaviour

### `Attendance` (employee, `authFilter`)

- `index()` — renders with `today` (`todayFor`), `history` (paginated),
  `ipAllowed` (`office_ip_allowed()`), `ipEnforced`, and `tz` for display.
- `checkin()` — in order:
  1. `! office_ip_allowed()` → `jsonFailed('You can only check in from the office network.', 403)`
  2. `todayFor()` already has `checkin_at` → `jsonFailed('You have already checked in today.', 409)`
  3. insert; on a duplicate-key error (double-submit / two tabs) treat it as
     case 2 rather than a 500. `// ponytail: unique key is the guard, the
     pre-check is only for the message`
  4. `jsonSuccess` with the formatted VN time.
- `checkout()` —
  1. IP check as above
  2. no `checkin_at` today → `jsonFailed('You have not checked in today.', 409)`
  3. otherwise write/overwrite `checkout_at` — **re-checking out is allowed and
     the last one wins**, so an accidental lunchtime check-out is self-healing
     instead of locking the day. Return the updated row (times + worked hours).

### `Admin\Attendance` (`authFilter` + `permission:attendance.manage`)

- `index()` — filter form + paginated company table (user, date, in, out, hours,
  IPs, missing-check-out badge, corrected-by badge). Mirrors
  `Admin\Settings::index`. Shows the amber banner when IP enforcement is off.
- `edit($id)` (JSON) — row payload for the modal.
- `update()` — validate `checkin_at` / `checkout_at` (`valid_date`), require
  `checkout_at >= checkin_at`, `note` max 255; reject with 409 when
  `attendance_period_locked($row->work_date)`; then `applyCorrection()`.
- `create()` — manual entry for a user + date (missed check-in): same validation,
  plus unique-per-day (surface the duplicate as a 409 message, not a 500), plus
  the lock check. Written through `applyCorrection()` semantics so a
  manager-created row is visibly manager-created.

Every management action records `edited_by = current_user_id()`.

---

## 9. UI (TailAdmin, mobile-first)

- **Employee page** — one prominent card:
  - no check-in yet → big "Check in" button
  - checked in → arrival time (VN) + "Check out"
  - checked out → both times + worked hours
  - `!ipAllowed` → button disabled with the reason ("You are not on the office
    network"), never a silent no-op
  - `missing_checkout` on a past row → inline amber alert "No check-out
    recorded — ask your manager to correct it"
  - below: history table (date, in, out, hours)
- **Client dashboard widget** — the same card, compact.
- **Management page** — filter form + table + correction modal (shared modal
  pattern), amber banner while enforcement is off.
- Shared toast / confirm / alert Alpine components only; `esc()` everything.

---

## 10. Nav

- Client: new group **Attendance** → `My Attendance` (`/attendance`)
  [+ `My Requests` from card 02].
- Admin: `Attendance` (`/admin/attendance`) — render the link only when
  `user_can('attendance.manage')`.

---

## 11. Routes

`/admin/attendance` needs a different filter than the existing `admin` group
(`['authFilter', 'adminFilter']`), so declare a **second group with the same
`admin` prefix** — legal in CI4, and it keeps the URL scheme consistent. Verify
with `php spark routes` after wiring.

```php
// authFilter group (employee)
$routes->get('attendance', 'Attendance::index');
$routes->post('attendance/checkin', 'Attendance::checkin', ['filter' => 'csrf']);
$routes->post('attendance/checkout', 'Attendance::checkout', ['filter' => 'csrf']);

// management group
$routes->group('admin', ['filter' => ['authFilter', 'permission:attendance.manage']], static function ($routes) {
    $routes->get('attendance', 'Admin\Attendance::index');
    $routes->get('attendance/edit/(:num)', 'Admin\Attendance::edit/$1');
    $routes->post('attendance/update', 'Admin\Attendance::update', ['filter' => 'csrf']);
    $routes->post('attendance/create', 'Admin\Attendance::create', ['filter' => 'csrf']);
});
```

---

## 12. Tests

**Unit (`AttendanceHelperTest`)**
- enforcement off → allowed regardless of IP; enforcement on + empty list → allowed
- exact IPv4 match; IPv4 CIDR inside/outside; IPv6 exact; malformed rule → false
- `attendance_period_locked`: same month locked, later month open, empty setting → never locked
- `attendance_today()` returns the VN date when the UTC date differs (freeze time at 17:30 UTC)

**Feature — employee (`AttendanceTest`)**
- check-in from an office IP succeeds; from a non-office IP with enforcement on → 403
- **forged `X-Forwarded-For` from an untrusted client does not bypass the allowlist** (§0)
- second check-in the same day → 409
- check-out without check-in → 409; check-out sets the time; re-check-out overwrites it
- `work_date` uses VN, not UTC (freeze time near midnight)
- unauthenticated → redirected to login

**Feature — management (`AdminAttendanceTest`)**
- a user without `attendance.manage` is redirected; an admin-zone user passes
- correction writes `edited_by`/`edited_at` and back-fills `original_*` once, not twice
- `checkout_at < checkin_at` rejected
- correction inside a locked month → 409
- manual create for a duplicate user+date → 409, not a 500
- the "missing check-out" filter returns only flagged rows
- render smoke: both pages return 200 for an authorised user (see the
  `HubRenderSmokeTest` note in CLAUDE.md)

**Database (`AttendanceDayModelTest`)**
- `todayFor` scoping; `searchAdmin` date-range + user filters; unique-per-day
  constraint; `applyCorrection` audit semantics; sort allowlist rejects an
  arbitrary column

**Nightly job**
- a row with a check-in and no check-out on the previous VN day gets flagged;
  today's open row does **not**; a completed row is untouched

---

## 13. Definition of done

- [ ] `proxyIPs` set (or enforcement deliberately left off, and documented)
- [ ] Spoofed-`X-Forwarded-For` test passes
- [ ] All DATETIME stored UTC; VN used for `work_date` + display
- [ ] Corrections impossible without an audit record
- [ ] Locked months reject corrections
- [ ] `permission:attendance.manage` gates the management area
- [ ] Nightly flag job registered and covered by a test
- [ ] `npm run build` run after view edits (Tailwind JIT — see CLAUDE.md)
- [ ] `vendor/bin/php-cs-fixer fix`, `phpstan`, `phpunit` clean

---

## Implementation log — 2026-07-25

**Status: code complete, tested. Two items open (see below).**

Shipped:
- `2026-07-22-010000_CreateAttendanceDays` · `-010100_SeedAttendanceVariables`
  (3 settings) · `-010200_SeedAttendancePermissions` (`attendance.manage`,
  `requests.approve`) · `-010300_SeedAttendanceScheduledTask`
- `app/Helpers/attendance_helper.php` — tz split, IP/CIDR match, payroll lock,
  worked-seconds + duration formatting
- `is_impersonating()` added to `app/Helpers/auth_helper.php` (shared — card 02
  needs the same guard)
- `app/Models/AttendanceDayModel.php` — incl. `applyCorrection()` (the only
  audited write path), `forUsersInRange()`/`openRowsOn()` bulk loaders for 01b/01c
- `app/Controllers/Attendance.php`, `app/Controllers/Admin/Attendance.php`
- `app/Views/attendance/index.php`, `app/Views/admin/attendance/index.php`
- Routes (second `admin` group on `permission:attendance.manage`), nav group
  "Attendance", `attendance.flag_missing_checkout` handler in `ScheduledTaskRunner`

Tests: 43 new, all green — `AttendanceIpMatchTest` (10), `AttendanceHelperTest`
(6), `AttendanceDayModelTest` (11), `AttendanceFlagMissingCheckoutTest` (5),
`AttendanceTest` (11), `AdminAttendanceTest` (16). Full suite: 616 tests, only
the 4 documented `BasicAuthGateTest` failures (pre-existing, `.env`-related).
`php-cs-fixer` + `phpstan` clean; `npm run build` run.

Decisions taken during implementation (beyond the plan):
- **Check-in is refused while impersonating.** `current_user_id()` follows
  `userWorking`, so an admin acting-as an employee would otherwise file an
  arrival under that employee's name — falsified payroll input. Viewing stays
  allowed.
- **Manual admin entry goes through `AttendanceDayModel::createManual()`** rather
  than `insert()` in the controller, so the duplicate-key exception is raised
  from the model layer (and phpstan can see it).
- Hourly (not daily) schedule for the flag job: cheap, idempotent, and the flag
  shows up the same morning regardless of the cron host's rollover.

Open:
1. ~~Trusted proxy~~ **RESOLVED 2026-07-25** — nginx directly on EC2, no CDN, so
   `proxyIPs` stays empty and `REMOTE_ADDR` is already the real client address
   (§0). Added: the management page now prints the address the server sees, so the
   allowlist is filled from an observed value; `app/Config/App.php` carries the
   "when to change this" note. Enforcement is still shipped OFF — flip
   `attendance_ip_enforce` to `1` once `attendance_office_ips` holds the office's
   public IP.
2. Review pass (`reviewer` + `ponytail-review`) not yet run — card stays in
   `processing/` until then.

---

## Review pass — 2026-07-25 (covers cards 01 / 01b / 01c / 01d / 02)

**Security & correctness sweep**

| Check | Result |
|---|---|
| Raw SQL / string-built queries | none — Query Builder throughout |
| `orderBy(…, false)` (escaping off) | one, `"leave_requests.status = 'pending'"` — a literal with zero request input |
| Sort columns | allowlisted (`SORT_ALLOWLIST`) in both new models before reaching SQL |
| `user_id` from request input | only in the three permission-gated management controllers, and only as a *filter* — never as the owner of a write |
| Employee-side scoping | `Attendance` / `Requests` derive the owner from `current_user_id()` (12 sites); neither route accepts a user id |
| Output escaping | every dynamic value `esc()`d; the remaining bare `<?=` are developer-authored literal ternaries (`'checked'`, `'selected'`, class strings) |
| CSRF | every POST route in the feature carries the `csrf` filter (verified via `php spark routes`) |
| Impersonation | check-in/out and request submission refuse while acting as another user |
| Payroll lock | enforced on corrections, manual entries **and** approvals |

**`ponytail-review` — applied, net −95 lines**

- deleted `LeaveBalance::remaining()` (never called; `summary()` computes it inline)
- deleted `LeaveRequestModel::approvedForRange()` + `approvedForDate()` — a wrapper
  chain for a contract nobody used; `approvedRequestMap()` calls the bulk loader and
  resolves leave-beats-late itself. The one test that used it now asserts the
  rendered outcome instead, which is the behaviour that matters.
- `attendance_local_time()` helper replaces an identical 4-line closure in 5 views
- `leave_request_label()` / `leave_request_dates()` helpers + `requests/_status_pill.php`
  replace ~38 lines duplicated verbatim across the two request views

**Accessibility / layout spot-check (all 10 views)**

- 10/10 wide tables wrapped in `overflow-x-auto`; `th scope="col"` throughout
- every modal carries `role="dialog"` + `aria-modal` + `aria-labelledby` + `x-trap.noscroll`
- every alert banner carries `role="alert"` (one was missing inside the correction
  modal — added)
- action buttons `whitespace-nowrap` so no clickable label wraps to two lines

**Final state:** 740 tests, 2 215 assertions. Only the 4 pre-existing
`BasicAuthGateTest` failures (empty `app.basic.auth.*` in `.env`, documented in
CLAUDE.md, unrelated). `phpstan` clean, `php-cs-fixer` clean, `npm run build` run,
all migrations applied to `db_office_xcn_vn`.

**Handover / still needed from ops:**
1. OS cron entry — without it the 5 scheduled jobs never fire:
   `* * * * * cd /path/to/app && php spark schedule:run >> writable/logs/schedule.log 2>&1`
2. Office-IP enforcement is shipped **off**. Read the address printed on
   `/admin/attendance`, put it in `attendance_office_ips`, set
   `attendance_ip_enforce = 1`.
3. Enter this year's public holidays at `/admin/attendance/holidays` — until then a
   holiday counts as absence.
