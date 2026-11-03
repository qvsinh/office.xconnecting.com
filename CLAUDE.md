# Project: CI4 + TailAdmin Admin-Panel Starter

A reusable **CodeIgniter 4.7 + TailAdmin (Tailwind CSS + Alpine.js)** admin-panel starter,
built end-to-end by AI agents. Use it as the base for new projects — it ships auth, users,
roles/permissions, settings, i18n, email, scheduled tasks, and a system toolkit, so new work
starts from a working, tested admin shell. See **`STARTER.md`** for setup + full contents.

- **Frontend template:** [TailAdmin free Tailwind dashboard](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template)
  — Tailwind CSS + Alpine.js, compiled via the Tailwind CLI (no CDN).
- **Working language (hard rule):** write **all saved artifacts in English** — code, comments,
  plans, commit messages, docs — regardless of whether the prompt is in Vietnamese or English.
  Chat replies may mirror the user's language; anything written to disk is English.

## Feature summary

- **Auth & access** — email/password login, optional basic-auth site gate, TOTP 2FA, roles +
  permissions (`user_can()` helper / opt-in `permission:` route filter) alongside a `zone`
  (global/client) admin gate, and admin "act as" impersonation.
- **Admin** — users management, settings (site variables), translations (i18n + JSON export),
  email templates + a read-only sent-email log, scheduled tasks (`php spark schedule:run`), and
  system tools (info, migrations run/rollback, logs, OPcache/cache/DB-session reset).
- **Account** — profile, security (2FA), notifications.
- **UI** — shared TailAdmin layout (collapsible sidebar, dark mode, Outfit font, shared toast/
  confirm/alert Alpine components) + reusable form/button/modal classes.

> Building a new feature: copy an existing module (e.g. **Settings** or **Translations**) as a
> template — controller + model + view + guarded migration + tests — and follow the conventions below.

## Tech Stack

- **Language:** PHP 8.3+
- **Framework:** CodeIgniter 4 — **latest stable: 4.7.4** (target this version) — `app/` holds project code, `system/` is the framework
- **Database:** MySQL/MariaDB via CI4 Query Builder & Models (`app/Models`)
- **Frontend:** CI4 Views rendering **TailAdmin** markup — **Tailwind CSS** for styling,
  **Alpine.js** for interactivity, **vanilla `fetch()`** for AJAX. **No jQuery, no Bootstrap.**
- **Asset build:** Tailwind CLI / npm (`package.json`), output to `public/`.
- **CLI:** CodeIgniter Spark (`php spark ...`).

## Frontend conventions (TailAdmin / Tailwind / Alpine)

- Style with **Tailwind utility classes** and TailAdmin's component patterns. No inline CSS,
  no Bootstrap classes, no `tabler` classes.
- Interactivity via **Alpine.js** (`x-data`, `x-show`, `@click`, etc.). No jQuery.
- AJAX via **vanilla `fetch()`** with the CI4 CSRF token; return `setJSON()` from controllers.
- **Toast**, **confirm modal**, and **alert banner** are small reusable Alpine components (define
  once in the layout, reuse everywhere) — never the browser's native `alert()` / `confirm()`. Load a
  UI/UX skill when first building them.
- **UI/UX skills (installed project-locally via `npx skills add Leonxlnx/taste-skill`):**
  `hallmark` (default taste pass), `better-interface` (review pass — wraps `better-accessibility` /
  `-layout` / `-writing` / `-typography` / `-colors` / `-ui`), `design-taste-frontend`,
  `minimalist-ui` (fits TailAdmin), `high-end-visual-design`, `stitch-design-taste`, `gpt-taste`,
  `industrial-brutalist-ui`, `image-to-code`, `imagegen-frontend-web`/`-mobile`, `brandkit`, plus
  `frontend-patterns`. The `frontend-coder` agent must load the most specific one before non-trivial
  visual work (see its skill table).
