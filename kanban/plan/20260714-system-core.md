# Plan: System — core (system info + migrations status + system logs)

**Task:** 20260714
**Created:** 2026-07-14
**Branch suggestion:** `feature/system-core`

## 1. Goal
Deliver the first "System" admin card: a read-only System landing (environment/info +
record counts), a read-only Database Migrations status view (applied vs pending), and a
System Logs viewer (list / preview / download / delete) over `writable/logs/`. Admin-only,
no secrets ever rendered, path-traversal-safe.

## 2. Scope
- **In scope:**
  - **System info** page (`/admin/system`): CI4 version, PHP version, `ENVIRONMENT`, DB driver
    + database *name* (not credentials), record counts (users, API access keys, API history rows
    — total/live/testing, notifications), disk free/total, writable-dir status. Read-only.
  - **Database migrations** viewer (`/admin/system/migrations`): list all defined migrations and
    mark each applied (with batch + applied time) or pending. Read-only by default. Search +
    pagination. No raw SQL — CI4 `MigrationRunner` + Query Builder on the framework `migrations` table.
  - **System logs** viewer (`/admin/system/logs`): list `*.log` files in `writable/logs/` (name,
    size, mtime), `preview` (escaped tail of file), `download` (safe), `delete` / `delete-all`
    (CSRF + confirm modal). Allowlist-validated filenames.
