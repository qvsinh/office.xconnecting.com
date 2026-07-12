# Project: proxyv2.services.vn — Rebuild of proxy.services.vn (Registrar API Proxy, Developer Console & CMS)

**This is a greenfield rebuild** of the existing `proxy.services.vn` platform onto the
**latest CodeIgniter 4** and a **TailAdmin (Tailwind CSS) frontend**, built end-to-end by
AI agents. There is **no application code here yet** — it is being written from scratch.

- **Legacy reference codebase:** `../proxy.services.vn` (added as a working directory). Read it to
  understand existing behaviour, data shapes, and business rules — but **do not copy its
  frontend** (old server-rendered theme system) or its dated patterns. Port intent, not code.
- **Frontend template:** [TailAdmin free Tailwind dashboard](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template)
  — Tailwind CSS + Alpine.js. Use it for the whole project (admin + user areas).
- **Raw prompts** for this rebuild are logged in `changeslog/` for later review.

## What we're rebuilding (feature summary)

A **registrar API proxy/gateway + developer console**. It sits in front of upstream registrar
APIs and exposes them to clients through issued API keys, logging every call. Core domains:

- **API proxy/gateway** — proxy upstream registrar APIs (CNR / CentralNic / RRP, and JPRS for
  `.jp`) to clients via `/api/cnr/*` and `/api/jprs/*`, authenticated by per-client **API access
  keys** (`apiFilter`) with per-key enable/disable and request logging.
- **Developer console** — admin & client consoles to compose and run CNR / JPRS API calls
  (`ConsoleCnr` / `ConsoleJprs`) with request/response **API history** and log management.
- **Billing integration** — upstream **PSI Billing API** console (logs, preview, retention).
- **CMS** — posts, pages, FAQ, news, contact, menus, SEO.
- **CRM** & **user management** — customers, sub-users (delegated access), notifications,
  scheduled tasks, servers, translations, email templates, 2FA, activity/system logs.

> For the exact controllers, models, registrar API contracts, and proxy/keying rules, consult
> the legacy repo at `../proxy.services.vn`.

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
  UI/UX skill (`minimalist-ui` / `stitch-design-taste`) when first building them.
- **Escape all dynamic output** with `esc()` (laminas-escaper) to prevent XSS — Tailwind styling
  does not exempt output from escaping.
- Extend a shared layout via `$this->extend()` / `$this->section()`; do not duplicate chrome.

## Backend conventions (CodeIgniter 4)

- Controllers extend `App\Controllers\BaseController` (public) or an admin base for protected areas;
  data access goes through Models (`CodeIgniter\Model`), **never raw SQL**.
- Routes are **explicit** in `app/Config/Routes.php` — auto-routing stays **off**. Group and filter
  routes (auth / admin / super-admin / api / subuser) as the legacy app does.
- Use **parameterized Query Builder / Model methods** — never concatenate user input into SQL.
- Validate all HTTP input at the controller boundary with CI4 `Validation`.
- **Never log or expose registrar credentials, API keys, or customer payment data.** Sensitive
  values go through an encryption library and `.env`, never hardcoded.
- External registrar/payment calls can fail or time out — handle errors explicitly and never
  swallow them; these operations have billing / provisioning side effects.
- Format & check with `php-cs-fixer`, `phpstan`, and `phpunit`.

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
grepping blindly. **The index is empty until code is written** — early in the rebuild, prefer the
legacy repo (`../proxy.services.vn`, already indexed) for reference lookups.

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
- **claude-mem:learn-codebase**, **claude-mem:smart-explore** — explore the legacy repo, build memory.
- **obsidian:*** — Obsidian-style note workflows for `docs/`.

> When in doubt: **superpowers:using-superpowers** → **superpowers:brainstorming** → domain skill
> (PHP/CI4 → **codeigniter4**; UI → **frontend-patterns** + Tailwind; tests → **tdd-workflow**).
> **Never** invoke `laravel-*` skills on this project.
