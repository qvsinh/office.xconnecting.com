# Plan: Admin Site Settings / Site Variables (key/value CRUD)

**Task:** 20260714-admin-settings
**Created:** 2026-07-14
**Branch suggestion:** feature/admin-settings

## 1. Goal
Give admins CRUD over `site_variable` key/value config, and a `get_site_variable()`
read helper so those values actually take effect in the app. Mirror the approved
`Admin\Servers` / `Admin\Users` admin-CRUD pattern exactly — do not reinvent.

## 2. Scope
- **In scope:**
  - `SiteVariableModel` (searchAdmin/countAdmin/find + soft-delete), matching the real schema.
  - Idempotent migration for `site_variable` (guarded: table already exists in prod).
  - `Admin\Settings` controller: index / create / edit(JSON) / update / delete.
  - Routes in the admin group (authFilter+adminFilter; POSTs behind `csrf`).
  - TailAdmin list + create/edit modal view (dark-aware), sidebar "Settings" entry.
  - `get_site_variable(name, default)` read helper (none exists in this repo yet).
  - Tests: CRUD, validation (required name + unique name), auth/admin gating.
- **Out of scope (later cards):** wiring settings into `CnrClient`/`JprsClient`/2FA
  (they read `.env` today — the helper just makes it *possible* later); scheduled
  tasks; translations; emails + templates; child-view dark sweep; polish batch;
  editing the `type` column via UI (DB default `'string'` stands).

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Models/SiteVariableModel.php` (new) | Mirror `ServerModel`; keyword LIKE on name/value/description |
| Backend | `app/Controllers/Admin/Settings.php` (new) | Mirror `Admin\Servers` |
| Backend | `app/Helpers/app_helper.php` (edit) | Add `get_site_variable()` |
| Database | `app/Database/Migrations/2026-07-14-120000_CreateSiteVariable.php` (new) | Idempotent; real schema |
| Config / Routes | `app/Config/Routes.php` (edit, admin group) | 5 routes |
| Frontend (Views) | `app/Views/admin/settings/index.php` (new) | Mirror `admin/servers/index.php` |
| Frontend (Views) | `app/Views/layouts/app.php` (edit) | Sidebar Admin group: add Settings link |
| Tests | `tests/...` feature test for `Admin\Settings` | Mirror the Servers feature test |

## 4. Impact analysis
All backend symbols are **new** (`SiteVariableModel`, `Admin\Settings`) — zero blast
radius on existing code. Two edits to existing files, both additive:
- `app/Helpers/app_helper.php` — **new** function `get_site_variable()`; grep confirms
  no current caller of that name in this repo, so no collision. Blast radius: none.
- `app/Config/Routes.php` (admin group) + `app/Views/layouts/app.php` (sidebar array) —
  append-only, same shape as the existing Servers/Users entries. Blast radius: none.
No existing symbol is renamed or modified → no critical blast radius.

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Migration `2026-07-14-120000_CreateSiteVariable.php` — owner: `backend-coder`
   - Match real schema (from legacy `setup/db_proxy_service.sql`):
     `id` INT UNSIGNED AI, `name` VARCHAR(100) NOT NULL + **UNIQUE**, `value`
     VARCHAR(255) NULL, `type` VARCHAR(50) DEFAULT `'string'`, `description` TEXT NULL,
     `created_at` DATETIME NULL, `deleted_at` DATETIME NULL. **No `updated_at`.**
   - Idempotent: `Database::connect()->resetDataCache()` then `tableExists('site_variable')`
     early-return (table already exists in `db_proxy_dev`/prod; genuinely creates on the
     `tests` DB). `down()` drops only on the tests DB group — copy `CreateServers` verbatim.
   - Acceptance: `php spark migrate` is a no-op on dev; creates the table with UNIQUE(name)
     on the tests DB; re-running does not error.
2. [ ] `SiteVariableModel` — owner: `backend-coder`
   - Mirror `ServerModel`: `$table='site_variable'`, `returnType='object'`,
     `useSoftDeletes=true`, `allowedFields=['name','value','type','description']`,
     `useTimestamps=true`, `createdField='created_at'`, `deletedField='deleted_at'`,
     and **`protected $updatedField = ''`** so CI4 never writes the absent `updated_at`.
   - `SORT_ALLOWLIST=['id','name','value','type','created_at']` (default sort `name` ASC);
     `KEYWORD_FIELDS=['name','value','description']`.
   - `findVariable(int $id): ?object` (typed `find()` wrapper), `searchAdmin()`,
     `countAdmin()`, `applyAdminFilters()` — copied from `ServerModel` (no `status` filter;
     keyword only).
   - Acceptance: phpstan lvl5 clean; `searchAdmin`/`countAdmin` share `applyAdminFilters`.
3. [ ] `Admin\Settings` controller — owner: `backend-coder`
   - Mirror `Admin\Servers`: `index()` (list, default sort `name`/`asc`), `create()`,
     `edit(int $id)` (JSON record for modal), `update()`, `delete()`; all via
     `jsonSuccess`/`jsonFailed` (BaseController). `resolveLimit()` clamp copied.
   - Validation rules: `name => required|max_length[100]|is_unique[site_variable.name]`
     (create) / `...is_unique[site_variable.name,id,{id}]` (update);
     `value => permit_empty|max_length[255]`; `description => permit_empty`;
     `id => required|is_natural_no_zero` (update/delete). `type` not user-editable (DB default).
   - Acceptance: create rejects duplicate `name`; edit returns 404 JSON for missing id;
     delete soft-deletes; all POSTs require CSRF (route-level).
4. [ ] `get_site_variable(string $name, mixed $default = null)` helper in `app_helper.php` — owner: `backend-coder`
   - Look up non-deleted row by `name` via `SiteVariableModel`, return its `value`;
     fall back to `$default` (or `env($default)` intent — keep it a single default arg,
     simpler than the legacy two-arg `($dbName,$envName)` form) when absent/null.
   - Acceptance: returns stored value when present; returns `$default` when the name is
     unknown or soft-deleted; one runnable assert-style check covers both paths.
5. [ ] Routes in admin group (`app/Config/Routes.php`) — owner: `backend-coder`
   - `get settings`, `post settings/create`, `get settings/edit/(:num)`,
     `post settings/update`, `post settings/delete` → `Admin\Settings::*`; POSTs `['filter'=>'csrf']`.
   - Acceptance: `php spark routes` lists all five under the authFilter+adminFilter group.

### Phase 2 — Frontend (Views)
1. [ ] `app/Views/admin/settings/index.php` — owner: `frontend-coder`
   - Copy `admin/servers/index.php`: TailAdmin list (dark-mode variants), keyword search,
     pagination, create + edit modal driven by Alpine + `fetch()` (CSRF token, refresh
     `csrf_hash` from JSON), shared toast/confirm components. Columns: name, value,
     description, created_at, actions (edit/delete). `esc()` / `x-text` every value.
   - UI notes: no `status` toggle column (site_variable has no status). Long `description`
     truncated in the cell. Value shown as-is (see §7 — no sensitive values expected).
   - Acceptance: list renders, search filters, create/edit/delete round-trip via `fetch()`
     with toasts; no jQuery/Bootstrap; dark mode correct.
2. [ ] Sidebar entry — owner: `frontend-coder`
   - Add `['label' => 'Settings', 'href' => '/admin/settings']` to `$navGroups['Admin']`
     in `app/Views/layouts/app.php` (after Servers).
   - Acceptance: "Settings" appears in the Admin sidebar group and links correctly.

### Phase 3 — Tests
1. [ ] `Admin\Settings` feature test — owner: `tester`
   - Mirror the Servers feature test on the sandbox MySQL `tests` group.

## 6. Test plan
- **Unit:** `get_site_variable()` — hit (returns value), miss (returns default),
  soft-deleted name (returns default); `SiteVariableModel::searchAdmin` keyword LIKE on
  name/value/description; `countAdmin` matches `searchAdmin` row count.
- **Integration (feature):** create (200 + persisted), duplicate name (422/validation
  error), edit JSON (record shape / 404 on missing), update (persisted + unique-excludes-self),
  delete (soft-delete, row hidden from list). CSRF hash returned in every JSON envelope.
- **Gating:** unauthenticated → redirect to login; authenticated non-admin → blocked by
  `adminFilter`; all POST routes reject missing CSRF.
- **Browser / E2E:** admin opens `/admin/settings`, searches, creates a variable, edits it,
  deletes it — toasts fire, no full-page reloads.
- **Manual smoke:** `php spark migrate` (no-op on dev), create `app_name`, confirm
  `get_site_variable('app_name')` returns it from a quick tinker/route.

## 7. Risks & open questions (decisions)
- **Real schema (CONFIRMED)** from legacy `setup/db_proxy_service.sql` (MySQL was
  unreachable from the shell, so schema taken from the committed dump):
  `site_variable(id INT UNSIGNED AI, name VARCHAR(100) NOT NULL UNIQUE, value VARCHAR(255)
  NULL, type VARCHAR(50) DEFAULT 'string', description TEXT NULL, created_at DATETIME,
  deleted_at DATETIME)`. **No `updated_at`** → model uses `$updatedField = ''`.
  Table already exists in dev/prod (dump shows it empty) → migration is guard-gated and
  effectively a no-op there; it genuinely creates on the fresh `tests` DB.
  `backend-coder`: verify `SHOW COLUMNS FROM site_variable` on `db_proxy_dev` once MySQL is
  reachable; adjust only if it diverges from the dump.
- **Sensitive values:** the variable names actually referenced in the legacy code are
  `app_name`, `email_support`, `cnr_url`, `jprs_gjp_url`, `jprs_ojp_url`,
  `security_2fa_enable/name`, `security_force_2fa`, `security_email_otp_expire_time` — all
  non-secret config, and `value` is VARCHAR(255) (no password/key columns). **Secret-masking
  is N/A for v1.** ponytail: skip masking — add a masked display (name matches
  `/pass|secret|key|token/i`) only if real data later holds a sensitive value.
- **Read helper existence:** none in this repo (grep confirms zero `get_site_variable`/
  `getValueByName` usages in `app/`); the legacy two-arg form lives only in the old repo.
  We add a single-default-arg version — simpler, and nothing depends on the old signature.
- **`type` column:** kept in schema/allowedFields but **not** user-editable in v1 (DB default
  `'string'`). Add typed inputs later only if a real need appears.
- **Upsert vs insert:** legacy `create()` used `->replace()` (upsert on unique name); we use
  `insert()` + `is_unique` validation instead so duplicates return a clean field error.

## 8. Rollout
- Migration `2026-07-14-120000_CreateSiteVariable` runs after existing migrations; no-op on
  dev/prod (table exists), creates on tests. No env vars, no feature flag.
- No data seeding — admins create variables via the UI. (`get_site_variable()` returns the
  caller's default until a matching row exists, so nothing breaks pre-populate.)
- Deploy note: purely additive (new controller/model/view/helper/routes + one sidebar line);
  no existing behaviour changes.
