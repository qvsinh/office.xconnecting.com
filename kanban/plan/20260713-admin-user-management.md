# Plan: Admin User Management (list / create / edit / detail + act-as impersonation)

**Task:** 20260713
**Created:** 2026-07-13
**Branch suggestion:** `feature/admin-user-management`

## 1. Goal
Give admins a UI to list/search/paginate users, create and edit user accounts, view a user's
detail (with a per-user admin note), and **act-as** (impersonate) a user to see the app through
their data scope — reusing the existing `UserModel`, layout, and shared components, and linking to
the already-built API-keys admin UI rather than rebuilding key management.

## 2. Scope
- **In scope:**
  - Admin area under the existing `admin` route group (`authFilter` + `adminFilter`):
    - `GET /admin/users` — list with keyword search + status/zone filter + pagination.
    - `POST /admin/users/create` — create user (Alpine modal on the list page).
    - `POST /admin/users/update` — edit user (same modal, prefilled).
    - `GET /admin/users/(:num)` — user detail page (profile + admin note editor + a link to the
      existing admin API-keys UI filtered to this user + act-as button).
    - `POST /admin/users/note` — update the per-user admin note.
    - `POST /admin/users/act-as` — start impersonation (set `userWorking`, keep `userAuthenticated`).
    - `POST /admin/users/stop-acting` — end impersonation (restore `userWorking = userAuthenticated`).
  - `Admin\Users` controller (extends `BaseController`, uses the `jsonSuccess`/`jsonFailed` envelope).
  - `UserModel` additive helpers: `searchAdmin`/`countAdmin`, `countActiveAdmins`; add `note` to
    `allowedFields`. No changes to existing methods (`findActiveByEmail`, `stampLogin`).
  - Views: list (`admin/users/index.php`) with create/edit modal; detail (`admin/users/detail.php`).
    Extend `layouts/app`; reuse shared toast / confirm-modal / alert Alpine components + `postForm()`.
  - Activate the sidebar **Users** placeholder (currently an inert `<span>` at
    `layouts/app.php:62`) → `<a href="/admin/users">`. Add an **"Acting as …"** banner to the shared
    layout when `userWorking->id !== userAuthenticated->id`, with a "Return to admin" control.
  - Routes + tests (CRUD, validation incl. unique-email + self/last-admin lockout guards,
    password-hash-on-create, act-as security, XSS, admin gating).
