# Plan: User-side account features (profile + 2FA)

**Task:** 20260714
**Created:** 2026-07-14
**Branch suggestion:** feature/user-account-and-2fa

## 1. Goal
Give a logged-in user (client *and* admin, for their own account) a self-service area to edit
their profile, change their own password, and enable/disable Two-Factor Authentication. As part of
the same card, close the deferred security gap: when a user has 2FA enabled, login must require the
second factor before the session becomes authenticated.

## 2. Scope
- **In scope**
  - `/account` — view/edit own profile: name (first/last), company, phone, language, timezone.
  - `/account/password` — change own password (current password required; md5 rehash via `Config\Auth`).
  - `/account/security` — enable/disable **Google Authenticator (TOTP / `google2fa`)**: generate
    secret, show QR + manual key **once** during enrollment, verify a code to enable, disable
    (require a valid TOTP code or the account password).
  - **Login 2FA enforcement** — extend `Auth::login`: on password success, if
    `two_factor_method != 'none'` the session does **not** become authenticated; enter a
    pending-2FA state and require `/verify-2fa` before `userAuthenticated`/`userWorking` are set.
  - New Composer deps: `pragmarx/google2fa` + `bacon/bacon-qr-code` (neither is installed — verified
    `vendor/pragmarx` and `vendor/bacon` are absent).
  - Idempotent migration adding `phone` to `users` (current `CreateUsers` has no phone column;
    legacy dev/prod schema does — same guarded pattern as `AddNoteToUsers`).
  - Owner-scoped everything (session identity only, never a request-supplied id); tests below.
