# Plan: System info — deep details (OPcache, PHP config, server info, extensions)

**Task:** 20260714
**Created:** 2026-07-14
**Branch suggestion:** `feature/system-info-details`

## 1. Goal
Extend the existing admin System info page (`Admin\System::index` → `admin/system/info`) with four
runtime-introspection sections: OPcache status + a reset action, a curated PHP Configuration view,
Server Information, and a collapsed-by-default PHP Extensions list. This is item 7 of the current
UI/UX goals. Every value is an explicit safe allowlist — **no `phpinfo()`, no secrets, no deploy
paths**.

## 2. Scope
- **In scope (extend, do not rebuild):**
  - **OPcache** panel: summary from `opcache_get_status(false)` (enabled, memory used/free/wasted,
    hit rate, misses, cached scripts/keys, restarts). Graceful "OPcache not enabled" state when
    `function_exists('opcache_get_status')` is false or the call returns `false`.
  - **Reset OPcache** button → new POST (CSRF) admin-only endpoint calling `opcache_reset()`, result
    surfaced via toast. No-op with a clear message when OPcache is absent.
  - **PHP Configuration**: an explicit allowlist of non-sensitive `ini_get()` directives (limits,
    timezone, error display flags). No path-like directives.
  - **Server Information**: an explicit allowlist — PHP SAPI, OS name/release/arch (no hostname),
    server software, HTTP host.
  - **PHP Extensions**: `get_loaded_extensions()` + `phpversion($ext)`, rendered folded/collapsed by
    default (Alpine collapse).
  - All four sections appended to the **same** `admin/system/info` view under the existing System
    sidebar group; version/env/db/counts/writable panels stay unchanged.
- **Out of scope:**
  - Full `phpinfo()` output, any raw `$_ENV` / `$_SERVER` / `getenv()` dump, config-object property
    dumps, path directives (`error_log`, `session.save_path`, `open_basedir`, `doc_root`,
    `extension_dir`, `sys_temp_dir`, `upload_tmp_dir`).
  - Per-script OPcache listing (`opcache_get_status(true)` scripts array leaks absolute paths).
  - Any OPcache write/config action other than `opcache_reset()`.
  - Migrations / logs viewers (already shipped in `20260714-system-core`), new sidebar entries.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Admin/System.php` | extend `index()` to pass a new `runtime` payload; add `buildRuntimeDetails(): array` (testable allowlist builder) + `resetOpcache()` POST endpoint |
| Frontend (Views) | `app/Views/admin/system/info.php` | append 4 panels (OPcache + reset, PHP config, server info, collapsed extensions) below existing grid |
| Database | none | pure runtime introspection, no SQL |
| Config / Routes | `app/Config/Routes.php` | one new route in the existing admin group: `POST system/opcache/reset` (csrf filter) |
| Tests | `tests/Controllers/Admin/SystemInfoDetailsTest.php` (or extend existing System test) | no-secret-leak across new sections, OPcache-absent degrade, reset admin+CSRF gating, extensions present |

## 4. Impact analysis
No existing symbol is renamed; changes are additive plus one method body extension.

- **`Admin\System::index()`** — the only edit to an existing method: it will pass an extra
  `runtime` array to the same view. Sole caller is the `GET system` route. Blast radius: **1 route,
  the info view**. Low risk. `buildInfoData()` is left untouched so its no-secrets unit test keeps
  passing; the new data comes from a separate `buildRuntimeDetails()` so it is independently
  unit-testable (same pattern as `buildInfoData()` / `buildMigrationRows()`).
- **`app/Views/admin/system/info.php`** — appends panels after line ~103 (before `</div>` /
  `endSection`). Existing panels untouched; other views unaffected.
- **`Routes.php`** — one appended `$routes->post('system/opcache/reset', ...)` inside the existing
  `admin` group (`authFilter` + `adminFilter`). No existing route changed.
- **New `resetOpcache()` / `buildRuntimeDetails()`** — new symbols, no callers yet. Additive.

Blast radius: **small / low risk.** CodeGraph impact on `index` = single route caller; report
confirms no other consumer. (Legacy `../proxy.services.vn` dashboard has no equivalent runtime panel
— this is largely new PHP-runtime introspection; nothing to port.)

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Add route to `app/Config/Routes.php` in the admin group — owner: `backend-coder`
   - `POST system/opcache/reset` → `Admin\System::resetOpcache` with `['filter' => 'csrf']`.
   - Acceptance: `php spark routes` lists it under the admin group with `authFilter,adminFilter,csrf`.

2. [ ] Add `buildRuntimeDetails(): array` to `Admin\System` — owner: `backend-coder`
   - Returns four explicit, allowlisted sub-arrays (mirrors the `buildInfoData()` whitelist
     discipline; touches no request/response state so it is unit-testable on a bare instance):
     - `opcache`: if `function_exists('opcache_get_status')` and `opcache_get_status(false)` is an
       array → `['available' => true, 'enabled' => ..., 'memory' => used/free/wasted (+ percent),
       'hit_rate', 'hits', 'misses', 'num_cached_scripts', 'num_cached_keys',
       'oom_restarts'/'manual_restarts']`. Else `['available' => false]`.
       **Call `opcache_get_status(false)`** (pass `false`) so the per-script array — which contains
       absolute file paths — is never fetched.
     - `php_config`: explicit allowlist via `ini_get()` — `memory_limit`, `max_execution_time`,
       `max_input_time`, `max_input_vars`, `upload_max_filesize`, `post_max_size`, `max_file_uploads`,
       `default_socket_timeout`, `date.timezone`, `display_errors`, `error_reporting`,
       `session.gc_maxlifetime`, `opcache.enable`. **No path/log directives.**
     - `server`: `php_sapi_name()`, `PHP_OS` / `php_uname('s')`, `php_uname('r')` (release),
       `php_uname('m')` (arch) — **never `php_uname('a')` or `'n'` (leaks hostname)** — plus
       `$_SERVER['SERVER_SOFTWARE']` and `$_SERVER['HTTP_HOST']` read individually (never iterate
       `$_SERVER`). Cast to string, default `''` when unset.
     - `extensions`: `get_loaded_extensions()` sorted, each `['name' => $e, 'version' =>
       (string) (phpversion($e) ?: '')]`.
   - `// ponytail:` comment noting the allowlist is deliberate — never widen it to a loop over
     `$_SERVER`/`ini_get_all()`/`phpinfo()`.
   - Acceptance: returns the four keys; `opcache.available === false` when the extension is missing;
     no returned value contains a filesystem path, DB credential, encryption key, or hashids salt.

