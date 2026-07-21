# Internal Social Hub — Master Plan

> **For agentic workers:** implement task-by-task. Each feature has its own plan
> file (`20260721-0N-*.md`). This file is the shared architecture + data model
> every feature plan refers back to. Written in English (hard project rule).

**Goal:** Turn the CI4 + TailAdmin starter into an internal company collaboration
hub: users/groups/permissions, workspaces & projects people join, project files,
a kanban board + task lists per project, company + personal dashboards, personal
API keys, a REST API for AI agents to CRUD projects/tasks, and connection docs.

**Architecture:** Additive on top of the existing starter. Existing global RBAC
(`roles`/`permissions`/`user_can()`) governs **admin-area** capabilities.
Collaboration features (workspaces/projects/tasks/files) are governed by
**membership + membership role** (owner/admin/member/viewer) — the standard
model (GitLab/Asana), which also gives clean per-record ownership scoping to
prevent IDOR. Groups are a management convenience: a named set of users that can
be bulk-added to a workspace/project.

**Tech stack:** PHP 8.3 · CI 4.7.4 · MySQL (Query Builder + `CodeIgniter\Model`,
soft deletes) · TailAdmin (Tailwind + Alpine + vanilla `fetch()`). No jQuery/Bootstrap.

## Global Constraints (apply to every task)

- **Language:** all code/comments/docs in **English**.
- **Controllers** extend `App\Controllers\BaseController`; JSON via
  `jsonSuccess()`/`jsonFailed()`; list limit via `resolveLimit()`.
- **Data access** through Models only (never raw SQL). Parameterised builder.
- **Routes** explicit in `app/Config/Routes.php`; auto-routing stays off. Reuse
  filters `authFilter`/`adminFilter`; new `apikey` filter for the REST API.
- **Escape** every dynamic output with `esc()`. AJAX mutations POST via
  `postForm()` + CSRF; controllers return `setJSON()` envelopes.
- **Session identity vs scope:** `session('userAuthenticated')` = identity/zone
  (authorization); `session('userWorking')` = data scope (use `->id` for
  ownership/membership checks so "act as" works). Helper: `current_user_id()`
  (add to `auth_helper.php`) returns `(int) (session('userWorking')->id ?? 0)`.
- **Views** extend `layouts/app`; reuse `.form-input/.form-select/.btn-*/.modal-panel`,
  shared toast/confirm/alert Alpine components. Mobile-first, responsive.
- **Migrations** guarded (`tableExists()` no-op if present); `down()` only drops
  on the `tests` DB group (copy the pattern from `CreateSiteVariable`).
- **Tests:** Feature tests through real routing/filters/CSRF using
  `WebAuthFixture` (`seedWebUser`/`loginAs`/`withCsrf`), `DatabaseTestTrait`
  (`$migrate=true; $refresh=true`). Model/unit tests in `tests/database`.
- Keep `vendor/bin/phpunit`, `php-cs-fixer`, `phpstan` green.

## Data Model (all tables InnoDB, utf8mb4; timestamps DATETIME null; soft-delete where noted)

Table names avoid MySQL 8 reserved words (hence `user_groups`, not `groups`).

1. **user_groups** (soft-delete): `id` INT UAI · `name` VARCHAR(150) · `slug`
   VARCHAR(160) UNIQUE · `description` TEXT null · `created_at/updated_at/deleted_at`.
2. **group_members**: `id` · `group_id` (→user_groups.id) · `user_id` · `created_at`.
   UNIQUE(`group_id`,`user_id`). Index(`user_id`).
3. **workspaces** (soft-delete): `id` · `name` VARCHAR(150) · `slug` VARCHAR(160)
   UNIQUE · `description` TEXT null · `owner_id` (users.id) · `status` VARCHAR(20)
   default 'active' · `visibility` VARCHAR(20) default 'private' (private/company)
   · timestamps.
4. **workspace_members**: `id` · `workspace_id` · `user_id` · `role` VARCHAR(20)
   default 'member' (owner/admin/member/viewer) · `created_at`.
   UNIQUE(`workspace_id`,`user_id`). Index(`user_id`).
5. **projects** (soft-delete): `id` · `workspace_id` · `name` VARCHAR(150) ·
   `slug` VARCHAR(160) · `description` TEXT null · `status` VARCHAR(20) default
   'active' · `color` VARCHAR(20) null · `owner_id` · timestamps.
   UNIQUE(`workspace_id`,`slug`). Index(`workspace_id`).
6. **project_members**: `id` · `project_id` · `user_id` · `role` VARCHAR(20)
   default 'member' · `created_at`. UNIQUE(`project_id`,`user_id`). Index(`user_id`).
7. **project_files** (soft-delete): `id` · `project_id` · `uploaded_by` ·
   `original_name` VARCHAR(255) · `stored_name` VARCHAR(255) · `mime` VARCHAR(150)
   · `size` BIGINT · `created_at` · `deleted_at`. Index(`project_id`).
   Files stored under `writable/uploads/projects/{project_id}/{stored_name}`.
8. **task_columns**: `id` · `project_id` · `name` VARCHAR(100) · `position` INT
   default 0 · `created_at`. Index(`project_id`). Seed To Do/In Progress/Done
   per project on creation.
9. **tasks** (soft-delete): `id` · `project_id` · `column_id` null · `title`
   VARCHAR(255) · `description` TEXT null · `priority` VARCHAR(20) default 'normal'
   (low/normal/high/urgent) · `assignee_id` null · `due_date` DATE null ·
   `position` INT default 0 · `created_by` · timestamps. Index(`project_id`),
   Index(`column_id`), Index(`assignee_id`).
10. **api_keys**: `id` · `user_id` · `name` VARCHAR(100) · `token_prefix`
    VARCHAR(16) UNIQUE · `token_hash` VARCHAR(255) · `scopes` VARCHAR(255) default
    'read' · `last_used_at` DATETIME null · `expires_at` DATETIME null ·
    `revoked_at` DATETIME null · `created_at`. Index(`user_id`).

## Access-control helper (add to a new `App\Libraries\Access` or `access_helper.php`)

- `workspace_role(int $workspaceId, ?int $userId=null): ?string` — the caller's
  role in a workspace, or null if not a member. Global-zone admins are treated as
  implicit 'owner'.
- `project_role(int $projectId, ?int $userId=null): ?string` — caller's role in a
  project; falls back to workspace role. Null if no access.
- `can_edit_project(...)`, `can_manage_workspace(...)` convenience booleans.
- Controllers MUST call these and 403 (`jsonFailed('...',403)` or redirect) when
  access is missing. This is the IDOR guard — never trust a path id alone.

## Build order (each = one plan file, each ships working + tested software)

- **01 schema-and-models** — all migrations + models + access helper + seed perms.
- **02 groups-admin** — admin CRUD for user groups + membership.
- **03 workspaces** — user-created workspaces, members, join/leave, group-add.
- **04 projects-and-files** — projects in a workspace, members, file manager.
- **05 kanban-tasks** — board columns + task CRUD + drag/drop + list view.
- **06 api-keys** — personal API key management (account area).
- **07 rest-api** — Bearer-auth `/api/v1` for me/projects/tasks (AI agents).
- **08 dashboards** — company (admin) + personal (client) dashboards.
- **09 docs** — in-app docs: connect guide + REST API reference.

## Navigation additions (`app/Views/layouts/app.php`)

Client zone nav groups: **Workspaces** (Workspaces, My Tasks), plus Account.
Admin zone adds **Groups** under the Admin group, and **Docs** top-level link in
both zones. API Keys lives under Account.