- **Out of scope (later System cards — enumerated only):** Servers, Scheduled Tasks, Translations,
  Emails + Email templates, Settings / site variables, PSI Billing API console. Also: any web-triggered
  destructive migration run/rollback is **deferred to CLI** by default (see §7 decision — optional
  guarded actions described but off by default). User-side features and API docs are their own cards.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Admin/System.php` (new) | `index()` = info, `migrations()` = read-only status |
| Backend | `app/Controllers/Admin/SystemLogs.php` (new) | `index/preview/download/delete/deleteAll` |
| Backend | `app/Helpers/system_helper.php` (new, tiny) | `tail_lines()` bounded log tail + `log_dir_files()` allowlist |
| Frontend (Views) | `app/Views/admin/system/info.php` (new) | extends `layouts/app` |
| Frontend (Views) | `app/Views/admin/system/migrations.php` (new) | read-only table + search/pagination |
| Frontend (Views) | `app/Views/admin/system/logs/index.php` (new) | list + preview modal + delete actions |
| Frontend (Views) | `app/Views/layouts/app.php` | add "System" admin sidebar link(s) (frontend-coder) |
| Database | none | reads framework `migrations` table only; no new migration |
| Config / Routes | `app/Config/Routes.php` | new routes in existing `admin` group |
| Tests | `tests/…` | feature tests: logs path traversal, no-secret-leak, admin gating |

## 4. Impact analysis
All new symbols — **no existing symbol is modified or renamed**, so blast radius is additive:

- **Routes.php** — new lines appended inside the existing `admin` group
  (`['filter' => ['authFilter','adminFilter']]`). No change to existing routes; no callers broken.
- **`layouts/app.php`** — sidebar nav gets one new `<a>` block under the `$navZone === 'admin'` branch
  (lines ~70–90). Purely additive markup; other views that `extend('layouts/app')` are unaffected.
- **New controllers** extend `App\Controllers\BaseController` (same base as `Admin\ApiHistory`) and
  reuse `jsonSuccess`/`jsonFailed`. No shared symbol touched.
- Legacy `Admin\Dashboard`, `Admin\DatabaseMigrations`, `Admin\SystemLogs` are **reference only** —
  not imported, not indexed in this repo. `codegraph_impact` N/A (symbols don't exist here yet).

Blast radius: **small / low risk.** The only shared file edited is `layouts/app.php` (nav only,
frontend phase).

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Add routes to `app/Config/Routes.php` inside the `admin` group — owner: `backend-coder`
   - `GET  system` → `Admin\System::index`
   - `GET  system/migrations` → `Admin\System::migrations`
   - `GET  system/logs` → `Admin\SystemLogs::index`
   - `GET  system/logs/preview` → `Admin\SystemLogs::preview`
   - `GET  system/logs/download` → `Admin\SystemLogs::download`
   - `POST system/logs/delete` → `Admin\SystemLogs::delete` (`['filter' => 'csrf']`)
   - `POST system/logs/delete-all` → `Admin\SystemLogs::deleteAll` (`['filter' => 'csrf']`)
   - Acceptance: `php spark routes` lists all seven under the admin group with the right filters.

2. [ ] Create `app/Helpers/system_helper.php` — owner: `backend-coder`
   - `log_dir_files(): array` — `scandir(WRITEPATH.'logs/')`, keep only `.log` files (basename,
     extension check), return `[name, size, mtime]` sorted by mtime desc. **This list is the
     allowlist** used to validate any requested filename.
   - `tail_lines(string $path, int $max = 2000): string` — memory-bounded tail using `SplFileObject`
     seek-to-end (NOT `file()` — a `.log` can be multi-MB). `// ponytail: naive line tail, fine up to
     a few hundred MB; switch to chunked reverse-read if logs ever exceed that.`
   - Acceptance: given a fixture dir, `log_dir_files()` excludes `index.html`/`..`; `tail_lines()`
     returns at most `$max` lines and never loads the whole file when larger.

3. [ ] Create `app/Controllers/Admin/System.php` — owner: `backend-coder`
   - `index()` (system info): build a plain array of display values only —
     `CodeIgniter\CodeIgniter::CI_VERSION`, `PHP_VERSION`, `ENVIRONMENT`, DB driver
     (`db_connect()->DBDriver`) and database **name** (`->database`), counts via existing models
     (`UserModel`, `ApiAccessKeyModel`, `ApiHistoryModel` total/live/testing via `env` column,
     `NotificationModel`), `disk_free_space`/`disk_total_space` on `ROOTPATH`, and writable status
     via `is_writable()` on `WRITEPATH` + `logs/`, `cache/`, `session/`.
     **Hard rule: never read or render `.env`, DB password/user, `encryption.key`, hashids salt, or
     any registrar/billing credential.** Only the whitelisted keys above.
   - `migrations()` (read-only status): use `service('migrations')->findMigrations()` for the full
     defined set; read applied rows from the `migrations` table via Query Builder
     (`db_connect()->table('migrations')` — not raw SQL), keyed by `version`/`class`. Merge into a
     status list `[class, version/filename, batch|null, applied_at|null, status: applied|pending]`.
     Support `keyword` (LIKE on class via Query Builder `like()`), sort, and pagination
     (`countAllResults`-based total — do **not** port the legacy `SQL_CALC_FOUND_ROWS` raw query).
   - Acceptance: info page renders with real counts and zero secret values; migrations page shows
     each defined migration as applied or pending with batch/time for applied ones; both are `GET`
     and perform no writes.

4. [ ] Create `app/Controllers/Admin/SystemLogs.php` — owner: `backend-coder`
   - `index()`: paginate `log_dir_files()` (clamp limit like `Admin\ApiHistory::resolveLimit()`).
   - `preview()`: read `?file=`, resolve via **`basename()` + membership check against
     `log_dir_files()`** (reject anything not in the allowlist → `jsonFailed('File not found.',404)`);
     return `tail_lines()` output in a JSON envelope for an Alpine modal (view escapes it).
   - `download()`: same allowlist validation, then `$this->response->download(WRITEPATH.'logs/'.$name,
     null)->setFileName($name)`. 404 if not allowlisted.
   - `delete()` (POST/CSRF): allowlist-validate `file`, `unlink`, return `jsonSuccess`/`jsonFailed`.
   - `deleteAll()` (POST/CSRF): iterate `log_dir_files()` only, unlink each, return count summary.
   - **No filename from the request ever reaches a filesystem call before allowlist validation.**
   - Acceptance: `preview`/`download`/`delete` with `file=../../.env` or `file=Config/App.php`
     return 404 and touch nothing; valid filenames work.

### Phase 2 — Frontend (Views)
1. [ ] `app/Views/admin/system/info.php` — owner: `frontend-coder`
   - TailAdmin card grid (reuse dashboard card pattern): version/env cards, counts cards, disk
     usage bar, writable-status list. `esc()` every value. Read-only, no forms.
   - UI notes: Tailwind + TailAdmin cards; no secrets present in the passed data anyway.
2. [ ] `app/Views/admin/system/migrations.php` — owner: `frontend-coder`
   - Read-only table (class, batch, applied time, applied/pending badge) with a search box and the
     shared pagination component. `esc()` all output. No run/rollback buttons in this card.
3. [ ] `app/Views/admin/system/logs/index.php` — owner: `frontend-coder`
   - Table of files (name, human size, mtime) with row actions: Preview (Alpine modal, `fetch()`
     the preview JSON, render inside `<pre class="whitespace-pre-wrap">` with escaped content),
     Download (link), Delete (shared confirm modal → `postForm()`), and a Delete-all button.
   - UI notes: reuse shared toast/confirm/alert + `postForm()`; AJAX via `fetch()` with CSRF token;
     `esc()` the previewed log content (untrusted).
4. [ ] Add "System" admin sidebar entry in `app/Views/layouts/app.php` — owner: `frontend-coder`
   - One link to `/admin/system` (or a small group: System / Migrations / Logs) under the
     `$navZone === 'admin'` branch, matching existing nav item styling.
   - Owner note: frontend-coder loads the most specific UI/UX skill (`minimalist-ui` fits TailAdmin)
     before this visual work.

### Phase 3 — Tests
1. [ ] Feature tests — owner: `tester`
   - Log **path traversal**: `preview`/`download`/`delete` with `../`, absolute paths, and
     non-`.log` names all return 404 / failure and delete/read nothing.
   - **No-secret-leak**: assert the system info response body contains none of the DB password,
     encryption key, hashids salt, or raw `.env` contents (seed a known secret value in test env and
     assert its absence).
   - **Admin gating**: all seven routes require `authFilter`+`adminFilter` (non-admin / guest → redirect/403).
   - Migrations status: applied migrations show a batch/time; a synthetic pending migration shows `pending`.
   - Logs list + delete/delete-all happy paths against a temp fixture logs dir.

## 6. Test plan
- **Unit:** `tail_lines()` (respects max, bounded memory), `log_dir_files()` (filters non-`.log`,
  `.`/`..`, sorts by mtime).
- **Integration (feature, sandbox MySQL `db_proxy_test`):** each admin route; migrations status
  merge; log delete/delete-all; system info counts.
- **Security (must-have):**
  - **Path traversal** for every log filename entry point (preview/download/delete).
  - **No-secrets-leak** on system info.
  - **Gating**: non-admin and unauthenticated access blocked on all routes.
- **Browser / E2E:** admin opens System → sees info; opens Migrations → sees applied/pending;
  opens Logs → previews a file in modal, downloads one, deletes one via confirm modal (toast shows).
- **Manual smoke:** `php spark serve`, log in as admin, visit `/admin/system`,
  `/admin/system/migrations`, `/admin/system/logs`; confirm no secret appears in page source
  (view-source search for DB password / key).

## 7. Risks & open questions
- **DECISION — web-triggered migrations are dangerous.** Running/rolling back migrations from the
  browser is destructive (can drop tables / lose data). **This card ships migrations as read-only
  status only.** Destructive runs stay on the CLI (`php spark migrate` / `migrate:rollback`).
  *Optional, off by default:* if run/rollback are ever wanted, gate behind admin + CSRF + an explicit
  confirm modal **and** an env flag `ALLOW_WEB_MIGRATIONS` (never enabled by default) with a loud
  warning banner. Recommend deferring these to a separate card; do not build them now unless the
  user explicitly asks.
- **RISK — path traversal on log files.** Mitigation: never build a path from raw request input;
  `basename()` + membership check against `log_dir_files()` before any `fopen`/`unlink`/`download`.
- **RISK — secret leakage on system info.** Mitigation: whitelist the exact display keys; never
  iterate `getenv()`/`$_ENV` or read config credential properties. Covered by a dedicated test.
- **RISK — large log files (multi-MB, e.g. current 8 MB).** Mitigation: `tail_lines()` via
  `SplFileObject` seek, never `file_get_contents`/`file()` the whole file; download streams via
  `response->download()` on the real path (no temp copy — drop the legacy `tempnam` copy).
- **Open question:** should the System landing be a single info page with tabs, or three sidebar
  entries (System / Migrations / Logs)? Plan assumes one landing + two sub-pages, one grouped
  sidebar entry. Adjust nav in Phase 2 if you prefer flat links.
- **Note:** migrations status reads the framework `migrations` table via Query Builder (not a Model,
  not raw SQL) — acceptable since it's a framework-managed table with no domain model.

## 8. Rollout
- No DB migration; no new env var required. `ALLOW_WEB_MIGRATIONS` only relevant if the optional
  guarded actions are built later (default unset → disabled).
- Deploy order: backend (Phase 1) → frontend (Phase 2) → tests (Phase 3). No data backfill.
- Post-deploy: verify `writable/logs/` is readable by the web user; confirm `/admin/system` loads
  and no secret appears in page source.
- After sign-off, `reviewer` moves this file to `kanban/done/`.