- **Escape all dynamic output** with `esc()` (laminas-escaper) to prevent XSS — Tailwind styling
  does not exempt output from escaping.
- Extend a shared layout via `$this->extend()` / `$this->section()`; do not duplicate chrome.

## Backend conventions (CodeIgniter 4)

- Controllers extend `App\Controllers\BaseController` (public) or an admin base for protected areas;
  data access goes through Models (`CodeIgniter\Model`), **never raw SQL**.
- Routes are **explicit** in `app/Config/Routes.php` — auto-routing stays **off**. Group and filter
  routes by area (guest / auth / admin), reusing the existing filters (`authFilter`, `adminFilter`,
  `guestFilter`, `basicAuthFilter`, opt-in `permission`).
- Use **parameterized Query Builder / Model methods** — never concatenate user input into SQL.
- Validate all HTTP input at the controller boundary with CI4 `Validation`.
- **Never log or expose credentials, API keys, tokens, or personal data.** Sensitive
  values go through an encryption library and `.env`, never hardcoded.
- External API/service calls can fail or time out — handle errors explicitly and never
  swallow them, especially where they have side effects.
- Format & check with `php-cs-fixer`, `phpstan`, and `phpunit`.

## One place per concern — reuse these, don't re-invent

| Concern | The single place |
|---|---|
| Password hash/verify | `App\Libraries\PasswordHasher` (bcrypt via `password_hash()`, tuned by `auth.password_algorithm` / `auth.password_cost`). Legacy md5 rows still verify and are rewritten on next login — never add a second hashing site. |
| Sending mail | `App\Libraries\Mailer` (Amazon SES, `aws/aws-sdk-php`). Notifiers extend `App\Libraries\MailNotifier`, which owns the admin address list (`mail_notify_admin_recipient`, comma-separated — `recipients()` trims and de-duplicates, and every address gets its own send and its own `emails` row) and the rule that a send never throws into the caller. `AttendanceNotifier` composes the check-in/out notification for **two** audiences with **two** templates: a report to `mail_notify_admin_recipient` and, when `mail_notify_employee` is on, a receipt to the employee. Each send is dispatched independently so one failure cannot suppress the other; `mail_notify_attendance` is the master switch. Every attempt is logged to `emails` and **never** throws into the caller. |
| Deciding a request | `App\Libraries\RequestReview::apply()` — the lock check, the concurrency guard and telling the employee, for **both** doors: the admin queue and the emailed one-click link. |
| Request mail | `App\Libraries\RequestNotifier` — admin on submit/withdraw, employee on approve/reject, all three under `mail_notify_requests`. The employee's in-app notification is kept *alongside* the decision mail, not replaced by it. |
| Company timezone | `attendance_tz()` — not a literal. Business dates come from `attendance_today()`. |
| Who attendance is measured over | `UserModel::attendanceRoster()` — active accounts minus `users.attendance_exempt`. Used by the today board, admin dashboard, monthly report and the reminders. `activeRoster()` is the *picker* set (every active account) for the leave-request and access-log filters — an exempt person still books leave and still signs in. |
| Translated strings | `lang('group.key')`, resolved from the `translation` table by `App\Libraries\DatabaseLanguage`. |
| Asset cache-busting | `asset_version()` (helper `app`, autoloaded). |
| Modal detail fetches | `fetchLatest(key, url, options)` (layout, next to `postForm()`) — GET JSON for anything a user can reopen before the last request lands. Returns `null` when superseded; the caller returns without touching state. Never call `fetch()` directly from an `openEdit`/`openShow`. |
| Pagination footer | `view('_pagination', …)` — rows-per-page + Prev/Next, used by all nine admin lists. |
| Password inputs | `view('admin/users/_password_field', …)` and `view('_password_reveal', …)`. |

**AWS credentials belong in `mail.aws.*` only.** Never `AWS_ACCESS_KEY_ID` /
`AWS_SECRET_ACCESS_KEY`: the SDK's default credential chain reads those and they then
shadow the EC2 instance role for *every* AWS client in the process.

