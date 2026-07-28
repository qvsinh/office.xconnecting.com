# CI4 + TailAdmin Admin-Panel Starter

A batteries-included **CodeIgniter 4.7 + TailAdmin (Tailwind CSS + Alpine.js)**
admin-panel starter. Use it as the base for a new project instead of wiring up
auth, users, settings, and a dashboard shell from scratch.

## What's included

**Auth & access**
- Email/password login — bcrypt via `App\Libraries\PasswordHasher`, tuned in
  `.env` (`auth.password_algorithm`, `auth.password_cost`). Pre-existing unsalted
  MD5 rows still verify and are re-hashed on next successful login, so a ported
  database needs no forced reset. Logout, guest/auth route filters.
- Optional **basic-auth site gate** in front of login (enable by setting
  `app.basic.auth.username`/`password` in `.env`; auto-bypassed when empty).
- **Two-factor auth** (Google Authenticator / TOTP) — self-service enrol/disable.
- **Roles & permissions** scaffold — `roles`, `permissions`, `role_permissions`,
  `users.role_id`; `user_can('x.y')` helper (admin zone = super-user) and an
  opt-in `permission:x.y` route filter. Coexists with the `zone` (global/client)
  admin gate.
- Admin **impersonation** ("act as" a user) with a banner.

**Admin area**
- Users management (CRUD, search, activate/deactivate, notes).
- Settings (key/value `site_variable` store) — e.g. the site name shown in titles.
- Translations (i18n strings + JSON export).
- Email templates + a read-only sent-email log.
- Scheduled tasks (recurring jobs run by `php spark schedule:run` via cron; a
  fixed handler allowlist, run-now, enable/disable).
- System tools: info (PHP/OPcache/extensions), DB migrations run/rollback,
  log viewer, OPcache reset, cache clear, DB-session count + reset.

**Attendance & leave** (what this deployment is for)

- **Check-in / check-out** — one row per employee per day, Vietnam business day,
  optional office-IP restriction, audited manager corrections, payroll month lock.
- **Work schedules & holidays** — the baseline every figure is measured against.
- **Reporting** — today board, monthly report per employee, CSV export for payroll.
- **Leave & late requests** — employee submits, manager approves; approved leave
  changes how the attendance sheet reads. Annual-leave quota counted in workdays.
- **Reminders** — check-out nudges and manager digests via `spark schedule:run`.

> The starter's workspaces / projects / kanban module was **removed** from this
> deployment (see migration `2026-07-27-020000_DropWorkspaceStack`); restore it
> from git history if a future project needs it.

**UI**
- Shared TailAdmin layout: collapsible sidebar (icon rail + accordion groups),
  dark/light theme, Outfit font, shared toast/confirm/alert Alpine components,
  reusable form/button/modal classes (`.form-input`, `.form-select`, `.btn-*`,
  `.modal-panel`).
- Compiled Tailwind (no CDN) via the Tailwind CLI.

## Tech stack
PHP 8.3 · CodeIgniter 4.7 · MySQL/MariaDB (Query Builder + `CodeIgniter\Model`) ·
Tailwind CSS + Alpine.js + vanilla `fetch()` · Tailwind CLI build · Spark CLI.

## Quick start

```bash
# 1. Get the code + PHP deps
composer install

# 2. Configure
cp env .env
#   In .env set at minimum:
#   - CI_ENVIRONMENT = development
#   - app.baseURL   = http://localhost:8080/
#   - database.default.*  (hostname/database/username/password)
#   - hashid_salt   = <a long random string>
#   - session.driver = CodeIgniter\Session\Handlers\DatabaseHandler   (optional)
#   - app.admin.email / app.admin.password  (optional — seeder defaults otherwise)

# 3. Database
#   create the database named in database.default.database, then:
php spark migrate
php spark db:seed AdminUserSeeder      # creates the first admin

# 4. Front-end assets
npm install
npm run build            # or `npm run dev` to watch

# 5. Run
php spark serve
```

Then log in with the seeded admin (`app.admin.email` / `app.admin.password`, or
the defaults printed by the seeder) — **change the password immediately**.

## Using it as a new project's base
1. Rename the package in `composer.json` (`name`/`description`).
2. Set the site name in `/admin/settings` (`site_name`) — it drives page titles,
   the sidebar brand, and the 2FA issuer. The compiled default is "CI4 Starter".
3. Point `.env` at a fresh DB + a new `hashid_salt`.
4. Build features under `app/Controllers`, `app/Models`, `app/Views` following
   the conventions in `CLAUDE.md`. Gate admin-only pages with the `adminFilter`,
   or fine-grained pages with `permission:your.permission`.

## Notes
- Behind a reverse proxy/CDN: set `Config\App::$proxyIPs`, and for Cloudflare use
  `session.matchIP = false`.
- Everything is covered by PHPUnit (`RUNNING_MODE=testing vendor/bin/phpunit`),
  php-cs-fixer, and phpstan — keep them green.
