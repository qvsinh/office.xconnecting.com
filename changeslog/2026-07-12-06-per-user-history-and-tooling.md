# 2026-07-12 — Per-user API history scoping + dev tooling (php-cs-fixer, phpstan, sqlite)

## Raw prompt

> Add per-user scoping to fetchHistory
> php-sqlite3, php-cs-fixer, and phpstan should be installed and use in this project

## Decisions

- **API history is per-user**, not per-service: `fetchHistory()` now scopes by `user_id` in
  addition to `service_name` + `service_id`, so a caller cannot see the history of another user
  who holds a key for the same `service_id`. (This tightens the reviewer's MEDIUM #3 finding;
  diverges intentionally from the legacy per-service behavior for tenancy safety.)
- Standardize on **php-cs-fixer** (CI4 coding standard via `nexusphp/cs-config` +
  `codeigniter/coding-standard`) and **phpstan** (level 5) as required project tooling.
- Use **SQLite in-memory** for the PHPUnit `tests` DB group (CI4 default) once `php8.3-sqlite3`
  is installed, replacing the interim MySQL `db_proxy_test` workaround.

## What the agent did

- Added `user_id` scope to `ApiController::fetchHistory()` + a cross-user isolation feature test.
- `composer require --dev friendsofphp/php-cs-fixer nexusphp/cs-config codeigniter/coding-standard
  phpstan/phpstan`; added composer scripts `cs`, `cs-fix`, `analyse`.
- Ran php-cs-fixer (CI4 standard) across the api code; created `phpstan.neon` (level 5, excludes
  framework ENVIRONMENT-branch config stubs); fixed the real phpstan findings (`ApiFilter`
  missing explicit returns). `composer analyse` → No errors; 67 tests green.
- **php8.3-sqlite3**: requires `sudo` (not available to the agent) — user runs
  `sudo apt-get install -y php8.3-sqlite3`, then the agent switches the `tests` group from the
  MySQL override back to SQLite `:memory:` and re-verifies.