3. [ ] Extend `index()` to pass runtime data — owner: `backend-coder`
   - `return view('admin/system/info', $this->buildInfoData() + ['runtime' => $this->buildRuntimeDetails()]);`
   - Acceptance: info page still renders existing panels plus the four new ones; still `GET`, no writes.

4. [ ] Add `resetOpcache()` POST endpoint — owner: `backend-coder`
   - Guard: if `!function_exists('opcache_reset')` → `jsonFailed('OPcache is not enabled.')` (no-op).
   - Else call `opcache_reset()`; `jsonSuccess('OPcache reset.')` on true, `jsonFailed(...)` on false.
   - State-changing → relies on route `csrf` filter + `adminFilter`; performs no DB work.
   - Acceptance: admin POST with valid CSRF resets and returns success envelope; missing/invalid CSRF
     rejected; non-admin/guest blocked; when OPcache absent returns the clear failure message and
     does nothing.

### Phase 2 — Frontend (Views)
1. [ ] Append the four panels to `app/Views/admin/system/info.php` — owner: `frontend-coder`
   - **OPcache panel**: when `$runtime['opcache']['available']` → key/value grid (enabled badge,
     memory used/free/wasted with a small usage bar, hit rate %, hits/misses, cached scripts/keys,
     restarts) + a **Reset OPcache** button. When not available → muted "OPcache not enabled" note,
     no button.
   - **Reset button**: shared confirm modal → `postForm('/admin/system/opcache/reset')` with CSRF
     token → toast the result. Reuse the existing shared toast/confirm + `postForm()` helpers.
   - **PHP Configuration panel**: two-column `dl` of the allowlisted directives.
   - **Server Information panel**: two-column `dl` of the allowlisted server values.
   - **PHP Extensions panel**: `x-data="{ open: false }"`, header toggles, list `x-show="open"`
     collapsed by default; render as a wrap of name+version chips.
   - `esc()` every server-derived value (and `x-text`/`esc()` for any Alpine-bound output). Match the
     existing TailAdmin card styling (`rounded-lg border border-[#EAEAEA] bg-white p-6`).
   - UI notes: TailAdmin/Tailwind + Alpine collapse; `fetch()` via shared `postForm()`; frontend-coder
     loads `minimalist-ui` (fits TailAdmin) before this visual work.
   - Acceptance: all four panels render; extensions collapsed until clicked; reset button only shown
     when OPcache available; no secret/path visible in page source.