- **Out of scope**
  - **`email_otp` 2FA method** — deferred to a follow-up card. Reason: it needs new `email_otp` /
    `email_otp_expires` columns **and** the email-sending infrastructure (templates, `sendEmail`)
    which does not exist in this rebuild yet. `two_factor_method` enum already includes `email_otp`,
    so the DB is forward-compatible. See §7.
  - Admin managing/resetting **another** user's 2FA (admin user-mgmt exists; a "reset 2FA for user"
    action is a possible follow-up).
  - Sub-users / delegated access, email templates, remaining System modules, password-reset/signup.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Account.php` (new) | Profile + password + 2FA setup/enable/disable, all owner-scoped |
| Backend | `app/Controllers/Auth.php` (`login`, new `showVerify2fa`, `verify2fa`) | Pending-2FA login branch |
| Backend | `app/Libraries/Google2faService.php` (new) | Thin wrapper over pragmarx/bacon: `generateSecret`, `qrCodeDataUri`, `verify` |
| Backend | `app/Models/UserModel.php` | Add `first_name`,`last_name`,`phone` to `allowedFields`; add `enableGoogle2fa()`, `disableTwoFactor()` |
| Frontend (Views) | `app/Views/account/profile.php`, `app/Views/account/security.php`, `app/Views/auth/verify_2fa.php` (new) | Extend `layouts/app`; reuse toast/confirm + `postForm()` |
| Database | `app/Database/Migrations/2026-07-14-100000_AddPhoneToUsers.php` (new) | Guarded `columnExists()` no-op pattern |
| Config / Routes | `app/Config/Routes.php` | `/account/*` in the `authFilter` group; `/verify-2fa` GET/POST top-level (controller-guarded) |
| Dependencies | `composer.json` | `pragmarx/google2fa`, `bacon/bacon-qr-code` |
| Tests | `tests/database/`, `tests/feature/Web/` | See §6 |

## 4. Impact analysis
**`Auth::login` — the critical change (blast radius via grep, CodeGraph index still sparse).**

`userAuthenticated` / `userWorking` are read in **49 sites across ~19 files**: `AuthFilter`,
`auth_helper` (`is_authenticated`/`is_admin`/`is_client`), every Client/Admin controller,
`NotificationModel`, and layout/views. All of them read the keys; **none set them except
`Auth::login`**. The change is **additive**:

- Non-2FA users: identical path — set `userAuthenticated`/`userWorking` and redirect (unchanged).
- 2FA users: password success sets **only** `session('pending2faUserId')` (the id, not the user
  object) and returns `redirect_url => /verify-2fa`. `userAuthenticated`/`userWorking` are set only
  after `verify2fa` succeeds.

Because the pending state sets neither session key, every downstream reader (filters, helpers,
controllers) treats a pending user as unauthenticated — no reader needs changing.

**Tests exercising the login/session path (must stay green):**
`tests/database/AuthLoginTest.php`, `tests/feature/Web/AuthWebFlowTest.php`,
`tests/session/AuthFiltersTest.php`, and every feature test using
`tests/_support/Web/WebAuthFixture.php` (which seeds the session **directly**, not through the login
flow — so it is unaffected by the pending branch). No rename/move of an existing symbol; no
signature changes. Blast radius: **moderate, contained** — the risk is behavioural (login flow),
not structural. Mitigation: add the 2FA-enforcement + non-2FA-unchanged tests in §6 first (TDD).

**`UserModel::$allowedFields`** — adding fields is backward-compatible (protectFields stays on).

## 5. Implementation steps

### Phase 0 — Dependencies & schema
1. [ ] Add `pragmarx/google2fa` and `bacon/bacon-qr-code` via `composer require`. — owner: `backend-coder`
   - Acceptance: `composer.json` updated, `vendor/` populated, `composer audit` clean, existing suite still green.
2. [ ] Migration `AddPhoneToUsers` (guarded `columnExists()` no-op, mirror `AddNoteToUsers`). — owner: `backend-coder`
   - Acceptance: `php spark migrate` adds `phone VARCHAR(50) NULL` only where absent; re-running is a no-op; `down()` drops it when present.

### Phase 1 — Backend
1. [ ] `App\Libraries\Google2faService` wrapping pragmarx (`generateSecret(): string`,
   `verify(string $secret, string $code): bool`) and bacon (`qrCodeDataUri(string $label, string $secret): string` returning a `data:image/...;base64,` URI + the `otpauth://` URL). — owner: `backend-coder`
   - Acceptance: unit test verifies a known secret+code pair passes and a wrong code fails; QR method returns a non-empty data URI. No SDK types leak past the wrapper.
2. [ ] `UserModel`: add `first_name`,`last_name`,`phone` to `allowedFields`; add
   `enableGoogle2fa(int $id, string $secret)` (sets `google2fa_secret` + `two_factor_method='google2fa'`)
   and `disableTwoFactor(int $id)` (nulls `google2fa_secret`, sets `two_factor_method='none'`). — owner: `backend-coder`
   - Acceptance: model tests cover enable/disable; enum guard keeps `two_factor_method` in {none,google2fa}.
3. [ ] `Account` controller — `index()` (render profile), `update()` (name via first/last, company,
   phone, language, timezone), `password()` (current-password required, rehash via `Config\Auth`).
   All operate on `session('userWorking')->id` — **never** a request id. Validation at boundary;
   `jsonSuccess`/`jsonFailed` envelope. — owner: `backend-coder`
   - Acceptance: updates only the session user's row; wrong current password rejected; password never
     echoed/logged; validation errors returned in the envelope.
4. [ ] `Account` 2FA actions — `security()` (render), `setupGoogle2fa()` (generate + persist secret,
   return QR data URI + manual key + otpauth URL; only if not already enabled),
   `enableGoogle2fa()` (verify code → `enableGoogle2fa()` model call), `disable()` (require a valid
   TOTP code **or** the account password, then `disableTwoFactor()`). — owner: `backend-coder`
   - Acceptance: enrollment returns secret/QR once; enabling requires a correct code; disabling
     requires a valid code or password; `google2fa_secret` is never returned after enrollment.
5. [ ] `Auth::login` pending-2FA branch + `Auth::showVerify2fa()` (GET) + `Auth::verify2fa()` (POST). — owner: `backend-coder`
   - Pending state: `session('pending2faUserId')` (id only; re-fetch the user in `verify2fa`).
   - `showVerify2fa`/`verify2fa` guard: if `is_authenticated()` → redirect dashboard; if no pending id
     → redirect `/login` (no new filter needed — pending users aren't authenticated so `guestFilter`
     would pass them; guard in-controller is the smaller diff).
   - On valid code: `stampLogin`, `session()->regenerate(true)`, set `userAuthenticated`/`userWorking`/`language`, clear pending id, return `redirect_url`.
   - Brute-force guard: cap verify attempts per pending session (e.g. `session('verify2faAttempts')`,
     reset on success, fail closed after N) — 6-digit TOTP has only 1M combos.
   - Acceptance: non-2FA login unchanged (existing tests green); 2FA account gets `redirect_url=/verify-2fa`
     and is **not** authenticated until a valid code; wrong code rejected; attempt cap enforced; CSRF on POST.
6. [ ] Routes: `/account`, `/account/update`, `/account/password`, `/account/security`,
   `/account/security/setup-google2fa`, `/account/security/enable-google2fa`,
   `/account/security/disable` in the **`authFilter`** group (POSTs `csrf`); `/verify-2fa` GET +
   POST(`csrf`) top-level. — owner: `backend-coder`
   - Acceptance: `php spark routes` lists them; auto-routing stays off; POSTs carry the csrf filter.
   - Update the `Auth.php` docblock note that said "No 2FA branch yet".

### Phase 2 — Frontend (Views)
1. [ ] `account/profile.php` + `account/security.php` extending `layouts/app`. Profile form (name,
   company, phone, language, timezone) + change-password form. Security page shows current method,
   an enable flow (fetch `setup-google2fa` → render returned QR + manual key, enter code to enable)
   and a disable flow (confirm modal + code/password). — owner: `frontend-coder`
   - Acceptance: forms submit via `postForm()`/`fetch()` with CSRF; success/error via shared
     toast; disable uses the shared confirm modal; all dynamic output `esc()`/`x-text`.
   - UI notes: TailAdmin/Tailwind + Alpine; reuse shared components; load the most specific UI/UX skill;
     the secret/QR/manual key is shown **only** in the enrollment response, never re-rendered afterward.
2. [ ] `auth/verify_2fa.php` — second-factor page for the pending-login flow (code input, submit via
   `fetch()`, error via toast/inline). — owner: `frontend-coder`
   - Acceptance: posts to `/verify-2fa` with CSRF; on success follows `redirect_url`; no user data
     beyond what the flow needs is rendered.
3. [ ] Add an "Account / Security" entry to the user nav in `layouts/app`. — owner: `frontend-coder`
   - Acceptance: visible to authenticated users; links resolve.

### Phase 3 — Tests
1. [ ] Backend + integration tests per §6 (write the login-2FA enforcement tests **first**, RED). — owner: `tester`

## 6. Test plan
- **Unit**
  - `Google2faService`: correct code verifies, wrong code fails, QR returns a data URI.
- **Integration / DB**
  - `UserModel::enableGoogle2fa` / `disableTwoFactor` set the expected columns.
  - Profile update writes only allowed fields to the session user's row.
- **Feature (Web)**
  - **Profile update is owner-only (IDOR-proof):** a request cannot edit another user's row — the
    controller ignores any request-supplied id and writes to the session user only.
  - **Change password:** current-password required; correct current → rehash & success; wrong
    current → rejected; new value is stored hashed (md5 via `Config\Auth`), never echoed.
  - **2FA enable:** setup returns secret/QR; enabling with a correct TOTP sets
    `two_factor_method=google2fa`; wrong code rejected.
  - **2FA disable:** valid code/password disables and nulls the secret; wrong code/password rejected.
  - **Login requires 2nd factor when 2FA enabled:** password-correct 2FA user receives
    `redirect_url=/verify-2fa` and is NOT authenticated (session lacks `userAuthenticated`); after a
    valid code, authenticated + redirected to dashboard; wrong code stays pending; attempt cap fails closed.
  - **Non-2FA login unchanged:** all existing `AuthLoginTest` / `AuthWebFlowTest` / `AuthFiltersTest`
    assertions stay green.
  - **Gating:** `/account/*` and `/verify-2fa` behave correctly for unauthenticated / pending / authenticated.
  - **XSS:** profile fields (name/company/phone) render escaped.
  - **Secret redaction:** `google2fa_secret` is never present in any response after enrollment
    (profile, security page reload, JSON envelopes).
- **Manual smoke**
  - Enroll TOTP with a real authenticator app, log out, log back in, confirm the code is required;
    disable 2FA and confirm password-only login returns; edit profile + change password.

## 7. Risks & open questions
- **Login-flow security (highest):** the pending state must set *neither* session identity key, or a
  2FA user could bypass the second factor. Mitigation: store id-only in `pending2faUserId`, re-fetch,
  set identity only in `verify2fa`; TDD the enforcement + non-2FA-unchanged tests first; brute-force cap.
- **Session fixation:** keep `session()->regenerate(true)` at the point identity is granted (now in
  `verify2fa` for 2FA users, still in `login` for non-2FA users).
- **New dependencies:** `pragmarx/google2fa` + `bacon/bacon-qr-code` — run `composer audit`; wrap
  behind `Google2faService` so the rest of the app depends on our contract, not the SDK.
- **Scope decision — google2fa first, email_otp deferred:** email_otp needs new columns *and*
  email infrastructure that doesn't exist yet. Building it now would balloon the card; the enum
  already allows `email_otp`, so it's a clean follow-up. **Recommend shipping google2fa only.**
- **`phone` column:** current `CreateUsers` lacks it; legacy dev/prod has it. Guarded migration keeps
  fresh (test) and existing (dev/prod) DBs consistent. If the team prefers dropping phone from the
  profile form instead, the migration can be skipped — decision needed but google2fa first is the default.
- **TOTP clock drift:** rely on the library's verification window; do not hand-roll.

## 8. Rollout
- Migration order: run after existing migrations; `AddPhoneToUsers` is a guarded no-op where the
  column exists (dev/prod), a real add on fresh/test DBs.
- Deploy: `composer install` (new deps), `php spark migrate`, `npm run build`.
- No env vars or feature flags required. No data backfill.
- Communicate to existing 2FA-enabled accounts that the second factor is now enforced at login
  (previously password-only bypassed it).

---
## Decisions confirmed (2026-07-14, by orchestrator — build to these)
1. **google2fa (TOTP) only this card; email_otp deferred** to a follow-up (needs email infra; enum already allows it).
2. **Add the phone field, but use column name `phone_number`** (NOT `phone`) — matches the existing legacy/dev `users.phone_number` column, so the guarded migration no-ops on dev/prod and only backfills fresh/test DBs. Use the `resetDataCache()`→`fieldExists` guard pattern.
3. Login-2FA enforcement is purely additive via `session('pending2faUserId')`; non-2FA login path must stay byte-identical (keep all AuthLoginTest green).