## i18n

`lang('group.key')` reads `translation` (`location` = group, `slug` = key), layered
over the files in `app/Language/`. Locale is resolved per request by `LocaleFilter`:
session choice -> `users.language` -> `Config\App::$defaultLocale`; only codes in
`$supportedLocales` are honoured.

- Slugs are unique per **`(lang_code, location, slug)`** — `common.date` and
  `attendance.date` can coexist. `common` holds anything used on more than one screen.
- Adding strings: seed them (see `2026-08-05-030000_SeedInterfaceTranslations`) **and**
  run `vendor/bin/phpunit tests/database/TranslationCoverageTest.php`. It extracts every
  `lang()` key from the templates and fails when one is missing a row in any language —
  necessary because a missing key does not error, it renders as the literal `group.key`.
- Translated so far: layout/nav/header, both dashboards, notifications, attendance,
  requests, account. The admin CRUD screens, auth pages and docs are still literal English.

## Scripts

```bash
sudo scripts/prepare-writable.sh   # REQUIRED on a fresh clone: writable/{logs,cache,debugbar} are not in git
scripts/build-assets.sh [--watch]  # npm install if needed, then the Tailwind build
python3 scripts/make-favicon.py    # regenerate the multi-size favicon.ico from the SVG
node scripts/mobile-audit.js       # real-browser check for horizontal overflow (needs a running app + login)
```

## Coding & review discipline — ponytail (REQUIRED)

Use the **`ponytail`** skill for all coding, and **`ponytail-review`** for all code review.
Lazy means efficient, not careless: understand the problem fully, then ship the shortest change
that works.

- **Coding:** invoke `ponytail` before writing/editing code. Climb its ladder — does this need to
  exist? reuse what's already here? stdlib / CI4 built-in / existing dependency before new code?
  Prefer the smallest correct diff, delete over add, boring over clever. No speculative
  abstractions, no scaffolding "for later". Fix bugs at the root (shared function), not per-caller.
- **Review:** invoke `ponytail-review` after code changes (alongside the `reviewer` agent) to flag
  over-engineering, dead code, and needless complexity. Quality/simplification only — it does not
  replace security/correctness review.
- Do **not** simplify away input validation, error handling on external side effects,
  auth/ownership scoping, or `esc()` output escaping — those are never "extra".

Ponytail skill set available (plugin): `ponytail` (coding), `ponytail-review` (review),
`ponytail-audit`, `ponytail-debt`, `ponytail-gain`, `ponytail-help`.

## Task board — kanban (plan → processing → done)

Every planned task is one Markdown file that **moves** through three directories:

- `kanban/plan/` — plan written by `planner`, not yet started.
- `kanban/processing/` — a coder has started; move the file here when work begins.
- `kanban/done/` — implemented, tested, and reviewed; `reviewer` moves it here after sign-off.

One file per task, **moved (never copied)** across stages so its history stays in one place. This
replaces the old `tasks/{id}/plan.md` location. See `kanban/README.md`.

## Common Commands

```bash
php spark serve              # local dev server
php spark migrate            # run database migrations
php spark routes             # list all routes
npm run dev                  # Tailwind watch build (or scripts/build-assets.sh --watch)
npm run build                # Tailwind production build
vendor/bin/phpunit           # run tests
vendor/bin/php-cs-fixer fix  # format code
vendor/bin/phpstan analyse   # static analysis
```

## Gotchas & workflow notes (this project)

- **Tests share one MySQL DB (`database.tests.database` in `.env` — currently `db_office_xcn_vn_test`).**
  Never run two `phpunit` processes at once — concurrent `$refresh` regress/migrate corrupts it
  (errors like `Table '….users' doesn't exist`). Recover with
  `mysql ... -e "DROP DATABASE <name>; CREATE DATABASE <name> CHARACTER SET utf8mb4 ..."` then re-run.
  Two other symptoms with the same fix: `Unknown database '…'` after the DB is renamed in `.env`
  (just CREATE it — migrations rebuild the schema), and
  `There is a gap in the migration sequence near version …` when the DB still holds migration
  history from another branch.
