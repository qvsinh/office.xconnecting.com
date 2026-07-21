# 01 — Schema & Models (foundation)

**Goal:** Create every migration, model, and the access-control helper the
collaboration features build on. Ships as: `php spark migrate` succeeds and all
model/helper tests pass. See `20260721-00-master-social-hub.md` for the schema.

## Files

- Create migrations under `app/Database/Migrations/` (dated `2026-07-21-0N0000_*`):
  `CreateUserGroups`, `CreateGroupMembers`, `CreateWorkspaces`,
  `CreateWorkspaceMembers`, `CreateProjects`, `CreateProjectMembers`,
  `CreateProjectFiles`, `CreateTaskColumns`, `CreateTasks`, `CreateApiKeys`.
  Each guarded (`tableExists()` early-return) + tests-only `down()` — copy the
  exact pattern from `2026-07-14-120000_CreateSiteVariable.php`.
- Create models under `app/Models/`: `GroupModel`, `GroupMemberModel`,
  `WorkspaceModel`, `WorkspaceMemberModel`, `ProjectModel`, `ProjectMemberModel`,
  `ProjectFileModel`, `TaskColumnModel`, `TaskModel`, `ApiKeyModel`.
- Modify `app/Helpers/auth_helper.php`: add `current_user_id()`.
- Create `app/Helpers/access_helper.php`: `workspace_role()`, `project_role()`,
  `can_manage_workspace()`, `can_edit_project()`.
- Modify `app/Config/Autoload.php` if helpers need autoloading (they don't —
  loaded per-controller via `helper()`).
- Tests: `tests/database/{GroupModelTest,WorkspaceModelTest,ProjectModelTest,
  TaskModelTest,ApiKeyModelTest,AccessHelperTest}.php`.

## Model conventions (match UserModel)

- `returnType = 'object'`, `useTimestamps = true` (createdField/updatedField
  set) EXCEPT join tables (`group_members`, `*_members`) which only have
  `created_at` → set `useTimestamps=false` and stamp manually, or set only
  `createdField` with `updatedField` absent. Simplest: join/link models use
  `useTimestamps=false`.
- Soft-delete models (`user_groups`, `workspaces`, `projects`, `project_files`,
  `tasks`): `useSoftDeletes=true`, `deletedField='deleted_at'`.
- `allowedFields` explicit (never include `id`).

### Key model methods (implement only what features 02–09 consume)

**WorkspaceModel**
- `forUser(int $userId): array` — workspaces the user owns or is a member of
  (join `workspace_members`), plus `visibility='company'` ones. Distinct, newest
  first. Returns rows incl. `member_count` via subquery or separate count.
- `findWithMeta(int $id): ?object`.

**WorkspaceMemberModel**
- `roleFor(int $workspaceId, int $userId): ?string`
- `membersOf(int $workspaceId): array` (join users: id,name,email,role)
- `add(int $workspaceId, int $userId, string $role='member'): void` (ignore dup)
- `remove(int $workspaceId, int $userId): void`

**ProjectModel**
- `forWorkspace(int $workspaceId): array`
- `forUser(int $userId): array` — projects where user is a project member OR a
  member of the parent workspace.
- `findInWorkspace(int $id, int $workspaceId): ?object`

**ProjectMemberModel** — same shape as WorkspaceMemberModel.

**TaskColumnModel**
- `forProject(int $projectId): array` (ordered by position)
- `seedDefaults(int $projectId): void` — inserts To Do(0)/In Progress(1)/Done(2).

**TaskModel**
- `forProject(int $projectId): array` (with assignee name via join; ordered
  column_id, position)
- `forAssignee(int $userId): array` (open tasks assigned to a user, newest/ due)
- `reorder(int $taskId, int $columnId, int $position): void`
- `countByPriority(int $projectId): array`

**ApiKeyModel**
- `forUser(int $userId): array` (never selects `token_hash`)
- `createForUser(int $userId, string $name, string $scopes, ?string $expiresAt): array`
  — generates token, returns `['plain'=>..., 'row'=>...]`. Token = `sk_` .
  bin2hex(random_bytes(20)); `token_prefix` = first 12 chars (`sk_`+8 hex);
  `token_hash` = `hash('sha256',$plain)`. Store prefix+hash only.
- `resolve(string $plain): ?object` — prefix lookup, `hash_equals` on sha256,
  reject when `revoked_at` set or `expires_at` in the past; on hit stamp
  `last_used_at`. Returns the api_key row (with user_id) or null.
- `revoke(int $id, int $userId): bool`

**GroupModel / GroupMemberModel** — CRUD + `membersOf`/`add`/`remove` + a
`groupsForUser`. Slug from name via `url_title($name,'-',true)` (url helper),
de-duplicated with a numeric suffix.

## access_helper.php

```php
function workspace_role(int $workspaceId, ?int $userId = null): ?string {
    if (is_admin()) return 'owner';                 // global zone = implicit owner
    $userId ??= current_user_id();
    return model(WorkspaceMemberModel::class)->roleFor($workspaceId, $userId);
}
function project_role(int $projectId, ?int $userId = null): ?string {
    if (is_admin()) return 'owner';
    $userId ??= current_user_id();
    $direct = model(ProjectMemberModel::class)->roleFor($projectId, $userId);
    if ($direct !== null) return $direct;
    $project = model(ProjectModel::class)->find($projectId);
    return $project ? workspace_role((int) $project->workspace_id, $userId) : null;
}
function can_manage_workspace(int $wid, ?int $uid = null): bool {
    return in_array(workspace_role($wid, $uid), ['owner','admin'], true);
}
function can_edit_project(int $pid, ?int $uid = null): bool {
    return in_array(project_role($pid, $uid), ['owner','admin','member'], true);
}
```

## Steps (TDD)

1. Write migration files. Run `php spark migrate` → all applied.
2. Write models. Write `tests/database/*ModelTest.php` for CRUD + the key
   methods above (RED → implement → GREEN). Cover: workspace membership scoping,
   `ProjectModel::forUser`, `ApiKeyModel::resolve` (valid/revoked/expired/wrong),
   `TaskColumnModel::seedDefaults`.
3. Write `AccessHelperTest`: member sees role, non-member gets null, admin gets
   'owner', project falls back to workspace role.
4. `RUNNING_MODE=testing vendor/bin/phpunit --filter 'Model|AccessHelper'` green.
5. `php-cs-fixer fix` + `phpstan analyse` clean. Commit.
