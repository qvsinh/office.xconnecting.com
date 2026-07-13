# Plan: Web authentication, login UI, and authenticated app shell

**Task:** 20260713
**Created:** 2026-07-13
**Branch suggestion:** `feature/web-auth-and-shell`

## 1. Goal
Let a real user/admin log in through the web and land on a dashboard, matching the
legacy `proxy.services.vn` login behaviour (md5 password check, `userAuthenticated` +
`userWorking` session, zone-based admin/client routing). This card delivers only
password auth plus the shared authenticated shell (layout + toast/confirm/alert
components) that every future feature page will extend.

## 2. Scope
- **In scope:**
  - `Config/Auth.php` port (md5, empty salt) and a `UserModel` over the existing `users` table.
  - Idempotent `users` migration (guarded by `tableExists`) matching the existing schema for greenfield DBs.
  - `Auth` controller: `showLogin` (GET), `login` (POST, JSON for `fetch()`), `logout`.
  - Filters: `authFilter`, `adminFilter`, `guestFilter` + aliases + route groups; auto-routing stays off.
  - `auth_helper` (`is_authenticated`, `is_admin`, `is_client`).
  - Login page + shared authenticated base layout (sidebar/topbar/user menu/logout) + shared Alpine
    toast / confirm-modal / alert components defined once in the layout.
  - Admin dashboard shell and client dashboard shell (placeholder cards only).
  - `php spark user:password <email> <password>` CLI command + a dev seeder that ensures a known dev admin exists.
  - Tests for login success/failure, disabled account, admin-route gating, guest redirect, logout.
