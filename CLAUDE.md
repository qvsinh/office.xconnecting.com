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
  `stitch-design-taste` (default taste pass), `design-taste-frontend`, `minimalist-ui` (fits
  TailAdmin), `high-end-visual-design`, `gpt-taste`, `industrial-brutalist-ui`, `image-to-code`,
  `imagegen-frontend-web`/`-mobile`, `brandkit`, plus `frontend-patterns`. The `frontend-coder`
  agent must load the most specific one before non-trivial visual work (see its skill table).
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
npm run dev                  # Tailwind watch build (once package.json exists)
npm run build                # Tailwind production build
vendor/bin/phpunit           # run tests
vendor/bin/php-cs-fixer fix  # format code
vendor/bin/phpstan analyse   # static analysis
```

---

# CodeGraph — Code Intelligence

Once application code exists it is indexed by **CodeGraph** (local SQLite graph at
`.codegraph/codegraph.db`). Use the CodeGraph MCP tools to navigate and assess impact instead of
grepping blindly. Run `codegraph sync` after adding code so the index stays current.

## Always Do

- **Run impact analysis before editing an existing symbol.** Use `codegraph_impact` and report the
  blast radius (callers, callees, affected files) before modifying a function/class/method.
- **Warn** if impact analysis shows a large/critical blast radius before proceeding.
- Use `codegraph_search` to locate symbols and `codegraph_context` to build task context instead of grepping.
- When renaming/moving a symbol, run `codegraph_callers` first to enumerate every call site.

## MCP Tools Quick Reference

| Tool | When to use |
|------|-------------|
| `codegraph_search` | Find symbols by name |
| `codegraph_context` | Build relevant code context for a task |
| `codegraph_node` | Get symbol details with optional source |
| `codegraph_callers` / `codegraph_callees` | What calls / is called by a symbol |
| `codegraph_trace` | Trace call paths between symbols |
| `codegraph_impact` | Analyze affected code (blast radius) |
| `codegraph_status` | Check index health |

CLI equivalents: `codegraph status`, `codegraph query <search>`, `codegraph callers/callees <symbol>`,
`codegraph impact <symbol>`, `codegraph sync` (incremental), `codegraph index --force` (full rebuild).

---

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
- **minimalist-ui**, **design-taste-frontend**, **stitch-design-taste** — design-taste guidance;
  translate their advice into **TailAdmin/Tailwind** components, keeping `esc()` on all output.
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