- **`tests/feature` alone runs >10 minutes** and will blow a 600s tool timeout — start it with
  `run_in_background` and wait, or narrow with `--filter`. `vendor/bin/phpunit tests/database tests/unit tests/session`
  is ~1 minute. Run the two groups **one at a time**, never concurrently (next bullet).
- **After editing `app/Views/**` classes, run `npm run build`.** Tailwind JIT only emits classes/arbitrary
  values (e.g. `min-h-[500px]`) present at build time (content glob = `./app/Views/**/*.php`); the browser
  caches `/css/app.css`, so hard-refresh to see changes.
- **Gating tests redirect before the view renders**, so they miss view-variable mismatches (500s).
  `tests/feature/Web/AuthedPagesRenderTest.php` logs in and asserts every authed page returns 200 —
  add new pages to its provider.
- **The emailed approve/reject link is a credential, so it is shaped like one.** `<hashid>.<random>`
  in the URL, only its sha256 in `leave_requests.decision_token_hash`, dead the moment the request
  stops being pending (`review()` and `clearDecisionToken()` both null it), and expiring on the
  app clock (`Time::now()`, never `strtotime()` — a test cannot move the latter). Its route skips
  `authFilter` **and** the basic-auth gate (`Config\Filters::$globals` `except`) because nobody is
  signed in. **The GET only asks**: mail clients and scanners follow links by themselves, so
  deciding needs the POST from the confirm page. Every dead end returns the same 410 page — saying
  *which* dead end would tell a prober whether a token exists.
- **`users.attendance_exempt = 1` means "not on the clock", not "hidden"** — they drop off the
  today board, admin dashboard, monthly report, the check-out reminder and the missing-checkout
  digest, and `Attendance::guardRecording()` refuses their check-in outright. Their *existing*
  rows stay on `/admin/attendance`, which is deliberately where a manager can still see and fix a
  dangling row. The two reminder queries filter on `COALESCE(users.attendance_exempt, 0) = 0`
  rather than through the roster, because they start from `attendance_days`, not from people.
- **Data going into a `<script>` body must use
  `json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)`** (see `requests/index.php`).
  `esc($v, 'js')` is only correct *inside* a quoted JS string — used bare it emits `\x22…\x22`, which is a
  **syntax error**, and a `<script>` that fails to parse silently kills every Alpine handler on that
  component (the `/requests` modal could not be closed). A 200 response proves nothing here;
  `tests/feature/Web/AuthedPagesRenderTest.php` asserts every authed page returns 200 **and** parses
  its inline scripts with `node --check`.
- **In tests use `->response()->getBody()`, never `->getBody()`.** `TestResponse` has no
  `getBody()`, so the call is forwarded to `DOMParser` and you get libxml's re-serialization:
  attribute names lowercased (`viewBox` -> `viewbox`), void elements expanded, and **every
  `@click` silently dropped** because `@` is not a valid attribute name to libxml. Asserting on
  any Alpine binding through `->getBody()` cannot pass, and it looks like the app emitted broken
  markup. `esc($v, 'attr')` also encodes spaces and slashes as entities — decode before matching.
- **`min-w-0` on the layout's content column is load-bearing** (`app/Views/layouts/app.php`).
  A flex item defaults to `min-width: auto` and refuses to shrink below its widest child, so
  without it the column grows to the widest table and drags the whole page sideways — measured
  +706px on `/admin/attendance/report` at 375px, while the `overflow-x-auto` wrappers sit unused.