- **Out of scope (later cards — see §7 backlog):**
  - 2FA (google2fa / email_otp) — **login is password-only this card**; 2FA-enabled accounts log in with password
    alone until the 2FA card lands (security follow-up, flagged in §7).
  - Remember-me cookie.
  - Sub-user delegation (`userWorking` ≠ `userAuthenticated`).
  - Password-reset / signup / email verification.
  - Captcha / login-fail lockout.
  - Transparent md5→bcrypt rehash (optional future card).
  - All feature pages: API-keys UI, API history UI, CNR/JPRS consoles, user management, servers,
    scheduled tasks, translations, email templates, settings, system logs, PSI Billing console.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Config/Auth.php` (new) | md5 + empty salt, ported verbatim |
| Backend | `app/Models/UserModel.php` (new) | `returnType='object'`, soft-deletes on, `allowedFields` incl. `login_at` |
| Backend | `app/Controllers/Auth.php` (new) | extends `BaseController`; `showLogin`/`login`/`logout` |
| Backend | `app/Filters/AuthFilter.php`, `AdminFilter.php`, `GuestFilter.php` (new) | require/redirect logic |
| Backend | `app/Helpers/auth_helper.php` (new) | `is_authenticated`/`is_admin`/`is_client` |
| Backend | `app/Commands/UserPassword.php` (new) | `user:password <email> <password>` |
| Backend | `app/Controllers/Admin/Dashboard.php`, `app/Controllers/Client/Dashboard.php` (new) | render shell views only |
| Frontend (Views) | `app/Views/auth/login.php` | TailAdmin login, `fetch()` POST |
| Frontend (Views) | `app/Views/layouts/app.php` | shared authenticated layout + shared Alpine toast/confirm/alert |
| Frontend (Views) | `app/Views/admin/dashboard.php`, `app/Views/client/dashboard.php` | placeholder cards |
| Database | `app/Database/Migrations/2026-07-13-000000_CreateUsers.php` | idempotent guard on existing table |
| Database | `app/Database/Seeds/DevAdminSeeder.php` | ensures known dev admin, never overwrites real accounts |
| Config / Routes | `app/Config/Routes.php`, `app/Config/Filters.php`, `app/Config/Security.php` | route groups, filter aliases, CSRF for web |
| Tests | `tests/session/Auth/*`, `tests/session/Filters/*`, `tests/unit/UserModelTest.php` | feature + unit |

## 4. Impact analysis
Greenfield card — CodeGraph index for this repo has no web-auth symbols yet, so blast radius is
additive rather than a refactor. Verified via `Grep`:
- No existing `users` table migration in this repo (only `users_api_access_keys`, `api_history`).
- No existing `Auth` controller, `UserModel`, or auth filters. No callers to break.
- **`app/Config/Filters.php`** is shared with the API layer. Adding `authFilter`/`adminFilter`/`guestFilter`
  aliases is additive. **Do not** add `csrf`/`authFilter` to `globals` — the `api` group is stateless and
  its `ApiFilter::after()` calls `session()->destroy()`. Apply web filters per-group only, and if enabling
  CSRF, scope it to web routes (exclude `api/*`). Blast radius: low, but Filters.php touches every request —
  reviewer must confirm the API group behaviour is unchanged.
- **Session config** (`app/Config/Session.php`, FileHandler) is currently only exercised by the (stateless)
  API path. Web auth is the first real session consumer; no change needed to the driver, but note the API
  `after()` session-destroy must remain scoped to the API group only.

## 5. Implementation steps

### Phase 1 — Backend (owner: `backend-coder`)
Invoke the `codeigniter4` and `mysql-patterns` skills first; apply ponytail.

1. [ ] Add `app/Config/Auth.php` with `password_algorithm='md5'`, `password_salt=''`. — owner: `backend-coder`
   - Acceptance: `config('Auth')->password_algorithm === 'md5'`; `hash('md5', $pw . '')` reproduces a legacy hash.
2. [ ] Add `app/Models/UserModel.php` over table `users`: `returnType='object'`, `useSoftDeletes=true`,
   `deletedField='deleted_at'`, `useTimestamps=false`, `allowedFields` limited to what we write
   (`login_at`, plus fields the seeder/command set: `name`,`email`,`password`,`zone`,`env`,`status`,
   `two_factor_method`,`language`,`timezone`). Add `findActiveByEmail(string $email): ?object`
   (soft-delete respected) and `stampLogin(int $id): void`. — owner: `backend-coder`
   - Acceptance: `findActiveByEmail()` returns an object for an active, non-deleted user and `null` for a soft-deleted one.
3. [ ] Add idempotent migration `2026-07-13-000000_CreateUsers.php`: wrap the whole `up()` in
   `if ($this->db->tableExists('users')) return;`. Columns must match the live schema exactly:
   `id` BIGINT unsigned PK auto-inc; `name` VARCHAR(255); `email` VARCHAR(255) (unique index);
   `password` VARCHAR(255); `two_factor_method` ENUM('none','google2fa','email_otp') default 'none';
   `google2fa_secret` VARCHAR(255) null; `zone` VARCHAR(20); `env` ENUM('LIVE','TESTING') default 'TESTING';
   `role` VARCHAR(...) null; `language` VARCHAR(5) null; `status` INT default 0; `timezone` VARCHAR null;
   `currency` VARCHAR null; `first_name`/`last_name`/`company` VARCHAR null;
   `created_at`/`updated_at`/`deleted_at`/`login_at` DATETIME null. Add unique key on `email`.
   `down()` drops the table only if this migration created it (safe on live). Consult `mysql-patterns` for the
   index/charset choices. — owner: `backend-coder`
   - Acceptance: `php spark migrate` is a no-op on `db_proxy_dev` (table exists) and creates a schema-compatible
     table on a fresh SQLite `tests` DB.
4. [ ] Add `app/Helpers/auth_helper.php`: `is_authenticated()`, `is_admin()` (`zone==='global'`),
   `is_client()` (`zone==='client'`). — owner: `backend-coder`
   - Acceptance: helper returns correct booleans given a fabricated `userAuthenticated` session object.
5. [ ] Add `app/Controllers/Auth.php` (extends `BaseController`):
   - `showLogin()` GET: if authenticated, redirect to dashboard; else `view('auth/login')`.
   - `login()` POST JSON: CI4 `Validation` (`email` required/valid_email/max_length[255], `password`
     required/min_length[5]); look up via `UserModel::findActiveByEmail`; verify
     `hash_equals($user->password, hash('md5', $pw))`; require `(int)$user->status === 1` (reject 0/2/3 with a
     generic "invalid or inactive" message — see §7 status note); on success set session `userAuthenticated`
     AND `userWorking` to the user object, set `language` from `$user->language`, `UserModel::stampLogin($id)`,
     return `{status:'success', redirect_url}` (admin→`/admin/dashboard`, client→`/dashboard`); on failure
     return `{status:'failed', message}` with HTTP 401. **Do NOT port the legacy `RUNNING_MODE=dev`
     password-skip backdoor.**
   - `logout()`: `session()->destroy()`, redirect to `/login`.
   - owner: `backend-coder`
   - Acceptance: valid creds → JSON success + session set; bad password / inactive → 401 JSON failure, no session.
6. [ ] Add filters `AuthFilter` (no `userAuthenticated` → redirect `/login`), `AdminFilter`
   (`!is_admin()` → redirect `/dashboard` or 403), `GuestFilter` (authenticated → redirect to their
   dashboard). Register aliases in `app/Config/Filters.php`. — owner: `backend-coder`
   - Acceptance: unit/feature tests in Phase 3 pass; API group behaviour unchanged.
7. [ ] Wire `app/Config/Routes.php`: `GET /login`→`Auth::showLogin` and `POST /login`→`Auth::login`
   under `guestFilter`; `GET /logout`→`Auth::logout` under `authFilter`; group `admin` (→`Admin\Dashboard::index`
   at `/admin/dashboard`) behind `[authFilter, adminFilter]`; client area (`/dashboard`→`Client\Dashboard::index`)
   behind `authFilter`. Enable CSRF for web POSTs scoped to exclude `api/*` (per §4). — owner: `backend-coder`
   - Acceptance: `php spark routes` lists the new routes with correct filters; `/admin/dashboard` unreachable when logged out.
8. [ ] Add `app/Controllers/Admin/Dashboard.php` and `app/Controllers/Client/Dashboard.php` — each `index()`
   renders its shell view. No business logic. — owner: `backend-coder`
   - Acceptance: authenticated admin sees admin shell; authenticated client sees client shell.
9. [ ] Add `app/Commands/UserPassword.php` (`user:password <email> <password>`): find user by email
   (fail loudly if missing), update `password = hash('md5', $password)` via `UserModel`. — owner: `backend-coder`
   - Acceptance: after running, that user can log in with the given password.
10. [ ] Add `app/Database/Seeds/DevAdminSeeder.php`: upsert `admin@xconnecting.com` with `zone='global'`,
    `status=1`, `two_factor_method='none'`, a settable dev password (md5). **Only insert if absent — never
    overwrite an existing row** (guard on email lookup). — owner: `backend-coder`
    - Acceptance: on a DB without the dev admin, seeding makes the app loginable; on a DB that already has the
      account, seeding is a no-op.

### Phase 2 — Frontend (Views) (owner: `frontend-coder`)
Load a UI/UX skill (`stitch-design-taste` or `minimalist-ui`) before building. TailAdmin/Tailwind +
Alpine + vanilla `fetch()`; `esc()` every dynamic value; no jQuery/Bootstrap.

1. [ ] `app/Views/layouts/app.php` — shared authenticated layout: TailAdmin sidebar + topbar with user
   menu (name/email + logout link to `/logout`), `$this->section('content')`. Define the shared Alpine
   components **once here**: `toast`, `confirm-modal`, `alert` banner (reusable, event-driven; never native
   `alert()`/`confirm()`). Include the CSRF meta/token for `fetch()`. — owner: `frontend-coder`
   - Acceptance: a child view extending this layout renders chrome + can trigger a toast; user name is `esc()`d.
   - UI notes: this layout is the foundation for all future feature pages — keep the toast/confirm/alert API stable.
2. [ ] `app/Views/auth/login.php` — TailAdmin centered login card, email+password fields, Alpine form state,
   `fetch()` POST to `/login` with CSRF token, JSON handling (success→`window.location = redirect_url`;
   failure→toast/alert with `message`). — owner: `frontend-coder`
   - Acceptance: submitting valid creds redirects to the dashboard; invalid shows the error via the shared component.
3. [ ] `app/Views/admin/dashboard.php` and `app/Views/client/dashboard.php` — extend `layouts/app.php`,
   placeholder stat/summary cards only (real widgets are later cards). — owner: `frontend-coder`
   - Acceptance: both render inside the shell with correct sidebar nav for their zone; all output `esc()`d.

### Phase 3 — Tests (owner: `tester`)
Use the `tests` (SQLite in-memory) DB group; seed a known user per test (no external mocks).

1. [ ] Feature: login success — active user, correct md5 password → 200 JSON `success` + `redirect_url`, session set.
2. [ ] Feature: wrong password → 401 JSON `failed`, no session.
3. [ ] Feature: disabled account (`status=0`, and a `status!=1` case) → rejected, no session.
4. [ ] Feature: soft-deleted user (`deleted_at` set) → cannot log in.
5. [ ] Feature: `adminFilter` blocks a client user from `/admin/dashboard`; allows a `zone='global'` user.
6. [ ] Feature: `guestFilter` redirects an authenticated user away from `/login`.
7. [ ] Feature: logout destroys the session (subsequent protected request redirects to `/login`).
8. [ ] Unit: `UserModel::findActiveByEmail` (active vs soft-deleted) and `auth_helper` booleans.
9. [ ] CLI: `user:password` sets a hash that logs in.

## 6. Test plan
- **Unit:** `UserModel::findActiveByEmail`/`stampLogin`; `is_authenticated`/`is_admin`/`is_client`; md5 hash reproduction.
- **Integration:** POST `/login` (success/fail/disabled/soft-deleted), GET `/logout`, filter-gated GET
  `/admin/dashboard` and `/dashboard`, GET `/login` guest redirect; migration runs clean on SQLite and is a
  no-op on an existing `users` table.
- **Browser / E2E:** log in as dev admin → admin dashboard shell renders; log in as a client → client shell;
  wrong password shows toast; logout returns to `/login`; direct-nav to `/admin/dashboard` while logged out redirects.
- **Manual smoke:** run `php spark migrate`, `php spark db:seed DevAdminSeeder`, then
  `php spark user:password admin@xconnecting.com <known>`; `php spark serve`; log in at `/login`.

## 7. Risks & open questions
- **md5 password hashing (KEEP, do not "fix"):** required for legacy credential compatibility this card.
  Mitigation: enforce `status` + soft-delete checks, CSRF on the POST, `hash_equals()` for the compare.
  Optional future card: transparently rehash to bcrypt on successful login (dual-verify md5 then upgrade).
- **2FA deferral (SECURITY follow-up — call out loudly):** legacy gates login behind google2fa/email_otp for
  2FA-enabled accounts. This card is **password-only**, so accounts like `admin+1@xconnecting.com`
  (`two_factor_method='google2fa'`) will authenticate with password alone until the dedicated 2FA card lands.
  Document this clearly; the 2FA card must re-instate the `2fa_required` branch before production exposure.
- **`status` semantics mismatch:** the task brief says `status` int `1=active/0=disabled`, but legacy code
  treats `1=active`, `2=not-activated`, `3=suspended`. Decision: **only `status===1` may log in**; every other
  value is rejected with a generic message. Compare with `(int)$user->status === 1` (MySQL/SQLite may return it
  as a string). Open question: should `2`/`3` get distinct user-facing messages? Defaulting to a single generic
  message for now.
- **CSRF vs API group:** enabling CSRF must be scoped to web routes and must not touch the stateless `api/*`
  group (whose `ApiFilter::after()` destroys the session). Reviewer to confirm.
- **`RUNNING_MODE=dev` backdoor removed:** legacy skips the password check in dev — intentionally NOT ported.
- **Session driver:** FileHandler is fine for this card; if multi-node deploy matters later, switch to the DB/Redis
  handler (separate concern).

## 8. Rollout
- Migration order: run after existing `users_api_access_keys` / `api_history` migrations. On `db_proxy_dev`
  (and prod) `CreateUsers` is a guarded no-op; on fresh/greenfield DBs it creates the table.
- Seeding: `php spark db:seed DevAdminSeeder` (dev/test only — do not seed prod). Set a real admin password with
  `php spark user:password <email> <password>` rather than shipping a hardcoded one.
- No new env vars required. Confirm `app.baseURL` is set so `redirect_url` and CSRF cookie work.
- Deploy note: because 2FA is deferred, do not expose this to untrusted networks until the 2FA card lands
  for accounts that require it.

## Backlog (enumerated, NOT planned here)
2FA (google2fa + email_otp), remember-me cookie, sub-user delegation, password-reset/signup/email-verify,
captcha + login lockout, md5→bcrypt rehash, and feature pages: API-keys UI, API history UI, CNR/JPRS consoles,
user management, servers, scheduled tasks, translations, email templates, settings/site variables, system logs,
PSI Billing console.