### Phase 3 — Tests
1. [ ] Feature/unit tests — owner: `tester`
   - See §6.

## 6. Test plan
- **Unit (`buildRuntimeDetails()` on a bare instance):**
  - Returns keys `opcache`, `php_config`, `server`, `extensions`.
  - `extensions` non-empty and each entry has `name` + `version`.
  - **No-secret / no-path leak:** seed a known secret in the test env (e.g. a distinctive
    `encryption.key` / DB password value) and assert the serialized `buildRuntimeDetails()` output
    contains none of it, no `WRITEPATH`/`ROOTPATH`/`APPPATH` substring, and no `phpinfo`-style dump.
  - When OPcache functions are unavailable in the test runtime, `opcache.available === false` and no
    fatal — assert the graceful shape. (If OPcache IS loaded in CI, assert `available === true` and
    the summary keys exist; branch the assertion on `function_exists`.)
- **Integration (feature, admin session):**
  - `GET /admin/system` returns 200 and the response body contains none of the seeded secret / no
    absolute deploy path across the whole (now larger) page.
  - `POST /admin/system/opcache/reset` with valid CSRF as admin → success envelope (or the clear
    "not enabled" failure when OPcache absent); the endpoint does nothing destructive to DB.
  - **Gating:** `POST /admin/system/opcache/reset` as guest and as non-admin user → redirect/403;
    without CSRF token → rejected.
- **Browser / E2E:** admin opens `/admin/system`, sees OPcache/PHP-config/server panels; expands the
  collapsed Extensions list; clicks Reset OPcache → confirm modal → toast shows the result.
- **Manual smoke:** `php spark serve`, log in as admin, visit `/admin/system`; view-source and search
  for the DB password / encryption key / an absolute path — confirm none appear; toggle Extensions;
  reset OPcache and observe the toast.

## 7. Risks & decisions
- **RISK (biggest) — secret / full-phpinfo leak.** Mitigation: every section is an explicit
  hand-picked allowlist; **never** `phpinfo()`, `ini_get_all()`, or a loop over `$_ENV`/`$_SERVER`.
  PHP-config allowlist excludes all path/log directives. Covered by a dedicated seeded-secret and
  no-path test asserting absence across the whole page/JSON.
- **DECISION — `opcache_get_status(false)`.** Pass `false` so the per-script array (absolute file
  paths → deploy layout) is never retrieved; only aggregate counters are shown.
- **DECISION — server info excludes hostname.** Use `php_uname('s'/'r'/'m')`, never `'a'` or `'n'`;
  read only `SERVER_SOFTWARE` and `HTTP_HOST` from `$_SERVER`, individually.
- **RISK — OPcache reset is state-changing.** Mitigation: POST + `csrf` filter + `adminFilter` +
  confirm modal; no-op with a clear message when OPcache absent (guarded by `function_exists`).
- **DECISION — OPcache availability varies by runtime** (CLI/test SAPI often lacks it). Graceful
  degrade path is a first-class requirement, and tests branch on `function_exists('opcache_get_status')`
  rather than assuming it is loaded.
- **Open question:** OPcache reset only clears the master process's cache; on FPM with multiple pools
  it may not clear every worker. Acceptable for an admin convenience button; note it in the UI helper
  text if desired. (No change to plan.)

## 8. Rollout
- No DB migration, no new env var. Purely additive UI + one POST endpoint.
- Deploy order: backend (Phase 1) → frontend (Phase 2) → tests (Phase 3).
- Post-deploy: verify `/admin/system` loads, no secret/path in page source, Reset OPcache toasts a
  result (success where OPcache is enabled, clear "not enabled" message otherwise).
- After sign-off, `reviewer` moves this file to `kanban/done/`.
