# Plan: API History viewer UI (user self-service + admin)

**Task:** 20260713
**Created:** 2026-07-13
**Branch suggestion:** feature/api-history-viewer-ui

## 1. Goal
Give users a self-service `/api-history` screen to browse, filter, inspect, and prune the
`api_history` rows written by their own proxied CNR/JPRS calls, and give admins an
`/admin/api-history` screen that does the same across every user (with a per-user filter). All
data access goes through `ApiHistoryModel` (no raw SQL), every user-side read/mutation is
owner-scoped, and destructive actions are soft-deletes behind a confirm modal.

## 2. Scope
- **In scope:**
  - User area `/api-history` (behind `authFilter`): filtered + sortable + paginated list, detail
    preview modal, single delete, filtered delete-all, delete-by-age — all scoped to
    `session('userWorking')->id`.
  - Admin area `/admin/api-history` (behind `authFilter` + `adminFilter`): same filters PLUS a
    user filter (email / user id), preview, single delete, filtered delete-all across all users.
  - `ApiHistoryModel` method additions (filtered search + count, `findOwned`, `deleteOwned`,
    `deleteAllFiltered`, `deleteByAge`, and admin equivalents) with one shared filter builder.
  - New migration adding a `(user_id, created_at)` composite index (see §4 — it does not exist).
  - `Client\ApiHistory` + `Admin\ApiHistory` controllers; routes; two index views + one shared
    preview-modal partial; sidebar link activation; tests (incl. IDOR + XSS-on-preview).