- **The z-index scale is fixed: sidebar `z-50` < modal overlay `z-[60]` < confirm modal `z-[70]` <
  toast/alert `z-[80]`.** The sidebar is `fixed` and always on screen at `lg`, so a modal below its
  `z-50` has the sidebar punched through its own backdrop — that is what every overlay's original
  `z-40` did, on all 20 of them. New modals copy `z-[60]`; the sidebar's *own* mobile backdrop stays
  at `z-40`, below the drawer it dims. Reasoning about the cascade is enough here only because no
  ancestor of the content column creates a stacking context (no transform/filter/opacity) — check
  that before trusting a z-index, or measure with `document.elementFromPoint()` over the sidebar.
- **A modal that fetches its record must ignore superseded responses** — use `fetchLatest()`.
  Every `openEdit`/`openShow` used to `fetch()` directly, so abandoning a slow open (Escape) and
  picking another row let the first response populate the modal already on screen. Measured in a
  real browser with one row's response delayed 700ms: `/admin/emails` showed row 0's body when
  asked for row 2 (5/5), and `/admin/users` held the *wrong user* in the edit form (4/4) — id
  included, so Save would have written the record you walked away from.
- **Never measure timing or iframe behaviour with Chrome's `--virtual-time-budget`.** It fast-
  forwards timers and fabricates results: it reported that changing an iframe's `srcdoc` never
  commits a document, "reproducing" a bug that does not exist and pointing at the wrong fix. Drive
  a real browser instead — `npm i --no-save playwright-core` (already vendored Chrome:
  `chromium.launch({executablePath: '/usr/bin/google-chrome'})`), and read a sandboxed iframe
  through `page.frames()`, not `contentDocument` (the sandbox makes it null).
- **Bump `asset.version` in the server's `.env`** whenever `public/css/app.css` changes, or
  returning visitors keep the cached stylesheet.
- **`postForm()` (layout AJAX helper) can't send array fields** — send a CSV string and `explode()` server-side
  (see `Admin\WorkSchedules::resolveWorkdays`).
- **`BasicAuthGateTest` has 4 pre-existing failures** (`app.basic.auth.username` is empty in `.env`, which disables the gate) — unrelated to feature work; don't chase them.
- Persist client-side UI state (active tab, etc.) in `localStorage` keyed per record; Alpine auto-calls a
  component's `init()` — use it to `$watch` and persist (see `myRequests` in `requests/index.php`).
- Sidebar nav (`app/Views/layouts/app.php`): top-level links use `url_is('x') || url_is('x/*')` for active
  state so detail pages stay highlighted; `$navGroups` are accordion sections.

---

# CodeGraph — Code Intelligence (CLI)

The `codegraph` CLI is installed (`/usr/bin/codegraph`, v0.9.7). **The MCP tools are
not** — do not call `codegraph_*` tools, they do not exist in this project. Use the
CLI through Bash.

`.codegraph/` does not exist yet, so the first step is `init` + `index`:

```bash
codegraph init && codegraph index     # one-off, builds .codegraph/codegraph.db
codegraph sync                        # after adding code, incremental
codegraph status                      # index health
```

Then, instead of grepping blindly:

| Command | Use |
|---|---|
| `codegraph query <search>` | find a symbol by name |
| `codegraph callers <symbol>` | every call site — run this **before renaming or moving** anything |
| `codegraph callees <symbol>` | what a symbol calls |
| `codegraph impact <symbol>` | blast radius before editing an existing symbol; report it, and warn if it is large |
| `codegraph context <task>` | build task context as markdown |
| `codegraph affected <files...>` | **which test files a change touches** |
| `codegraph index --force` | full rebuild |

`affected` is worth reaching for here specifically: `vendor/bin/phpunit tests/feature`
takes over ten minutes (see the gotchas), so running only the affected tests during
a change and the full suite once at the end is much faster than the full suite each
time.

`codegraph install` would register the MCP server with Claude Code if you prefer the
tool interface — until then, CLI only.

# Available Skills

Use the `Skill` tool. Pick the most specific skill; for non-trivial tasks, check the workflow skills first.

## Workflow & Process (use first)

