# Plan: Admin Servers management module (CRUD)

**Task:** 20260714-admin-servers
**Created:** 2026-07-14
**Branch suggestion:** `feature/admin-servers`

## 1. Goal
Give admins a Servers management screen (list/search/paginate + create/edit/delete)
mirroring the legacy `Admin\Servers`, rebuilt on the established admin-CRUD pattern
(`Admin\Users` + `UserModel` + `admin/users/index.php`) with a proper Model/Query
Builder, CI4 Validation, CSRF, `esc()`, and a dark-mode-aware TailAdmin view.

## 2. Scope
- **In scope:**
  - `ServerModel` (soft-deletes, `returnType=object`, keyword+status search).
  - Idempotent `CreateServers` migration (guarded `resetDataCache()` -> `tableExists`).
  - `Admin\Servers` controller: `index` (list+search+paginate), `create`, `edit` (JSON prefill), `update`, `delete`.
  - Routes under the existing `admin` group (CSRF on all POSTs).
  - Views: `admin/servers/index.php` (list + create/edit Alpine modal) reusing `layouts/app` + shared toast/confirm/alert.
  - Sidebar "Servers" entry in the Admin nav group (`layouts/app.php`).
  - Tests: CRUD, validation, admin gating, soft-delete-excluded-from-list.
  - `dark:` variants on the new view.