- **Out of scope (later cards — enumerate only, do not build):**
  - **Sub-user / delegated-access self-service** (a user inviting/managing their *own* sub-users —
    distinct from admin act-as; act-as here is admin→any-user only).
  - User **delete/soft-delete** UI (deactivate via `status` covers disabling this card).
  - Rebuilding **API key management** for a user (already DONE — link to `/admin/api-access-keys`).
  - Notifications, 2FA management, currency/commission/billing profile editing.
  - System modules (servers, tasks, translations, email templates, settings, system logs), PSI
    Billing console, Tailwind build pipeline, a `translate()` i18n helper (use plain English strings).

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Admin/Users.php` (new) | list/create/update/view/note/actAs/stopActing |
| Backend | `app/Models/UserModel.php` (modify) | add `searchAdmin`, `countAdmin`, `countActiveAdmins`; add `note` to `allowedFields` |
| Backend | `app/Database/Migrations/*_AddUserListIndexToUsers.php` (new, optional) | index for list sort/filter — add only if `EXPLAIN` shows a scan on the real table |
| Frontend (Views) | `app/Views/admin/users/index.php` (new) | list + search + pager + create/edit modal |
| Frontend (Views) | `app/Views/admin/users/detail.php` (new) | detail + note editor + link to API-keys + act-as |
| Frontend (Views) | `app/Views/layouts/app.php` (modify) | activate Users nav link; add "Acting as …" banner |
| Config / Routes | `app/Config/Routes.php` (modify) | add routes inside the existing `admin` group |
| Tests | `tests/database/UserModelTest.php` (modify/new) | `searchAdmin`/`countAdmin`/`countActiveAdmins` |
| Tests | `tests/feature/Web/AdminUsersTest.php` (new) | CRUD + validation + gating + XSS |
| Tests | `tests/feature/Web/AdminActAsTest.php` (new) | impersonation security (dedicated) |

## 4. Impact analysis
CodeGraph index for this repo is still sparse; blast radius assessed by direct reads of callers.
No existing symbol is renamed or has its signature changed — only additions.

- **`UserModel`** — existing callers are `Auth::login()` (`findActiveByEmail`, `stampLogin`) and
  their tests. This card only **adds** methods and **adds `note`** to `allowedFields`; existing
  method signatures and the login path are untouched → blast radius on auth is **zero**. Adding
  `note` to `allowedFields` widens what `insert`/`update` may write, but the create/update controllers
  build explicit arrays, so no unexpected field is mass-assigned.
- **`session('userWorking')` vs `session('userAuthenticated')` (critical)** — read by
  `auth_helper.php` (`is_admin`/`is_client` read **`userAuthenticated`**) and by every client-scoped
  controller (`Client\ApiAccessKeys`, `Client\ApiHistory`, `Client\Console*`, `Client\Dashboard`)
  which read **`userWorking`** for data scope. Act-as mutates **only `userWorking`**, so
  authorization (admin gate) stays anchored to the real admin while the client-scoped views render
  the target user's data. This is the whole point of the two-key split — do not touch
  `userAuthenticated` in act-as. Blast radius is intentional and confined to what `userWorking` feeds.
- **`layouts/app.php`** — shared shell; edits are (a) swap one inert `<span>` for an `<a>`, and
  (b) add a conditional banner block. Low risk, cosmetic + one session comparison.
- **Routes** — additions inside the already-filtered `admin` group; `api/*` and client groups
  untouched.

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Extend `UserModel` — owner: `backend-coder`
   - Add `'note'` to `$allowedFields` (column exists; needed by note update + edit form).
   - `searchAdmin(array $filters, int $limit, int $offset): array` and
     `countAdmin(array $filters): int` — Query Builder only (no raw SQL), a **separate count query**
     (no `SQL_CALC_FOUND_ROWS`, per mysql-patterns). Filters: `keyword` (exact `email` when it is a
     valid email, else `name LIKE %kw%`), `status` (0/1 when provided), `zone` (`global`/`client`
     when provided). `ORDER BY login_at DESC` (matches legacy default sort). Both share one private
     builder method so the count and the page cannot drift.
   - `countActiveAdmins(): int` — `where('zone','global')->where('status',1)` count, for the
     last-admin lockout guard. (Soft-deleted rows already excluded by `useSoftDeletes`.)
   - Acceptance: unit tests prove keyword/status/zone filtering, that `countAdmin` equals the
     unpaginated row count for the same filters, and `countActiveAdmins` counts only active `global`
     users.
2. [ ] Create `Admin\Users` controller — owner: `backend-coder`
   - `index()`: render `admin/users/index` with `searchAdmin`/`countAdmin`, CI4 `Pager`
     (`app.rpp_default` default 25; `page`/`keyword`/`limit`/`status`/`zone` GET). Pass the raw user
     rows for the edit modal (view escapes on output).
   - `create()` (POST): validate `name` required; `email` required|valid_email|max_length[255]|
     `is_unique[users.email]`; `zone` required|in_list[global,client]; `env` required|
     in_list[LIVE,TESTING]; `status` in_list[0,1]; `timezone` required; `language` permit_empty;
     `password` permit_empty|min_length[5]. Hash password with
     `hash($authConfig->password_algorithm, $password.$authConfig->password_salt)` (md5, legacy-
     compatible — same as `Auth::login`/`user:password`). **If password is blank, generate a random
     one** (`generate_access_token(12)`) and hash that; never echo/log the password or the hash.
     Insert via Model. Return the success envelope.
   - `update()` (POST): validate `id` exists; `email` unique-except-self
     (`is_unique[users.email,id,{id}]`); same field rules as create; **password optional — only set
     when non-blank, otherwise leave unchanged**. Enforce the two lockout guards below **before**
     writing:
     - **Self-lockout guard:** if `id === session('userAuthenticated')->id` and the change would
       demote (`zone !== 'global'`) or deactivate (`status === 0`) the acting admin → reject with a
       clear message.
     - **Last-admin guard:** if the target is currently an active admin and the update would demote
       or deactivate them while `countActiveAdmins() <= 1` → reject.
     Return the success/failure envelope.
   - `view(int $id)`: render `admin/users/detail` (404 via `throw PageNotFoundException` when
     missing). Provide the user, the note, and (for the link) the user id so the view can deep-link
     to `/admin/api-access-keys?keyword=<email>`.
   - `updateNote()` (POST): validate `id` exists; update only the `note` field; success envelope.
   - `actAs()` (POST) — **security-sensitive**: validate `user_id` exists. Load the target user
     object. Set `session('userWorking')` = target; **do not touch `session('userAuthenticated')`**.
     `log_message('notice', ...)` an audit line (`admin {authId} acting as user {targetId}`) — no
     secrets. Redirect to `/dashboard` (client-scoped view of the target). No-op + message if
     `user_id === userAuthenticated->id`.
   - `stopActing()` (POST): set `session('userWorking')` = `session('userAuthenticated')`; redirect
     to `/admin/users`. Safe to call when not impersonating (idempotent).
   - All POSTs behind CSRF at the route; all input validated at the boundary; controller in the
     `Admin` namespace gated by `adminFilter`. No raw SQL anywhere.
   - Acceptance: feature tests cover each action happy-path; a `zone=client` session is rejected by
     `adminFilter` on every route.
3. [ ] Register routes inside the existing `admin` group — owner: `backend-coder`
   ```php
   $routes->get('users', 'Admin\Users::index');
   $routes->post('users/create', 'Admin\Users::create', ['filter' => 'csrf']);
   $routes->post('users/update', 'Admin\Users::update', ['filter' => 'csrf']);
   $routes->get('users/(:num)', 'Admin\Users::view/$1');
   $routes->post('users/note', 'Admin\Users::updateNote', ['filter' => 'csrf']);
   $routes->post('users/act-as', 'Admin\Users::actAs', ['filter' => 'csrf']);
   $routes->post('users/stop-acting', 'Admin\Users::stopActing', ['filter' => 'csrf']);
   ```
   - Note ordering: place `users/(:num)` **after** the literal `users/...` POST routes so it does not
     shadow them. Acceptance: `php spark routes` lists all under `authFilter`+`adminFilter`; no
     auto-routing.
4. [ ] (Optional) Index migration — owner: `backend-coder`
   - Only if `EXPLAIN` on the real `users` table shows a filesort/full scan for the list: add a
     composite index supporting `ORDER BY login_at DESC` + the `zone`/`status` filters. Skip if the
     table is small / already indexed. `ponytail:` do not add speculatively.

### Phase 2 — Frontend (Views)
1. [ ] Admin list view `app/Views/admin/users/index.php` — owner: `frontend-coder`
   - Extend `layouts/app`. TailAdmin/Tailwind table: columns id, name, email, zone, env, status
     (active/inactive badge), last login (`login_at`), actions (View / Edit / Act-as). Keyword search
     + status + zone filter form (GET) + pager. Alpine create/edit modal: name, email, password
     (blank = keep/auto-generate, with a helper note), zone `<select>` (global/client), env
     `<select>` (LIVE/TESTING), status toggle, timezone, language.
   - AJAX via `fetch()` with `window.csrf`; success → toast + refresh; refresh the CSRF hash from the
     JSON response between POSTs (established pattern). Act-as goes through the shared **confirm
     modal** then `postForm('/admin/users/act-as', {user_id})`; never native `confirm()`.
   - `esc()` every dynamic value (name/email/note/zone/env), including inside `x-text`/attributes.
   - UI notes: load `minimalist-ui` (fits TailAdmin) before building; reuse the existing api-keys /
     dashboard card + table styling tokens for consistency.
   - Acceptance: renders for an admin session; create/edit/act-as work end-to-end via fetch with
     toasts; no jQuery/Bootstrap; all output escaped.
2. [ ] Admin detail view `app/Views/admin/users/detail.php` — owner: `frontend-coder`
   - Extend `layouts/app`. Read-only profile card (all fields `esc()`d), an inline **admin note**
     editor (textarea → `fetch()` `POST /admin/users/note` → toast), an **"Act as this user"** button
     (confirm modal → `postForm`), and a **"Manage API access keys"** link to
     `/admin/api-access-keys?keyword=<esc(email)>` (link only — do NOT rebuild key management here).
   - Acceptance: renders for an admin session; note saves via fetch; links resolve.
3. [ ] Shared layout edits `app/Views/layouts/app.php` — owner: `frontend-coder`
   - Replace the inert **Users** `<span>` (line ~62) with `<a href="/admin/users" …>` styled like the
     other admin nav links (remove it from the "Coming soon" group).
   - Add an **"Acting as {name}"** banner (shown when
     `session('userWorking')->id !== session('userAuthenticated')->id`) with a "Return to admin"
     button → `postForm('/admin/users/stop-acting')`. `esc()` the impersonated user's name. Banner is
     visible across both nav zones so an admin can always exit impersonation.
   - Acceptance: Users link navigates; banner appears only while impersonating and restores on return.

### Phase 3 — Tests
1. [ ] Unit: `UserModelTest` — `searchAdmin`/`countAdmin` filtering + count parity; `countActiveAdmins`. Owner: `tester`
2. [ ] Feature: `AdminUsersTest` — CRUD, validation, gating, XSS. Owner: `tester`
3. [ ] Feature: `AdminActAsTest` — impersonation security (see §6). Owner: `tester`

## 6. Test plan
- **Unit:**
  - `searchAdmin` filters by keyword (email exact vs name LIKE), status, zone; `countAdmin` equals
    the unpaginated count for identical filters; ordering is `login_at DESC`.
  - `countActiveAdmins` counts only `zone=global` + `status=1`, excludes client/inactive/soft-deleted.
- **Integration / HTTP (feature — `AdminUsersTest`):**
  - Create: valid payload creates the user; **password is stored as
    `md5(password.'')`** (assert the stored hash equals `hash('md5', $plain)`), and a blank password
    still produces a valid non-empty hash (auto-generated); response never contains the password/hash.
  - Update: edits persist; blank password leaves the existing hash unchanged; a non-blank password
    re-hashes.
  - Validation: missing/invalid email → 400; **duplicate email on create → rejected**; **edit keeps
    own email (unique-except-self) → allowed**; invalid `zone`/`env` → 400.
  - **Self-lockout guard:** the acting admin cannot demote themselves to `client` or set their own
    `status=0` (assert rejected + row unchanged).
  - **Last-admin lockout guard:** with exactly one active admin, demoting/deactivating that admin is
    rejected (assert rejected + still an active admin); with ≥2 active admins the same edit succeeds.
  - **Admin gate:** a `zone=client` session hitting any `/admin/users*` route is rejected by
    `adminFilter`.
  - **XSS:** a user created with `name` = `<script>alert(1)</script>` renders escaped in list +
    detail (assert the raw tag is not present).
- **Act-as security (dedicated — `AdminActAsTest`, required gates for sign-off):**
  - `act-as` sets `session('userWorking')` to the target **and leaves
    `session('userAuthenticated')` = the admin** (assert both).
  - After `act-as`, `is_admin()` still returns true (authorization unaffected) — **no privilege
    change to the impersonated identity**; a client-scoped read (e.g. `Client\ApiAccessKeys` /
    `Client\Dashboard`) reflects the target user's data scope.
  - `stop-acting` restores `userWorking = userAuthenticated` (assert equal again).
  - **Non-admin cannot impersonate:** a `zone=client` session POSTing `act-as` is rejected by
    `adminFilter` (assert `userWorking` unchanged).
  - CSRF is enforced on `act-as`/`stop-acting` (POST-only; a GET is not routed).
- **Browser / E2E (manual smoke):**
  - Search users, create one, edit it, open detail, save a note, click "Manage API access keys"
    (lands on the filtered admin keys page), act-as → banner shows on client dashboard → return to
    admin.
- **Manual smoke (post-merge):** `composer cs`, `composer analyse` (phpstan lvl 5),
  `vendor/bin/phpunit` (expect current ~207 + new tests green), `php spark routes`.

## 7. Risks & decisions
- **Act-as impersonation (highest risk).** Decision: **include it in this card** — it is core to
  user management. It must set **only `userWorking`** and keep `userAuthenticated` = the real admin;
  overwriting `userAuthenticated` would be privilege escalation/confusion. A visible "Acting as …"
  banner + `stop-acting` are mandatory so the admin can always exit. `act-as`/`stop-acting` are
  **POST + CSRF** (not legacy's GET link) because they change session state. An audit `log_message`
  line records who impersonated whom. Dedicated security tests in §6 are review gates.
- **Last-admin / self lockout.** Guards live in the controller (not just validation) and are covered
  by tests. Without them an admin could demote/deactivate the last admin and lock everyone out.
- **md5 password hashing (deliberate, not a bug).** Reuse `Config\Auth` (md5, empty salt) exactly as
  `Auth::login` and the `user:password` command do, so hashes stay login-compatible. Do **not**
  "upgrade" hashing here — that is a separate rehash-migration card. Never echo/log the plaintext or
  hash.
- **`is_unique` vs soft-deletes.** CI4 `is_unique[users.email]` checks all rows including
  soft-deleted; a previously soft-deleted email would block reuse. Acceptable for now (no delete UI
  in scope); note for the future delete card.
- **Act-as UX while admin nav shows.** Because `is_admin()` stays true during impersonation, the
  admin sidebar remains; the target's data appears on client-scoped pages via `userWorking`. This
  matches the legacy two-key design and is intended — the banner makes the state obvious.
- **No `translate()` in v2:** use plain English strings; wrap for i18n in the later translations card.

## 8. Rollout
- No required schema change (all columns exist); add the optional list index migration only if
  `EXPLAIN` justifies it. No new env vars, no feature flag.
- Deploy order: backend (model → controller → routes) → frontend (views → layout nav + banner).
- Post-deploy: `php spark routes`, run the full suite, smoke-test create → edit → detail/note →
  act-as → return-to-admin, and confirm a non-admin cannot reach `/admin/users*`.
