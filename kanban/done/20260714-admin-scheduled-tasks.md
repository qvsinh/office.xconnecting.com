# Plan: Admin Scheduled Tasks (cron) management

**Task:** 20260714
**Created:** 2026-07-14
**Branch suggestion:** `feature/admin-scheduled-tasks`

## 1. Goal

Give admins a management surface for the platform's recurring maintenance jobs (cron):
list/create/edit/delete schedules, enable/disable them, run one on demand, and see each
job's last run status/result — backed by a single `php spark schedule:run` command that an
OS cron invokes to execute due jobs.

## 2. Scope

- **In scope:**
  - `scheduled_tasks` table (rebuilt schema — recurring schedules, not a job queue) + guarded migration.
  - `ScheduledTaskModel` (searchAdmin/countAdmin/find + run-bookkeeping methods).
  - `Admin\ScheduledTasks` controller: index / create / edit(JSON) / update / delete + **toggle** (enable/disable) + **runNow** (manual run), all `authFilter`+`adminFilter`, POSTs behind `csrf`, JSON envelopes via `jsonSuccess`/`jsonFailed` (`csrf_hash` included).
  - `ScheduledTaskRunner` library — a **fixed handler registry (allowlist)** + one shared `runTask()` dispatch path used by both the CLI command and the web `runNow` action.
  - `App\Commands\RunScheduledTasks` (`php spark schedule:run`) invoked by OS cron: select due tasks, dispatch, record `last_run_at`/`next_run_at`/`last_status`/`last_result`/`last_duration_ms`.
  - Port only the in-scope maintenance handlers: purge old `emails`, `notifications`, `api_history`.
  - View `app/Views/admin/scheduled_tasks/index.php` + sidebar entry in `$navGroups['Admin']`.
  - Model + feature + command tests.

- **Out of scope (DEFERRED — see §4/§7):**
  - `Cleanup::cleanup_sessions` — rebuild uses the **file** session handler (`Config\Session::$driver = FileHandler`), so there is no `ci_sessions` table to purge. CI4 GC's file sessions itself.
  - `Cleanup::clean_up_users_login_log` — no `users_login_log` table exists in the rebuild.
  - `psi_domains_waiting_for_sync` (legacy `MinutelyTask`) — PSI billing sync; needs upstream creds + a `psi_*` table not yet in the rebuild.
  - `DnsZone::transfer_zones_to_domain_owners` (referenced by legacy `TaskScheduled::createTransferZonesTask`, lib file absent) — DNS/CMS/CRM domain, not being rebuilt.
  - Per-user completion notifications on task finish (legacy inserted a `notifications` row) — the in-scope jobs are system-level with no owning user; admin reads outcome from `last_status`/`last_result`. YAGNI.
  - Cron-expression scheduling (`* * * * *`). We use a plain `interval_minutes`; add an expression parser only if a job needs calendar alignment.
  - Arbitrary `lib_name`/`method` reflection from the DB row (legacy behaviour) — replaced by a fixed allowlist for security (§4).

## 3. Affected areas

| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Models/ScheduledTaskModel.php` (new) | list/search + bookkeeping |
| Backend | `app/Controllers/Admin/ScheduledTasks.php` (new) | mirrors `Admin\Translations` |
| Backend | `app/Libraries/ScheduledTaskRunner.php` (new) | handler registry + shared `runTask()` |
| Backend | `app/Commands/RunScheduledTasks.php` (new) | `php spark schedule:run` |
| Frontend (Views) | `app/Views/admin/scheduled_tasks/index.php` (new) | mirrors `admin/settings/index.php` |
| Frontend (Views) | `app/Views/layouts/app.php` (`$navGroups['Admin']`) | add "Scheduled Tasks" entry |
| Database | `app/Database/Migrations/2026-07-14-150000_CreateScheduledTasks.php` (new) | guarded idempotent, tests-only `down()` |
| Config / Routes | `app/Config/Routes.php` (admin group) | 7 routes |
| Tests | `tests/database/ScheduledTaskModelTest.php`, `tests/feature/Web/AdminScheduledTasksTest.php`, `tests/database/RunScheduledTasksCommandTest.php` (new) | |

## 4. Impact analysis

All new symbols — no existing symbol is renamed or refactored, so blast radius is additive.
CodeGraph impact was not run because there are no existing callers (nothing references
`ScheduledTaskModel`, `Admin\ScheduledTasks`, `ScheduledTaskRunner`, or `schedule:run` yet).

Touched existing files, additive only:
- `app/Config/Routes.php` — append routes inside the existing `admin` group (`authFilter`+`adminFilter`). No change to existing routes.
- `app/Views/layouts/app.php` — append one item to `$navGroups['Admin']`. No change to existing items. (Frontend-owned edit; backend must not touch it.)

**Security-sensitive design decisions (this module can execute actions on a schedule):**

1. **No DB-driven code reflection.** Legacy dispatched `new ("App\Libraries\Tasks\".$row['lib_name'])->$row['method']()` straight from the DB row — and that row is editable from the admin UI, i.e. stored-value → arbitrary class/method instantiation. The rebuild stores a `handler` **key** and dispatches only keys present in `ScheduledTaskRunner`'s fixed registry. Unknown key ⇒ `last_status='error'`, recorded, never fatal. **CRITICAL gate:** `create()`/`update()` must reject any `handler` not in the allowlist.
2. **`params` is JSON, not code.** Validated as JSON on write; the runner reads only the specific keys each handler declares (e.g. `days`). Never `eval`, never spread into a call.
3. **`runNow` side-effect gating.** POST + `csrf` + `authFilter` + `adminFilter`; audit-log actor + task id + outcome (`log_message('info', ...)`). Executes inline (the in-scope handlers are fast bounded `DELETE`s) via the **same** `ScheduledTaskRunner::runTask()` the CLI uses — one dispatch path, no duplication.
4. **`toggle` gating.** POST + `csrf` + `adminFilter`.
5. **Never swallow handler failure.** `runTask()` wraps each handler in `try/catch (\Throwable)`, records `last_status='error'` + truncated `last_result`, and returns so the batch continues. One failing job must not abort cron or crash the request. Do not simplify this away.
6. **`schedule:run` is CLI-only** (`BaseCommand`) — not web-routable.
7. **Bound `last_result`.** Truncate stored output (e.g. 5000 chars) so a chatty handler can't grow `LONGTEXT` unboundedly.

## 5. Implementation steps

### Phase 1 — Backend

1. [ ] Migration `2026-07-14-150000_CreateScheduledTasks.php` — owner: `backend-coder`
   - Guarded idempotent `up()`: `$db = Database::connect($this->DBGroup); $db->resetDataCache(); if ($db->tableExists('scheduled_tasks')) return;` then create. Tests-only `down()` (drop only when `$this->db->getDatabase()` equals the configured `tests` DB), exactly like `CreateTranslation`.
   - Columns (**rebuilt schema — divergence from legacy `tasks_scheduled`, see §7**):
     | Column | Type | Notes |
     |--------|------|-------|
     | `id` | BIGINT unsigned PK auto_increment | |
     | `name` | VARCHAR(255) | |
     | `handler` | VARCHAR(100) | registry key (allowlisted), not class/method |
     | `params` | TEXT null | JSON params for the handler |
     | `interval_minutes` | INT unsigned, default 60 | recurrence period |
     | `enabled` | TINYINT(1), default 1 | |
     | `last_run_at` | DATETIME null | |
     | `next_run_at` | DATETIME null | due when NULL or `<= NOW()` |
     | `last_status` | VARCHAR(20) null | `success` / `error` / `running` |
     | `last_result` | LONGTEXT null | truncated output or error message |
     | `last_duration_ms` | INT unsigned null | |
     | `description` | TEXT null | |
     | `created_at` | DATETIME null | `useTimestamps` |
     | `updated_at` | DATETIME null | `useTimestamps` |
   - Index: `addKey(['enabled', 'next_run_at'])` — covers the `dueTasks()` selection.
   - Acceptance: `php spark migrate` creates the table on a fresh DB and is a no-op when it already exists; `phpunit` with `$refresh=true` builds and drops it against the tests DB.

2. [ ] `ScheduledTaskModel` — owner: `backend-coder`
   - `$table='scheduled_tasks'`, `$returnType='object'`, `$useTimestamps=true`, `$allowedFields=['name','handler','params','interval_minutes','enabled','last_run_at','next_run_at','last_status','last_result','last_duration_ms','description']`.
   - `const SORT_ALLOWLIST = ['id','name','handler','next_run_at','last_run_at','enabled'];` keyword fields `['name','handler','description']`.
   - `searchAdmin(array $filters, string $sort, string $order, int $limit, int $offset): array` + `countAdmin(array $filters): int` + private `applyAdminFilters()` — mirror `TranslationModel` (keyword LIKE group, exact `handler` filter, exact `enabled` filter, deterministic secondary sort on `id`).
   - `findTask(int $id): ?object` — typed `find()` wrapper.
   - Bookkeeping (data layer only — no dispatch logic here):
     - `dueTasks(): array` → `where('enabled',1)->groupStart()->where('next_run_at',null)->orWhere('next_run_at <=', date('Y-m-d H:i:s'))->groupEnd()->orderBy('next_run_at','ASC')->findAll()`.
     - `markRunning(int $id): void` → set `last_status='running'`, `last_run_at=now`.
     - `recordResult(int $id, string $status, string $result, int $durationMs, string $nextRunAt): void` → set `last_status`, truncated `last_result`, `last_duration_ms`, `next_run_at`.
     - `setEnabled(int $id, bool $enabled): void`.
   - Acceptance: model unit tests (Phase 3.1) green; `phpstan` clean.

3. [ ] `ScheduledTaskRunner` library — owner: `backend-coder`
   - `registry(): array` returns the fixed allowlist `key => ['label' => ..., 'run' => callable(array $params): string]`. Keep handlers tiny (bounded `DELETE`s), returning a one-line summary string:
     - `emails.purge` — delete `emails` where `created_at < now-{days}` (param `days`, default 7). ponytail: single query builder delete.
     - `notifications.purge` — delete `notifications` where `created_at < now-{days}` (param `days`, default 30).
     - `api_history.purge` — delete `api_history` where `created_at < now-{days}` (param `days`, default 7).
   - `handlerKeys(): array` — for controller validation + the view's dropdown.
   - `runTask(object $task): array` — **single shared dispatch path** (CLI + web):
     1. `model->markRunning($task->id)`; start timer.
     2. Look up `$task->handler` in registry; unknown ⇒ `status='error'`, message "Unknown handler".
     3. Decode `$task->params` JSON (invalid ⇒ empty array); call the handler in `try/catch (\Throwable)`.
     4. Compute `nextRunAt = now + interval_minutes`.
     5. `model->recordResult(...)` with truncated result; return `['status'=>..., 'result'=>...]`.
     - Never rethrow; never leave a task stuck in `running`.
   - Acceptance: covered by command test (Phase 3.3), including the unknown-handler error path.

4. [ ] `App\Commands\RunScheduledTasks` (`schedule:run`) — owner: `backend-coder`
   - `$group='App'`, `$name='schedule:run'`, `$usage='schedule:run [--task=<id>] [--force]'`.
   - Default: iterate `ScheduledTaskModel::dueTasks()`, call `ScheduledTaskRunner::runTask()` on each, `CLI::write` a per-task summary line.
   - `--task=<id>`: run one task by id; with `--force` ignore its `next_run_at`.
   - Acceptance: `php spark schedule:run` runs due tasks and updates bookkeeping; a failing handler is logged and does not abort the batch.

5. [ ] Routes — owner: `backend-coder`
   - Append inside the `admin` group in `app/Config/Routes.php` (after the translations block):
     ```php
     $routes->get('scheduled-tasks', 'Admin\ScheduledTasks::index');
     $routes->post('scheduled-tasks/create', 'Admin\ScheduledTasks::create', ['filter' => 'csrf']);
     $routes->get('scheduled-tasks/edit/(:num)', 'Admin\ScheduledTasks::edit/$1');
     $routes->post('scheduled-tasks/update', 'Admin\ScheduledTasks::update', ['filter' => 'csrf']);
     $routes->post('scheduled-tasks/delete', 'Admin\ScheduledTasks::delete', ['filter' => 'csrf']);
     $routes->post('scheduled-tasks/toggle', 'Admin\ScheduledTasks::toggle', ['filter' => 'csrf']);
     $routes->post('scheduled-tasks/run', 'Admin\ScheduledTasks::runNow', ['filter' => 'csrf']);
     ```
   - Acceptance: `php spark routes` lists all seven under `authFilter`+`adminFilter`.

6. [ ] `Admin\ScheduledTasks` controller — owner: `backend-coder` (mirror `Admin\Translations`)
   - `index()` — paginate via `searchAdmin/countAdmin`; filters `keyword`, `handler`, `enabled`; clamp limit (`resolveLimit()` like Translations). Pass `handlerKeys` to the view for the create/edit dropdown + filter.
   - `create()` — validate: `name` required max 255; `handler` `required|in_list[<registry keys>]`; `interval_minutes` `required|is_natural_no_zero`; `params` permit_empty valid-JSON (custom check); `description` permit_empty. Insert with initial `next_run_at = now + interval` and `enabled` from input (default 1).
   - `edit(int $id)` — JSON record for the modal.
   - `update()` — same rules + `id required|is_natural_no_zero`; re-validate `handler` against allowlist; recompute `next_run_at` if interval changed.
   - `delete()` — POST, `id` validated.
   - `toggle()` — POST, `id` validated; `model->setEnabled()`; return new state.
   - `runNow()` — POST, `id` validated; load task; **audit-log** actor+id; delegate to `ScheduledTaskRunner::runTask()`; return `last_status`+`last_result` in the JSON `extra`.
   - All responses via `jsonSuccess`/`jsonFailed`.
   - Acceptance: feature tests (Phase 3.2) green.

### Phase 2 — Frontend (Views)

1. [ ] `app/Views/admin/scheduled_tasks/index.php` — owner: `frontend-coder`
   - Mirror `app/Views/admin/settings/index.php`: extend the shared layout, TailAdmin table, keyword + `handler` + `enabled` filters, pagination.
   - Columns: Name, Handler, Interval (min), Enabled (badge), Next run, Last run, Last status (success/error/running badge), Actions.
   - Row actions (Alpine + `fetch()` with CSRF token, shared toast/confirm components — never native `alert`/`confirm`):
     - **Edit** — fetch `edit/{id}` JSON → populate modal → POST `update`.
     - **Create** — modal with `handler` dropdown (from `handlerKeys`), `interval_minutes`, `params` (JSON textarea), `enabled`, `description` → POST `create`.
     - **Toggle** — POST `toggle`; flip the badge on success.
     - **Run now** — confirm modal ("This runs the job immediately") → POST `run`; show returned `last_status`/`last_result` in the toast.
     - **Delete** — confirm modal → POST `delete`.
   - `esc()` every dynamic value (name, handler, description, last_result — especially `last_result`, which may contain a DB/exception string).
   - Acceptance: `AdminScheduledTasksViewTest` (if the team adds a view test like `AdminServersViewTest`) renders without error; page lists seeded tasks; all actions round-trip.
   - UI notes: TailAdmin table + modal patterns, Alpine `x-data` per row/modal, `fetch()` with `csrf-token` meta header, status badges styled by `last_status`.

2. [ ] `$navGroups['Admin']` entry — owner: `frontend-coder`
   - Add `['label' => 'Scheduled Tasks', 'href' => '/admin/scheduled-tasks']` in `app/Views/layouts/app.php`.
   - Acceptance: sidebar shows the link in the admin zone.

### Phase 3 — Tests

1. [ ] `tests/database/ScheduledTaskModelTest.php` — owner: `tester`
   - `searchAdmin`/`countAdmin` keyword + `handler` + `enabled` filters and deterministic ordering.
   - `dueTasks()` selects `enabled=1` with `next_run_at` NULL or `<= now`, excludes disabled/future.
   - `recordResult()`/`setEnabled()`/`markRunning()` write the expected fields; `last_result` truncation.

2. [ ] `tests/feature/Web/AdminScheduledTasksTest.php` — owner: `tester`
   - Mirror `AdminTranslationsTest` (real routing + filters + CSRF, `WebAuthFixture`).
   - CRUD success + validation failures; **handler allowlist rejection** (400 on unknown handler); `toggle` flips `enabled`; `runNow` returns success envelope and updates `last_status`; admin-gating (non-admin → redirected/403) on `index` and every POST.

3. [ ] `tests/database/RunScheduledTasksCommandTest.php` — owner: `tester`
   - Seed rows + target-table rows (`emails`/`notifications`/`api_history`); run `schedule:run`; assert due rows dispatched, old target rows purged, `last_run_at`/`next_run_at`/`last_status='success'` set, future/disabled rows skipped.
   - **Error path:** insert a row with `handler='does.not.exist'` directly via the model (bypassing controller validation), run the command, assert `last_status='error'` + `last_result` recorded and the batch still completes other tasks (no throw). No external/registrar calls anywhere.

## 6. Test plan

- **Unit:** `ScheduledTaskModel` search/count/filters, `dueTasks` selection, bookkeeping writes, `last_result` truncation.
- **Integration:** admin CRUD + toggle + runNow endpoints (JSON envelope, CSRF, `handler` allowlist, admin gating); `schedule:run` due-selection + purge + bookkeeping + error path.
- **Browser / E2E:** admin opens Scheduled Tasks → creates an `emails.purge` job (interval 1440) → toggles it off/on → "Run now" and sees the result toast → edits interval → deletes it.
- **Manual smoke:**
  1. `php spark migrate` (table created).
  2. Create a job in the UI, click "Run now", confirm `last_status`/`last_result` populate.
  3. `php spark schedule:run` on the CLI; confirm due jobs run and `next_run_at` advances.
  4. Install the OS cron line from §8 and confirm it fires.

## 7. Risks & open questions

- **Design divergence from legacy (deliberate).** Legacy `tasks_scheduled` is a *job queue* (one row per execution, `status` 0/1/2/3, no recurrence metadata) driven by four `Cli\{Minutely,Hourly,Daily,Monthly}Task::add_tasks()` cron controllers that INSERT rows, plus a long-running `Cli\Tasks::run_tasks` 1-second poll loop. The rebuild replaces all of that with **recurring schedule rows** (`interval_minutes` + `next_run_at`) executed by a single per-minute `schedule:run` command — no daemon, no queue-filler controllers. Rationale: fewer moving parts, no long-lived PHP process, and the admin UI manages *schedules* (what "cron management" means) rather than a growing execution log. Confirm this is acceptable before coding.
- **Handler execution safety.** Replacing DB-driven `lib_name`/`method` reflection with a fixed registry is a security improvement but means new maintenance jobs require a code change (a new registry entry), not just a DB row. Intended trade-off — flag if the team wants runtime-registrable handlers (they should not, given the UI writes these rows).
- **`runNow` runs inline.** Acceptable because in-scope handlers are fast bounded `DELETE`s. If a future handler is slow or hits an external API, `runNow` must switch to "mark due, let cron pick it up" to avoid web-request timeouts. Marked for revisit when the first heavy handler lands.
- **Deferred handlers (do not port now):** `cleanup_sessions` (file-session driver, no `ci_sessions`), `clean_up_users_login_log` (no such table), `psi_domains_waiting_for_sync` (PSI billing, needs upstream creds), `transfer_zones_to_domain_owners` (DNS/CMS, out of rebuild scope). Add when their tables/domains are rebuilt.
- **Open:** should the seeder pre-create the three purge jobs (disabled) so a fresh install has sensible defaults, or leave the table empty for admins to add? Recommend a small `ScheduledTasksSeeder` creating the three jobs **disabled** — low risk, discoverable. Confirm.

## 8. Rollout

- Migration order: `2026-07-14-150000_CreateScheduledTasks` runs after existing migrations; guarded, so safe to re-run on dev/prod.
- No new env vars. Handlers read only from `params` JSON.
- **OS cron (deploy note):** add one line, every minute:
  ```
  * * * * * cd /path/to/proxyv2.services.vn && php spark schedule:run >> writable/logs/schedule.log 2>&1
  ```
  This replaces the legacy per-frequency cron entries (`run tasks`, `MinutelyTask/add_tasks`, etc.).
- No feature flag needed; the module is admin-only and inert until a job is created and enabled.