- **superpowers:using-superpowers** — invoke at the start of any task; explains how to find & apply skills.
- **superpowers:brainstorming** — clarify ambiguous requests before planning.
- **superpowers:writing-plans** / **superpowers:executing-plans** — design and run multi-step plans.
- **superpowers:test-driven-development** — strict RED → GREEN → REFACTOR.
- **superpowers:systematic-debugging** — disciplined root-cause investigation.
- **superpowers:verification-before-completion** — verify before declaring done.
- **superpowers:dispatching-parallel-agents**, **superpowers:subagent-driven-development** — parallel agents.

## PHP / CodeIgniter 4 (PRIMARY)

- **codeigniter4** — project-local skill at `.claude/skills/codeigniter4/SKILL.md`. **Invoke first**
  when touching `app/` PHP. Covers Controllers, Models, Routes, Filters, Views, Services, Spark,
  Validation, Migrations, Sessions, and a Laravel→CI4 anti-pattern table.
- **mysql-patterns** — project-local skill at `.claude/skills/mysql-patterns/SKILL.md`. **Invoke for
  any query, migration, or index.** Safe bindings, composite indexes, EXPLAIN, FULLTEXT vs LIKE,
  JSON columns, keyset pagination, soft-delete indexing, big-table ALTERs.

> **DO NOT use Laravel skills (`laravel-*`).** Laravel (Eloquent, Artisan, Blade, Facades) differs
> significantly from CI4 (Query Builder + `CodeIgniter\Model`, Spark, CI Views with `esc()`,
> `service()` helper). If a pattern feels like Laravel, stop and consult **codeigniter4** or
> https://codeigniter.com/user_guide/.

Supporting: **backend-patterns**, **api-design**, **database-migrations**, **docker-patterns**.

## Frontend / UI (TailAdmin · Tailwind · Alpine)

- **frontend-patterns** — view-layer patterns (apply through Tailwind utilities, not Bootstrap).
- **hallmark** (default), **minimalist-ui**, **design-taste-frontend**, **stitch-design-taste** —
  design-taste guidance; translate their advice into **TailAdmin/Tailwind** components, keeping
  `esc()` on all output.
- **better-interface** — cross-discipline review pass over a finished screen/flow; load a single
  **better-accessibility / -layout / -writing / -typography / -colors / -ui** for narrow fixes.
- **image-to-code** — convert a mockup/screenshot to Tailwind markup.
- **brandkit** — brand tokens; map to the Tailwind theme config.

> Frontend hard rules: **Tailwind + Alpine + `fetch()` only** — no jQuery, no Bootstrap, no tabler.
> Toasts/confirms are Alpine components; escape every dynamic value with `esc()`.

## Code Quality, Review, Security & Testing

- **code-review**, **review** — general review checklist.
- **security-review**, **security-scan** — OWASP sweep (SQLi, XSS, CSRF, secrets).
- **simplify**, **refactor-clean** — reduce complexity, remove dead code.
- **tdd-workflow**, **tdd** — TDD enforcement. **test-coverage** — reach ≥80%. **e2e-testing** — E2E flows.

## Docs & Codebase Knowledge

- **docs**, **update-docs**, **update-codemaps** — keep docs fresh.
- **claude-mem:learn-codebase**, **claude-mem:smart-explore** — explore the codebase, build memory.
- **obsidian:*** — Obsidian-style note workflows for `docs/`.

> When in doubt: **superpowers:using-superpowers** → **superpowers:brainstorming** → domain skill
> (PHP/CI4 → **codeigniter4**; UI → **frontend-patterns** + Tailwind; tests → **tdd-workflow**).
> **Never** invoke `laravel-*` skills on this project.

## Agent rules

Project rules for the global agents (backend-coder, frontend-coder, planner, tester, reviewer); they override the agents' defaults.

