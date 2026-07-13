# Plan: API Access Keys management UI (admin + user self-service)

**Task:** 20260713
**Created:** 2026-07-13
**Branch suggestion:** `feature/api-keys-management-ui`

## 1. Goal
Let admins manage every `users_api_access_keys` row and let users self-manage their own keys, so
both can create/enable/disable/log-toggle/delete the bearer tokens that authenticate the existing
`/api/cnr` + `/api/jprs` proxy — without breaking the `ApiFilter` token contract.

## 2. Scope
- **In scope:**
  - Admin area `/admin/api-access-keys` (behind `authFilter` + `adminFilter`): list (keyword search
    + pagination), create (pick user + service; env derived from that user's record), edit
    (service/description/`is_enable`/`enable_log`), toggle enable, toggle log, delete. All via
    `fetch()` + JSON.
  - User self-service `/my-api-access-keys` (behind `authFilter`): list own keys, create (pick
    service; env from own user record), toggle enable, toggle log, delete own — every read and
    mutation scoped to `session('userWorking')->id`.
  - Backend controllers `Admin\ApiAccessKeys` + `Client\ApiAccessKeys`.
  - **One** centralized key-generation method on `ApiAccessKeyModel` reused by both create paths.
  - A `list_api_services()` helper (extend `service_config_helper`) for the service dropdown.
  - Views extend `layouts/app`; reuse the shared toast / confirm-modal / alert Alpine components.
  - Enable the two "Coming soon" sidebar nav placeholders in `layouts/app.php`.
  - Routes + tests (unit, feature/HTTP, explicit IDOR/ownership).
- **Out of scope (backlog, do not plan here):** API history viewer UI; CNR/JPRS developer consoles
  (`ConsoleCnr`/`ConsoleJprs`); admin user-management CRUD; sub-user delegation; 2FA; notifications;
  a `translate()` i18n helper (v2 has none yet — use plain English strings this card).

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Admin/ApiAccessKeys.php` (new) | admin CRUD over all keys |
| Backend | `app/Controllers/Client/ApiAccessKeys.php` (new) | self-service, session-scoped |
| Backend | `app/Models/ApiAccessKeyModel.php` (modify) | add `createForUser()`, list/search + count helpers |
| Backend | `app/Helpers/service_config_helper.php` (modify) | add `list_api_services()` |
| Frontend (Views) | `app/Views/admin/api_access_keys/index.php` (new) | admin list + create/edit modal |
| Frontend (Views) | `app/Views/client/api_access_keys/index.php` (new) | self-service list + create |
| Frontend (Views) | `app/Views/layouts/app.php` (modify) | activate the two sidebar links (both zones) |
| Config / Routes | `app/Config/Routes.php` (modify) | admin group + `authFilter` group additions |
| Tests | `tests/database/ApiAccessKeyModelTest.php` (modify) | `createForUser()` + token/limit unit tests |
| Tests | `tests/feature/Web/AdminApiKeysTest.php` (new) | admin CRUD + admin-gate reject |
| Tests | `tests/feature/Web/ClientApiKeysTest.php` (new) | self-service + IDOR/ownership |
| Tests | `tests/unit/ServiceListHelperTest.php` (new) | `list_api_services()` parsing |

## 4. Impact analysis
CodeGraph index for this repo is still sparse; blast radius assessed via direct reads of every
caller. No renames of existing symbols are proposed — only additions.

- **`ApiAccessKeyModel`** — existing callers: `ApiFilter::before()` (`findByAccessKey`,
  `touchLastAccess`) and its tests. We only **add** methods (`createForUser`, a paginated/search
  list builder, per-user list, per-user count) and touch no existing method signature → blast
  radius on the auth path is **zero**. Adding methods keeps `allowedFields` unchanged.
- **Token generation contract (critical, shared with `ApiFilter`)** — the generated `access_key`
  MUST decode (via `get_hashids(32)`, salt `hashid_salt=proxy@2026`) to
  `[user_id, env_numeric(0=TESTING/1=LIVE), service_id, time(), mt_rand(1,1000)]`; `ApiFilter`
  reads exactly `decoded[0..2]` and cross-checks the stored row's `user_id`/`service_id`/`is_enable`
  and the URL env segment. Centralizing generation in `ApiAccessKeyModel::createForUser()` means
  both create paths mint identical, filter-compatible tokens — **change once, cannot drift**.
  `user_status` must be stored = `env_numeric` (as legacy does) for parity.
- **`service_config_helper.php`** — only an additive function; existing `get_*_service_config()`
  used by the API proxy controllers are untouched → zero blast radius.
- **`layouts/app.php`** — shared shell; the edit only swaps two inert `<span>` placeholders for
  `<a>` links per nav zone. Low risk, cosmetic.

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Extend `service_config_helper.php` with `list_api_services(): array` — owner: `backend-coder`
   - Scan loaded env for keys matching `^(cnr|jprs)\.(\d+)\.service_api_id$` (iterate `$_ENV` /
     `$_SERVER`; CI4 loads `.env` into both). Return a sorted list of
     `['id' => int, 'provider' => 'cnr'|'jprs', 'label' => "CNR #800 — psi-japan"]`
     (label = provider upper + `#id` + optional `.name` for CNR). Dedupe by id.
   - Acceptance: with the current `.env`, returns ids `800` (cnr) and `801/802/803` (jprs); returns
     `[]` when none configured; no secrets in the label/output.
2. [ ] Add `ApiAccessKeyModel::createForUser(int $userId, string $userEnv, int $serviceId): object|false` — owner: `backend-coder`
   - Enforce the **20-keys-per-user** limit (reject when existing count `>= 20` — fixes the legacy
     off-by-one `> 20`). Compute `env_numeric = $userEnv === 'LIVE' ? 1 : 0`. Mint token via
     `get_hashids(32)->encode([$userId, $env_numeric, $serviceId, time(), mt_rand(1,1000)])`. Insert
     `user_id, access_key, user_status=env_numeric, service_id` through the Model (Query Builder, no
     raw SQL). Return the created row (with `access_key`) or `false` when over the limit.
   - Also add: `countForUser(int $userId): int`; `listForUser(int $userId): array`;
     `searchAdmin(array $filters, int $limit, int $offset): array` + `countAdmin(array $filters): int`
     — a Query Builder join to `users` (`user_name`, `user_email`, `user_env`), `ORDER BY id DESC`,
     keyword branch matching legacy (email exact / numeric id-or-user-or-service / name LIKE). No
     `SQL_CALC_FOUND_ROWS` — separate count query per **mysql-patterns**. Ensure a composite index
     on `users_api_access_keys(user_id)` exists for the per-user path (add a migration only if
     absent; table has no `deleted_at`, so no soft-delete filter).
   - Acceptance: unit test proves the minted token decodes to `[userId, env_numeric, serviceId, …]`
     and that `ApiFilter` accepts a key created this way; `createForUser` returns `false` at the
     20th existing key.
3. [ ] Create `Admin\ApiAccessKeys` controller — owner: `backend-coder`
   - `index()` renders the list view with `searchAdmin`/`countAdmin` results, CI4 `Pager`
     (`app.rpp_default=25` default, `page`/`keyword`/`limit` GET), and a users list
     (`id, name, email`) for the modal.
   - `create()` (POST): validate `user_id` (exists) + `service_id` (numeric, in `list_api_services()`
     ids). Load target user, call `createForUser($user->id, $user->env, $serviceId)`; JSON result;
     surface the 20-limit rejection.
   - `update()` (POST): validate `id` exists, `user_id` exists, `service_id` valid; update
     `user_id, service_id, is_enable, enable_log, description` via Model. (Note: changing `user_id`
     does NOT re-mint the token; document that the token still decodes to the original user — allowed
     for admin, matches legacy. `ApiFilter` cross-checks the stored `user_id`, so a mismatched
     re-assignment would fail auth. **Open question in §7.**)
   - `toggle()` / `toggleLog()` (POST): validate `id` + 0/1 value; update single field.
   - `delete()` (POST): validate `id`; hard delete.
   - All mutations return the API envelope (`status`/`message`); all POSTs behind CSRF; validate at
     the controller boundary with CI4 `Validation`. Controller in the `Admin` namespace, gated by
     `adminFilter` at the route.
   - Acceptance: feature tests cover each action happy-path + a non-admin (`zone=client`) request is
     rejected by `adminFilter`.
4. [ ] Create `Client\ApiAccessKeys` controller (self-service) — owner: `backend-coder`
   - Derive owner strictly from `session('userWorking')` — **never** read `user_id` from input.
   - `index()`: `listForUser($me->id)`.
   - `create()` (POST): validate `service_id` (numeric + in allowed ids); call
     `createForUser($me->id, $me->env, $serviceId)`; enforce 20-limit via the model.
   - `toggle()` / `toggleLog()` / `delete()` (POST): fetch the row by `id` **AND** `user_id=$me->id`;
     if it does not belong to the session user → 404/`failed` (no leak of existence). Then mutate.
   - Acceptance: IDOR tests (see §6) prove a user cannot read/toggle/log/delete another user's key.
5. [ ] Register routes — owner: `backend-coder`
   - Admin: inside the existing `admin` group (`authFilter`+`adminFilter`): `GET api-access-keys`,
     `POST api-access-keys/create|update|toggle|toggle-log|delete` with `['filter' => 'csrf']`.
   - Client: inside the existing `authFilter` group: `GET my-api-access-keys`,
     `POST my-api-access-keys/create|toggle|toggle-log|remove` with `['filter' => 'csrf']`.
   - Acceptance: `php spark routes` lists all endpoints under the correct filters; no auto-routing.

### Phase 2 — Frontend (Views)
1. [ ] Admin list view `app/Views/admin/api_access_keys/index.php` — owner: `frontend-coder`
   - Extend `layouts/app`. TailAdmin/Tailwind table: columns id, user (name/email), service (label
     from `list_api_services()`), env, key (masked), enabled, log, last_access, actions. Keyword
     search form (GET) + pager. Alpine-driven create/edit modal (user `<select>`, service `<select>`,
     description, `is_enable`/`enable_log` checkboxes on edit).
   - Key display: masked by default with a reveal toggle + copy-to-clipboard button; `esc()` every
     dynamic value including inside `x-text`/attributes.
   - AJAX via `fetch()` using `window.csrf`; on success show toast and refresh the row/list; deletes
     go through the shared confirm-modal (`$dispatch('confirm', {...onConfirm})`), never native
     `confirm()`. Refresh the CSRF hash from the JSON response between POSTs (established pattern).
   - UI notes: load the `minimalist-ui` (fits TailAdmin) skill before building; reuse dashboard card
     styling tokens already in the codebase.
   - Acceptance: page renders for an admin session; create/edit/toggle/delete work end-to-end via
     fetch with toasts; no jQuery/Bootstrap; all output escaped.
2. [ ] Client self-service view `app/Views/client/api_access_keys/index.php` — owner: `frontend-coder`
   - Extend `layouts/app`. Simpler table (no user column — always the session user; show env as the
     account env). Create control = service `<select>` only. Masked key + reveal + copy. Toggle
     enable, toggle log, delete-own via `fetch()` + shared toast/confirm. Show remaining-key count
     and disable the create control at 20.
   - Acceptance: renders for a client session; all actions scoped to own keys; escaped output.
3. [ ] Activate sidebar nav in `layouts/app.php` — owner: `frontend-coder`
   - Replace the "API access keys" `<span>` placeholder with `<a href="/admin/api-access-keys">`
     (admin zone) and `<a href="/my-api-access-keys">` (client zone). Leave other "Coming soon"
     items untouched.
   - Acceptance: sidebar links navigate correctly per zone.

### Phase 3 — Tests
1. [ ] Unit: `ApiAccessKeyModelTest` additions + `ServiceListHelperTest` — owner: `tester`
2. [ ] Feature/HTTP: `AdminApiKeysTest` + `ClientApiKeysTest` (incl. IDOR) — owner: `tester`

## 6. Test plan
- **Unit:**
  - `list_api_services()` returns the configured ids with correct provider/label; empty when none;
    labels contain no secrets.
  - `createForUser()`: minted `access_key` decodes to `[userId, env_numeric, serviceId, …]`;
    `user_status` stored = env_numeric; returns `false` at the 20-key ceiling (boundary: 19→ok,
    20→reject); LIVE→1 / TESTING→0.
- **Integration / HTTP (feature):**
  - Admin: create/update/toggle/toggle-log/delete each return the success envelope and mutate the
    row; keyword search (email / numeric / name) filters correctly; pagination works; invalid
    `service_id` / missing `user_id` → 400.
  - **Admin gate:** a `zone=client` session POSTing to `/admin/api-access-keys/*` is rejected by
    `adminFilter`.
  - Self-service: user creates a key (env taken from own record), lists only own keys, toggles/logs/
    deletes own; 20-limit enforced.
  - **Cross-contract:** a key created via the UI (both paths) authenticates a real `/api/cnr/testing`
    or `/api/jprs/testing` call through `ApiFilter` (reuse existing API test helpers).
- **IDOR / ownership (explicit, required):**
  - User A cannot see User B's keys in `listForUser`/index.
  - User A POSTing B's key `id` to client `toggle`/`toggle-log`/`remove` → 404/`failed`, and B's row
    is unchanged (assert DB state).
  - Client `create` ignores any injected `user_id` in the POST body — the key is always owned by the
    session user (assert stored `user_id`).
- **Browser / E2E (manual smoke):**
  - Admin: search a user, create a key, reveal + copy, edit description, disable, delete (confirm
    modal), toast on each.
  - Client: create a key, copy it, curl `/api/cnr/testing/...` with `Authorization: Bearer <key>`
    and get a proxied response; disable the key and confirm the call now 401s.
- **Manual smoke (post-merge):** run `composer cs`, `composer analyse`, `vendor/bin/phpunit`
  (expect 119 + new tests green), `php spark routes`.

## 7. Risks & open questions
- **Ownership/IDOR (highest risk):** mitigated by deriving the owner from `session('userWorking')`
  and filtering every client read/mutation by `user_id=$me->id` (fetch-by-id-AND-owner before any
  write). Dedicated IDOR tests are mandatory gates for review sign-off.
- **Token-contract compatibility:** mitigated by a single `createForUser()` used by both paths and a
  unit test asserting the decoded shape + a feature test that authenticates the minted key through
  `ApiFilter`. Do not alter the encode order.
- **Secret hygiene:** `access_key` is a live bearer credential — mask by default in the UI, never log
  it, `esc()` everywhere. CSRF on all POSTs with the hash-refresh pattern.
- **Open question — admin re-assigning `user_id` on update:** legacy allows editing `user_id`, but
  the token still decodes to the ORIGINAL user, and `ApiFilter` cross-checks the stored `user_id`
  against `decoded[0]`, so a re-assigned key would fail auth. Recommend the rebuild **omit `user_id`
  from the admin update form** (edit only service/description/is_enable/enable_log) to avoid minting
  a silently-broken key; re-assignment, if ever needed, should delete + recreate. Confirm before
  building the edit modal.
- **No `translate()` in v2:** use plain English strings now; wrap for i18n in the later translations
  card.
- **Service dropdown source:** ids come from `.env`; if an env service is later removed, existing
  keys referencing it still list (label falls back to `#id`). Acceptable.

## 8. Rollout
- No schema change expected (table exists; add a `user_id` index migration only if not already
  present). No new env vars, no feature flag.
- Deploy order: backend (model/helper/controllers/routes) → frontend views → enable sidebar links.
- Post-deploy: verify `php spark routes`, run the full test suite, smoke-test one admin + one client
  key create and a live `/api/*` call with a UI-minted key.
