# Plan: Basic-Auth site gate (form-based, same as legacy)

**Task:** 20260713
**Created:** 2026-07-13
**Branch suggestion:** `feature/basic-auth-gate`

## 1. Goal
Port the legacy **form-based "basic auth" site gate**: when `app.basic.auth.username` is set, every
browser request is redirected to a `/basic-auth` username/password wall until passed, matching the
old system. It sits IN FRONT of the app login (gate → app login → dashboard). Bearer `api/*` and the
gate route itself are exempt.

## 2. Scope
- **In scope:** `BasicAuthFilter` (global before-filter), `BasicAuth` controller (show gate + verify),
  `basic_auth` TailAdmin view, filter registration with `except` for `api/*` + `basic-auth`, `.env`
  keys, tests.
- **Out of scope:** per-domain `basic_auth_*` proxied-site fields (legacy DomainModel) — unrelated to
  the site gate. HTTP `WWW-Authenticate` Basic — the legacy gate is form+session, not HTTP Basic.

## 3. Legacy behavior (from `../proxy.services.vn`)
- `BaseController::initController`: if `getenv('app.basic.auth.username')` non-empty AND
  `session('basicAuthAuthenticated')` not set AND controller not in exclude list
  (`BasicAuth`, `Webhook`, `Api\CnrApi`, `Api\JprsApi`) → `redirect('/basic-auth')`.
- `BasicAuth::login`: POST compares `username`/`password` to `app.basic.auth.username|password` env;
  on match sets `session('basicAuthAuthenticated', 1)` and returns JSON `{status:success,redirect_url}`;
  else `{status:failed,message}`. GET renders the gate view.

## 4. Affected areas
| Layer | Files | Notes |
|-------|-------|-------|
| Backend | `app/Filters/BasicAuthFilter.php` (new) | before(): gate logic |
| Backend | `app/Controllers/BasicAuth.php` (new) | showGate (GET), authenticate (POST JSON) |
| Backend | `app/Config/Filters.php` | register `basicauth` alias in `$globals['before']` with `except => ['basic-auth', 'api/*']` |
| Backend | `app/Config/Routes.php` | GET/POST `/basic-auth` (POST under `csrf`) |
| Frontend | `app/Views/auth/basic_auth.php` (new) | TailAdmin gate form, fetch()+CSRF |
| Config | `.env` | `app.basic.auth.username`, `app.basic.auth.password` |
| Tests | `tests/session/`, `tests/feature/Web/` | gate on/off, exempt api, correct/wrong creds |

## 5. Implementation steps

### Phase 1 — Backend (owner: `backend-coder`)
1. [ ] `BasicAuthFilter::before()`: if `getenv('app.basic.auth.username')` is empty → return (disabled,
   no-op). Else if `session('basicAuthAuthenticated')` truthy → allow. Else redirect to `/basic-auth`.
   (The `except` list already spares `/basic-auth` + `api/*`, but also hard-skip if the current path
   is `basic-auth` as a belt-and-suspenders.)
2. [ ] Register `'basicauth' => BasicAuthFilter::class` alias; add to `$globals['before']` as
   `'basicauth' => ['except' => ['basic-auth', 'api/*']]`. Confirm `api/*` stays Bearer-only (the gate
   must NOT redirect API clients — matches legacy excludes for CnrApi/JprsApi).
3. [ ] `BasicAuth` controller: `index` (GET, render `auth/basic_auth`), `authenticate` (POST JSON:
   CI4 validation, compare env `username`/`password` with `hash_equals`, on success
   `session()->set('basicAuthAuthenticated', 1)` + return `{status:success,redirect_url,csrf_hash}`
   (redirect `/admin/dashboard` if `is_admin()` else `/dashboard` else `/login`); on fail 401
   `{status:failed,message,csrf_hash}`). Include `csrf_hash` per the established refresh pattern.
4. [ ] Routes: `GET /basic-auth` → `BasicAuth::index`; `POST /basic-auth` → `BasicAuth::authenticate`
   (under `csrf`). Not behind authFilter/guestFilter/adminFilter.
   - Acceptance: with `app.basic.auth.username` set, `GET /dashboard` as a fresh visitor → redirect
     `/basic-auth`; after a valid gate POST, the redirect target loads; `GET /api/cnr/...` is never
     redirected to the gate (still 401 Bearer). With username empty, no gate anywhere.

### Phase 2 — Frontend (owner: `frontend-coder`)
1. [ ] `app/Views/auth/basic_auth.php`: standalone TailAdmin gate card (username+password), fetch()
   POST to `/basic-auth` with CSRF, success → `window.location = redirect_url`, failure → alert with
   `message`; refresh CSRF token from `csrf_hash` between submits (same pattern as `auth/login.php`);
   submit disabled in-flight; `esc()` all output. No jQuery/Bootstrap/tabler.

### Phase 3 — Tests (owner: `tester`)
1. [ ] Gate enabled: guest hitting a web route → redirect `/basic-auth`; after session flag set →
   passes to app login/dashboard. Gate disabled (username empty) → no redirect. `api/*` never gated.
   Correct creds POST → success JSON + session flag; wrong creds → 401 + no flag. CSRF required on POST.

## 6. Test plan
- Feature: gate redirect when enabled+unpassed; no redirect when disabled; api/* exempt; valid/invalid
  gate POST; gate → app login → dashboard chained.
- Session/unit: filter no-op when username empty; `hash_equals` compare.

## 7. Risks & decisions
- **Enabling the gate** requires `app.basic.auth.username` + `password` in `.env` (secrets, not
  committed). This card sets known dev values in the sandbox `.env` and reports them; each env sets its
  own. Empty username = gate off (safe default in the committed sample).
- Gate is a session flag; app `logout` destroys the whole session, so the gate re-prompts after logout
  (matches legacy). Acceptable.
- Filter order: `basicauth` (global before) runs before route filters; `/basic-auth` + `api/*` excepted.

## 8. Rollout
- Set `.env` `app.basic.auth.username`/`password` to enable; leave username empty to disable.
- No migration. No new dependency.