**Backend**
- This is an attendance / leave-request / admin starter (no registrar, billing, sub-users or proxy code): ignore any "CNR/JPRS/registrar proxy" wording and the `../proxy.services.vn` reference.
- Auth helpers (`app/Helpers/auth_helper.php`): authorization uses `is_admin()` / `is_client()` / `user_can('perm')` (read `userAuthenticated`); data ownership uses `current_user_id()` (reads `userWorking`). `current_zone()` / `is_working_admin()` are VIEW-ONLY, never for authorization.
- `users.zone` is now `admin` / `user` (renamed from `global` / `client`, migration 2026-07-27-010000). Never compare to a literal: call `zone_is_admin()` (it still accepts legacy `global` for old sessions).
- Admin act-as: `Admin\Users::actAs()` / `stopActing()` (`users/act-as/(:num)`) set `userWorking`. Actions that attest to a person's own conduct (check-in/out in `Attendance`) must refuse when `is_impersonating()`; viewing stays allowed.
- Route groups in `app/Config/Routes.php`: `admin` + `['authFilter','adminFilter']` for admin-only; extra `admin` groups with `['authFilter','permission:<perm>']` (e.g. `requests.approve`, `attendance.manage`) for non-admin managers; `api/v1` + `apikey` filter (`ApiKeyFilter`, `ApiContext`); state-changing POSTs carry `['filter' => 'csrf']` per route. New permission = seed it in a guarded migration.
- Controllers: `BaseController`, area dirs `Admin/`, `Account/`, `Client/`, `Api/V1/`; mail only via `Mailer` / `MailNotifier` subclasses (see CLAUDE.md table).

**Frontend**
- TailAdmin + Tailwind + Alpine + `fetch()` only. Layouts: `app/Views/layouts/app.php` (authed shell) and `layouts/standalone.php` (guest/standalone). Views: `admin/<feature>/`, `account/`, `attendance/`, `client/`, `requests/`, `auth/`, `emails/`; shared partials `_pagination.php`, `_password_reveal.php`.
- Shared Alpine contract (defined in `layouts/app.php`, use exactly these names): toast `$dispatch('toast', {type:'success|error|warning|info', text})`; confirm `$dispatch('confirm', {title, body, variant:'danger|default', onConfirm})`; alert banner `$dispatch('alert', {variant, text})`; from plain JS use `window.dispatchEvent(new CustomEvent('toast', {detail}))`. CSRF: `window.csrf.{fieldName,token,headerName}`; POST via `postForm(url, fields)` (rotates the token from `csrf_hash`). Z-index scale and `fetchLatest()` rules: CLAUDE.md.
- Translated UI text via `lang('group.key')`; admin CRUD/auth pages are still literal English, do not translate them unasked.

**Tests**
- Config file is `phpunit.dist.xml` (CLAUDE.md says `.dist`), ONE suite `App` = `./tests`; no `Unit`/`Database`/`Session` suites. Layout: `tests/unit`, `tests/database`, `tests/session`, `tests/feature/Web` (helper `tests/_support/Web/WebAuthFixture.php`), `tests/feature/Api/V1/ApiTest.php`, `tests/_support`.
- Fast set: `phpunit tests/database tests/unit tests/session`; `tests/feature` is >10 min (background or `--filter`). Use `codegraph affected <files>` to pick tests.

**Plans**
- Planner writes `kanban/plan/{id-or-YYYYMMDD}-{slug}.md`; first coder moves it to `kanban/processing/`; reviewer moves it to `kanban/done/` after sign-off with no CRITICAL/HIGH. Never copy, only move. (Not `tasks/{id}/plan.md`.)

**Review**
- CRITICAL: authorization decided from `userWorking`, or data scoped by `userAuthenticated` (leaks under act-as); a new `/admin` route without `adminFilter` or `permission:`.
- HIGH: state-changing route missing `['filter'=>'csrf']`; attendance-recording path not guarded by `is_impersonating()`; new `site_variable` read missing from `SettingCatalogue::SETTINGS` + guarded migration.
- Verify the emailed decision link (`RequestDecision`) behaviour in CLAUDE.md is not weakened (GET only asks, POST decides, uniform 410).