- **Out of scope:** scheduled tasks, translations, emails/templates, site settings
  (later cards); dark sweep of other existing pages; any live "test connection to
  server" action; per-server credentials (the table has none — see §7).

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Models/ServerModel.php` (new) | mirror `UserModel::searchAdmin/countAdmin` |
| Backend | `app/Controllers/Admin/Servers.php` (new, rewrite) | extend `BaseController`; `jsonSuccess`/`jsonFailed` envelope |
| Database | `app/Database/Migrations/2026-07-14-110000_CreateServers.php` (new) | idempotent, creates table (does NOT exist yet on dev/test) |
| Frontend | `app/Views/admin/servers/index.php` (new) | list + create/edit modal, dark-mode |
| Frontend | `app/Views/layouts/app.php` (edit) | add `['label' => 'Servers', 'href' => '/admin/servers']` to `$navGroups['Admin']` |
| Config/Routes | `app/Config/Routes.php` (edit) | add 5 routes inside the `admin` group |
| Tests | `tests/Controllers/Admin/ServersTest.php` (new) | feature tests |

## 4. Impact analysis
CodeGraph index is empty for the new app; used `grep` for callers.
- **New symbols** (`ServerModel`, `Admin\Servers`, `CreateServers`, view): zero existing callers — blast radius nil.
- **`app/Config/Routes.php`**: additive only (new routes in an existing group). No existing route touched.
- **`app/Views/layouts/app.php`**: additive only (one new `$navGroups['Admin']` entry). Rendered on every admin page; a single array element is low-risk and can't affect other zones (`$navZone === 'admin'` guard already present).
- Legacy repo grep confirms the `servers` table has exactly one consumer (the legacy controller) — no cross-module coupling to preserve.
- **No large/critical blast radius.**

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Create migration `2026-07-14-110000_CreateServers.php` — owner: `backend-coder`
   - Columns (real legacy schema, confirmed via DB + legacy controller): `id` BIGINT AI PK,
     `name` VARCHAR(255) null, `ip` VARCHAR(255) null, `status` INT default 0,
     `created_at`/`updated_at`/`deleted_at` DATETIME null.
   - Use the exact idempotent pattern from `CreateUsers`: `Database::connect($this->DBGroup)->resetDataCache()`,
     early-return if `tableExists('servers')`, `down()` only drops when connected to the `tests` DB group.
   - Add an index on `status` (list orders/filters by it) per `mysql-patterns`.
   - Acceptance: `php spark migrate` creates `servers` on dev; re-running is a no-op; test suite's regress()+latest() cycle recreates cleanly.
2. [ ] Create `ServerModel` — owner: `backend-coder`
   - `table='servers'`, `primaryKey='id'`, `returnType='object'`, `useSoftDeletes=true`,
     `useTimestamps=true` (`created_at`/`updated_at`), `deletedField='deleted_at'`,
     `allowedFields=['name','ip','status']`.
   - `searchAdmin(array $filters, string $sort, string $order, int $limit, int $offset): array` and
     `countAdmin(array $filters): int` sharing a private `applyAdminFilters()` — keyword LIKE across
     `name`/`ip`, optional `status` (0/1) filter; sort allowlist `['id','name','ip','status','created_at']`,
     secondary `id` tiebreak in the same direction (mirror `UserModel`). Default order `status DESC` then `id DESC`.
   - `findServer(int $id): ?object` typed wrapper over `find()`.
   - Acceptance: soft-deleted rows excluded automatically; keyword/status filters produce matching counts (`searchAdmin` rows == `countAdmin` for same filters).
3. [ ] Create `Admin\Servers` controller extending `BaseController` — owner: `backend-coder`
   - `index()`: read `page`/`limit` (clamp via a `resolveLimit()` like `Admin\Users`), `keyword`, `status`, `sort`, `order`; render `admin/servers/index`.
   - `create()`: validate, insert, `jsonSuccess('Server has been created successfully.')`.
   - `edit(int $id)`: return `jsonSuccess('OK', ['server' => [...]])` for modal prefill; `jsonFailed('Server not found.', 404)` if missing.
   - `update()`: validate (incl. `id`), guard not-found, update, `jsonSuccess`.
   - `delete()`: validate `id`, soft-delete via Model, `jsonSuccess('Server has been deleted.')`.
   - Validation rules (private helper, create vs update): `name` required|max_length[255];
     `ip` required|max_length[255] (accept hostname or IP — no `valid_ip` since legacy field is a free-form host/ip);
     `status` permit_empty|in_list[0,1]; `id` required|is_natural_no_zero on update/delete.
   - No credentials anywhere -> no write-only/masking logic needed (see §7).
   - Acceptance: all responses use the `status`/`message`/`csrf_hash` envelope; invalid input returns field errors via `jsonFailed`.
4. [ ] Add routes to the `admin` group in `Config/Routes.php` — owner: `backend-coder`
   - `get('servers', 'Admin\Servers::index')`;
     `post('servers/create', ..., ['filter' => 'csrf'])`;
     `get('servers/edit/(:num)', 'Admin\Servers::edit/$1')`;
     `post('servers/update', ..., ['filter' => 'csrf'])`;
     `post('servers/delete', ..., ['filter' => 'csrf'])`.
   - Acceptance: `php spark routes` lists all five under `authFilter`+`adminFilter`.

### Phase 2 — Frontend (Views)
1. [ ] Create `app/Views/admin/servers/index.php` — owner: `frontend-coder`
   - Load a UI/UX skill (`minimalist-ui` fits TailAdmin) before non-trivial visual work.
   - `$this->extend('layouts/app')`; mirror `admin/users/index.php`: search bar (keyword + status
     select), sortable columns, table (Name, IP, Status badge, Created), pagination via the same query-string helper.
   - Create/Edit in one shared Alpine modal (`x-data`), submitted with `fetch()` + CSRF token; edit fetches
     `/admin/servers/edit/{id}` to prefill. Delete uses the shared confirm modal then `fetch()` to `servers/delete`.
   - Success/error surfaced via the shared toast; update stored CSRF hash from `csrf_hash` in each JSON response.
   - `esc()`/`x-text` on every dynamic value; full `dark:` variants so the page matches the shell.
   - Acceptance: create/edit/delete round-trip without full page reload; status badge reflects 0/1; no jQuery/Bootstrap; passes visual dark-mode check.
2. [ ] Add the Servers nav entry to `$navGroups['Admin']` in `layouts/app.php` — owner: `frontend-coder`
   - Acceptance: "Servers" link visible in the admin sidebar, absent in client zone.

### Phase 3 — Tests
1. [ ] `tests/Controllers/Admin/ServersTest.php` — owner: `tester`
   - Feature tests against sandbox `db_proxy_test` (RUNNING_MODE=testing), phpstan lvl5 clean.

## 6. Test plan
- **Unit (ServerModel):** keyword LIKE matches name & ip; status filter; sort allowlist rejects
  arbitrary column (falls back); soft-deleted row excluded from `searchAdmin`/`countAdmin`.
- **Integration (controller):**
  - `create` with valid data inserts and returns success envelope; missing `name`/`ip` returns field errors.
  - `edit` returns server JSON; unknown id -> 404 `jsonFailed`.
  - `update` changes fields; unknown id -> 404.
  - `delete` soft-deletes (row gone from list, `deleted_at` set).
  - `index` paginates and honors keyword/status filters.
- **Gating:** unauthenticated -> redirect to login; authenticated non-admin (`zone=client`) -> blocked by `adminFilter`; POST without CSRF -> rejected.
- **Browser/E2E:** admin opens /admin/servers, creates a server, edits it, filters by keyword/status, deletes with confirm — all via toast, no reload.
- **Manual smoke:** `php spark migrate` on dev creates `servers`; add one server; confirm sidebar link and dark mode.

## 7. Risks & open questions (key decisions)
- **DECISION — real schema:** `servers` does NOT exist in `db_proxy_dev`/`db_proxy_test`
  (only DBs present) and the legacy controller reads/writes only `name`, `ip`, `status`
  (+ `created_at`/`updated_at`/`deleted_at`). So the migration genuinely **creates** the table
  (the idempotent guard is retained for safety/test cycles, not because a live table exists).
- **DECISION — no credentials:** there are **no** credential/password/key columns on `servers`.
  The secret-handling requirement (masking/write-only/no-log) is **N/A** for this card — do not
  add credential fields speculatively (YAGNI). If a future card needs upstream auth per server,
  it gets its own migration + write-only handling like the user-password pattern.
- **DECISION — keyword match:** legacy used exact `name = ? OR ip = ?`; new uses `LIKE` for
  usability, consistent with `UserModel::searchAdmin`.
- **DECISION — `ip` validation:** kept as free-form host/IP (`max_length`, not `valid_ip`) to match
  the legacy VARCHAR field, which may hold hostnames.
- Risk: `layouts/app.php` renders on every admin page — the nav edit is a single additive array
  element behind the existing `$navZone === 'admin'` guard, so client zone is unaffected.

## 8. Rollout
- Migration order: new file `2026-07-14-110000_CreateServers.php` runs after the latest
  (`2026-07-14-100000_AddPhoneNumberToUsers.php`). `php spark migrate` on each env.
- No env vars, no feature flag. No data backfill (fresh table).
- Deploy note: run migrations before serving the new route; no downtime.

---
Recommended next agent: `backend-coder` (Phase 1), then `frontend-coder` (Phase 2), then `tester` (Phase 3).