- **Out of scope (later cards):**
  - CNR / JPRS developer consoles (`ConsoleCnr` / `ConsoleJprs`).
  - PSI Billing API console.
  - Admin user management, sub-users, notifications, 2FA.
  - Tailwind/npm asset build pipeline (still using the CDN `layouts/app` shell).
  - Hard-purge / retention automation of soft-deleted rows.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Models/ApiHistoryModel.php` | Add search/count/find/delete methods + one private `applyFilters()` builder; additive only |
| Backend | `app/Controllers/Client/ApiHistory.php` (new) | Owner-scoped list/preview/delete/delete-all/delete-by-age |
| Backend | `app/Controllers/Admin/ApiHistory.php` (new) | All-scoped list/preview/delete/delete-all + user filter |
| Frontend (Views) | `app/Views/client/api_history/index.php` (new) | Filters + sortable table + pagination + Alpine |
| Frontend (Views) | `app/Views/admin/api_history/index.php` (new) | Same + user column/filter |
| Frontend (Views) | `app/Views/api_history/_preview_modal.php` (new, shared) | JSON-fed detail modal, all fields via `x-text` |
| Frontend (Views) | `app/Views/layouts/app.php` | Activate the two "API history" links (currently "Coming soon" spans) |
| Database | `app/Database/Migrations/2026-07-13-130000_AddUserCreatedIndexToApiHistory.php` (new) | `(user_id, created_at)` composite index |
| Config / Routes | `app/Config/Routes.php` | New `api-history` routes in the user group + `admin/api-history` in the admin group |
| Tests | `tests/feature/Web/ClientApiHistoryTest.php`, `tests/feature/Web/AdminApiHistoryTest.php`, `tests/database/ApiHistoryModelTest.php` (extend) | IDOR, XSS-preview, filter/sort/paginate, soft-delete, delete-all filter scope, delete-by-age |

## 4. Impact analysis
CodeGraph is still empty this early in the rebuild, so blast radius was established with `grep`.

- **`ApiHistoryModel`** — current callers: `Api/ApiController.php` (`logApiCall` insert +
  `fetchHistory` via `->builder()`), and tests (`ApiHistoryModelTest`,
  `ApiControllerLogApiCallTest`, `CnrApiProxyTest`, `JprsApiProxyTest`). **All new work is
  additive** (new public methods + one private helper); no existing method signature or property
  changes. Blast radius: **low** — existing insert/fetch paths are untouched.
- **`layouts/app.php`** — rendered by every authenticated page. The only change is swapping two
  inert `<span>Coming soon</span>` placeholders for `<a>` links. Blast radius: **low**, cosmetic,
  but touches a shared file — keep the diff to those two lines per zone.
- **FINDING — no `user_id` index on `api_history`.** The create migration
  (`2026-07-12-110001_CreateApiHistory.php`) only indexes `(service_name, service_id)`, `command`,
  and `env`. The "user_id index added last card" was on **`users_api_access_keys`**
  (`2026-07-13-120000_AddUserIdIndexToUsersApiAccessKeys.php`), a different table. The viewer
  filters **every** query by `user_id` and sorts by `created_at DESC` by default, so a
  `(user_id, created_at)` composite index is required (leftmost-prefix also helps the existing
  `fetchHistory` per-user filter). This is a new migration, not an edit to the create migration.

## 5. Implementation steps

### Phase 1 — Backend: model + index

1. [ ] Add migration `2026-07-13-130000_AddUserCreatedIndexToApiHistory.php` — owner: `backend-coder`
   - Add composite key `idx_api_history_user_created (user_id, created_at)` in `up()`; drop it in
     `down()`. Follow **mysql-patterns** (leftmost-prefix rationale in a comment).
   - Acceptance: `php spark migrate` applies cleanly; `EXPLAIN` on the default user list query
     (`WHERE user_id = ? ORDER BY created_at DESC LIMIT ...`) uses the new index (documented in the PR).

2. [ ] Extend `ApiHistoryModel` with a single private filter builder + query methods — owner: `backend-coder`
   - Add `private function applyFilters(BaseBuilder $builder, array $filters): void` — the **one**
     place that translates `keyword` (space-split, each term grouped `LIKE`/`orLike` across
     `service_name`/`command`/`domain`/`url`/`params`/`response`), `service_name` (exact),
     `service_id` (numeric only, `ctype_digit`), `env` (exact), `status` (`'0'`/`'1'` only). Reuse
     it from every list/count/delete-all/delete-by-age path (ponytail: fix once, no per-caller
     duplication). Mirror the existing `ApiAccessKeyModel::adminBuilder()` structure.
   - Add user methods (all take `int $userId` and always add `->where('user_id', $userId)`):
     - `searchForUser(int $userId, array $filters, string $sortColumn, string $sortDir, int $limit, int $offset): array`
     - `countForUser(int $userId, array $filters): int` (separate count query, `countAllResults(false)`)
     - `findOwned(int $id, int $userId): ?array` (by `id` AND `user_id`; null if absent/foreign — no existence leak)
     - `deleteOwned(int $id, int $userId): bool` (soft-delete only when the row is owned)
     - `deleteAllFiltered(int $userId, array $filters): int` (chunked 500 soft-delete; returns count)
     - `deleteByAge(int $userId, string $env, int $days, array $filters): int` (adds `created_at <` cutoff + `env`; chunked soft-delete)
   - Add admin methods (no user scoping; join `users` for email/name display):
     - `searchAdmin(array $filters, ...)`, `countAdmin(array $filters)` — `applyFilters()` plus a
       `user` filter (exact `u.email` when it validates as email, else `uak.user_id`/numeric).
     - `findByIdAdmin(int $id): ?array`, `deleteByIdAdmin(int $id): bool`,
       `deleteAllFilteredAdmin(array $filters): int`.
   - Use **model query-builder chaining then `findAll()`/`countAllResults()`** (not `->builder()`)
     so the `useSoftDeletes` scope auto-applies `deleted_at IS NULL` — do not hand-add it. For the
     admin join, guard the soft-delete with an explicit `->where('deleted_at', null)` since the raw
     join builder bypasses the model scope (as `fetchHistory` already documents).
   - Whitelist sort columns via a model constant `SORT_ALLOWLIST = ['id','service_name','service_id','command','domain','status','created_at']`; caller-supplied column falls back to `created_at`, direction to `DESC`.
   - Acceptance: new methods parameterized (no string-concatenated SQL); `composer analyse` (phpstan
     L5) and `composer cs` pass; soft-deleted rows never returned by any read.

3. [ ] Create `Client\ApiHistory` controller (extends `BaseController`) — owner: `backend-coder`
   - `index()`: read `page`/`limit`/`keyword`/`service_name`/`service_id`/`env`/`status`/`sort_by`/`sort`
     from GET, resolve `session('userWorking')->id`, call `searchForUser` + `countForUser`, render
     `client/api_history/index`. Reuse the `resolveLimit()` pattern from `Admin\ApiAccessKeys`.
   - `preview()` (GET, JSON): `$id = (int) getGet('id')`; `findOwned($id, $me->id)`; return the row
     as JSON via `jsonSuccess` (or 404 `jsonFailed('Record not found.', 404)` if null — no
     existence leak). **Return the raw stored strings; do not pre-render HTML.**
   - `delete()` (POST): `deleteOwned((int) getPost('id'), $me->id)`; 404 envelope if not owned.
   - `deleteAll()` (POST): rebuild the same filter array from the request, call
     `deleteAllFiltered($me->id, $filters)`; return count in the success envelope; `warning`
     envelope when count is 0.
   - `deleteByAge()` (POST): validate `env` ∈ `['LIVE','TESTING']` and `days` > 0 with CI4
     `Validation`; call `deleteByAge($me->id, $env, $days, $filters)`.
   - Every JSON response uses the `jsonSuccess`/`jsonFailed` envelopes (they already inject
     `csrf_hash`). Never read a `user_id` from input.
   - Acceptance: unknown/foreign id → 404 with no existence leak; all mutations soft-delete only
     the session user's rows; controller contains zero `db_connect()`/raw SQL.

4. [ ] Create `Admin\ApiHistory` controller (extends `BaseController`) — owner: `backend-coder`
   - `index()`: same filters plus `user` (email/id); call `searchAdmin`/`countAdmin`; render
     `admin/api_history/index`. Fetch the user list (`UserModel->select('id,name,email')`) for the
     user-filter dropdown, mirroring `Admin\ApiAccessKeys`.
   - `preview()` (GET, JSON): `findByIdAdmin` (no owner scope); 404 if absent.
   - `delete()` (POST): `deleteByIdAdmin`. `deleteAll()` (POST): `deleteAllFilteredAdmin($filters)`.
   - **Fix the legacy bug by construction:** there is no `userAuthenticated` vs `userWorking`
     inconsistency here because the admin path is deliberately all-scoped and the user path is
     uniformly `session('userWorking')->id`.
   - Acceptance: admin can read/delete any user's rows; deletes are soft-deletes; no raw SQL.

### Phase 2 — Frontend (Views)

1. [ ] Build `app/Views/client/api_history/index.php` — owner: `frontend-coder`
   - Extend `layouts/app`. TailAdmin/Tailwind card + filter `<form method="get">` (keyword,
     service_name, service_id, env select `LIVE`/`TESTING`, status select ok/fail, per-page),
     sortable column headers (links that set `sort_by`+`sort` in the query string, showing the
     active arrow), and Prev/Page N of M/Next pagination — reuse the exact patterns from
     `admin/api_access_keys/index.php`.
   - Rows show env / service / service_id / command / domain / status badge / created_at, with
     Preview + Delete actions. A "Delete all (current filters)" button and a "Delete older than N
     days" control both route through the shared confirm modal (`$dispatch('confirm', …)`) with a
     body that states how many rows the current filters match (pass the total into the Alpine
     component from PHP).
   - AJAX via `postForm()` for delete / delete-all / delete-by-age; preview via `fetch()` GET to
     the JSON endpoint. On success, toast + update the Alpine list (or reload for delete-all).
   - UI notes: TailAdmin/Tailwind only, Alpine for interactivity, `fetch()`/`postForm()` for AJAX,
     shared toast/confirm components, `esc()` every server-rendered value (`esc(..., 'attr')` in
     attributes; `esc(json_encode(...), 'attr')` when seeding the Alpine component).

2. [ ] Build `app/Views/api_history/_preview_modal.php` (shared) + wire into both index views — owner: `frontend-coder`
   - One Alpine modal fed by the preview JSON. Bind **all** fields via `x-text` (never `x-html`,
     never `innerHTML`): `id`, `env`, `service_name`, `service_id`, `command`, `domain`, `url`,
     `status`, `created_at`, and the `params` / `response` / `metadata` blobs inside
     `<pre class="whitespace-pre-wrap" x-text="...">`. `x-text` escapes by construction, so the
     stored JSON/text (user's own, already credential-stripped by `logApiCall`) can never execute.
   - Include the partial from both `client/api_history/index.php` and `admin/api_history/index.php`
     (`<?= $this->include('api_history/_preview_modal') ?>`).
   - Acceptance: a `params`/`response` value containing `<script>` or `<img onerror>` renders as
     literal text; no `x-html` anywhere in the history views.

3. [ ] Build `app/Views/admin/api_history/index.php` — owner: `frontend-coder`
   - Same as the client view plus a User column (name/email) and a user filter (email or id).
     Reuse the shared preview modal. Actions post to the `admin/api-history/*` endpoints.

4. [ ] Activate the sidebar links in `app/Views/layouts/app.php` — owner: `frontend-coder`
   - Replace the admin-zone `<span>API history</span>` "Coming soon" placeholder with
     `<a href="/admin/api-history" …>` and the client-zone one with `<a href="/api-history" …>`,
     matching the existing link styling. Do not touch the topbar, toast/confirm, or `postForm`
     script. This is the only edit to the shared layout.

5. [ ] Add routes — owner: `backend-coder` (Routes.php is config, not a View)
   - In the existing `authFilter` group: `GET api-history` → `Client\ApiHistory::index`;
     `GET api-history/preview` → `::preview`; `POST api-history/delete`, `api-history/delete-all`,
     `api-history/delete-by-age` (each `['filter' => 'csrf']`).
   - In the existing `admin` group (`authFilter` + `adminFilter`): `GET admin/api-history` →
     `Admin\ApiHistory::index`; `GET admin/api-history/preview` → `::preview`;
     `POST admin/api-history/delete`, `admin/api-history/delete-all` (each `['filter' => 'csrf']`).
   - Acceptance: `php spark routes` lists all endpoints under the correct filters; auto-routing
     stays off.

### Phase 3 — Tests
1. [ ] Extend `tests/database/ApiHistoryModelTest.php` — owner: `tester`
   - `searchForUser`/`countForUser` respect user scoping, filters, sort whitelist, pagination;
     `findOwned` returns null for a foreign/absent id; `deleteOwned`/`deleteAllFiltered`/
     `deleteByAge` soft-delete only the target user's rows and never return soft-deleted rows;
     admin methods see all users.
2. [ ] Add `tests/feature/Web/ClientApiHistoryTest.php` — owner: `tester`
   - Auth-gated; list renders; **IDOR:** preview/delete of another user's row → 404 with no
     existence leak; delete-all only affects the caller's rows within the current filters;
     delete-by-age validation; CSRF required on every POST.
3. [ ] Add `tests/feature/Web/AdminApiHistoryTest.php` — owner: `tester`
   - `adminFilter` gate (non-admin rejected); admin sees all users; user filter narrows results;
     admin delete / delete-all soft-delete.

## 6. Test plan
- **Unit:** `applyFilters` term-splitting and per-field predicates; sort-column whitelist fallback;
  `service_id` numeric guard; `status` `'0'`/`'1'` guard.
- **Integration (DB / model):** owner-scoped search/count/find/delete; chunked delete-all count;
  delete-by-age cutoff; soft-deleted rows excluded from every read; admin cross-user reads.
- **Feature (HTTP):**
  - **IDOR (explicit):** user A cannot preview or delete user B's row (404, indistinguishable from
    "not found"); user A's delete-all/delete-by-age never touch user B's rows.
  - **XSS-preview (explicit):** seed a row whose `params`/`response`/`domain` contains
    `<script>alert(1)</script>` and `"><img src=x onerror=alert(1)>`; assert the preview endpoint
    returns it as data and the rendered modal escapes it (no executable markup; bound via `x-text`).
  - CSRF enforced on all POSTs; `csrf_hash` present in every JSON envelope.
  - Filters, sort, pagination produce correct rows/counts.
- **Browser / E2E (manual after merge):** filter → sort → paginate → preview → single delete →
  filtered delete-all (confirm modal shows match count) → delete-by-age; verify sidebar links and
  admin user filter.
- **Manual smoke:** run `php spark migrate`; make a proxied CNR/JPRS call with logging enabled;
  confirm the row appears in `/api-history`, the preview renders params/response safely, deleting
  removes it from the list but leaves `deleted_at` set (not hard-purged).

## 7. Risks & open questions
- **Soft-delete semantics (decision):** delete, delete-all, and delete-by-age all **soft-delete**
  via the model (`deleted_at`), never hard-purge. The viewer shows only non-deleted rows. Document
  this in the view/PR so users understand rows are recoverable at the DB level and disk isn't
  reclaimed. A retention/hard-purge job is a separate later card.
- **Legacy bug fixed (decision):** legacy `deleteAll`/`deleteByAge` scoped by
  `session('userAuthenticated')->id` while everything else used `userWorking`. The rebuild uses
  `session('userWorking')->id` uniformly on the user side (and all-scoping on the admin side), so
  the inconsistency cannot recur.
- **Missing index (risk → mitigation):** `api_history` has no `user_id` index today (§4); without
  the new `(user_id, created_at)` composite, the default list does a full scan per user. Mitigation
  is Phase 1 step 1 — land the migration before shipping the UI.
- **Large `params`/`response` blobs (perf):** the keyword search does `LIKE '%term%'` across
  MEDIUMTEXT/LONGTEXT columns, which cannot use an index and gets slow on big tables. Acceptable at
  current scale (matches legacy). `ponytail:` leave as-is; note FULLTEXT as the upgrade path if
  history volume grows (mysql-patterns: FULLTEXT vs LIKE).
- **Open question:** should delete-by-age be user-only, or should admins get a global age-based
  purge too? Plan keeps it user-only (mirrors legacy); admins get single + filtered delete-all.
  Confirm if a global admin purge is wanted — if so it's a small add on the same model method.

## 8. Rollout
- **Migration order:** run `php spark migrate` to apply
  `2026-07-13-130000_AddUserCreatedIndexToApiHistory` before/with deploy. On a large existing
  table the `ALTER TABLE ... ADD INDEX` can be slow/locking — run in a maintenance window or via
  online DDL (mysql-patterns: big-table ALTERs).
- **No feature flag / no env vars** — the feature is gated by the existing `authFilter` /
  `adminFilter`; sidebar links appear once the routes and views ship.
- **Deploy notes:** no config changes beyond routes; verify `php spark routes` post-deploy and that
  the CDN-based `layouts/app` shell still loads (no asset-build dependency introduced).
- **Rollback:** migration `down()` drops the index; removing the routes hides the feature. No data
  migration to reverse (deletes are soft only).
