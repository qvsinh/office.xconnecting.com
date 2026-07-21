<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;

/**
 * Access control for collaboration features (workspaces/projects/tasks),
 * layered on top of membership + membership role (owner/admin/member/
 * viewer) — separate from the global `is_admin()`/`user_can()` RBAC gate,
 * which governs the admin area. See
 * `kanban/processing/20260721-00-master-social-hub.md` §Access-control
 * helper. Controllers MUST call these and reject (403/redirect) when access
 * is missing — this is the IDOR guard, never trust a path id alone.
 */
if (! function_exists('workspace_role')) {
    /**
     * The caller's role in a workspace, or null if not a member. Global-zone
     * admins are treated as an implicit 'owner' of every workspace.
     */
    function workspace_role(int $workspaceId, ?int $userId = null): ?string
    {
        helper('auth');

        // A soft-deleted (or non-existent) workspace has no roles — deny
        // everyone, including global-zone admins, so no caller (web or API)
        // can act on a deleted parent. `find()` excludes soft-deleted rows.
        if (! is_object(model(WorkspaceModel::class)->find($workspaceId))) {
            return null;
        }

        if (is_admin()) {
            return 'owner';
        }

        $userId ??= current_user_id();

        return model(WorkspaceMemberModel::class)->roleFor($workspaceId, $userId);
    }
}

if (! function_exists('project_role')) {
    /**
     * The caller's role in a project; falls back to their role in the
     * project's parent workspace when they're not a direct project member.
     * Null if they have no access through either path.
     */
    function project_role(int $projectId, ?int $userId = null): ?string
    {
        helper('auth');

        // Load the project first: a soft-deleted/non-existent project has no
        // roles (this also stops callers acting on or writing tasks into a
        // deleted project — the API relies on this to avoid a 500). `find()`
        // excludes soft-deleted rows.
        $project = model(ProjectModel::class)->find($projectId);
        if (! is_object($project)) {
            return null;
        }

        // The parent workspace must also be alive — a project under a
        // soft-deleted workspace is unreachable, even for a direct member.
        if (! is_object(model(WorkspaceModel::class)->find((int) $project->workspace_id))) {
            return null;
        }

        if (is_admin()) {
            return 'owner';
        }

        $userId ??= current_user_id();

        $direct = model(ProjectMemberModel::class)->roleFor($projectId, $userId);
        if ($direct !== null) {
            return $direct;
        }

        return workspace_role((int) $project->workspace_id, $userId);
    }
}

if (! function_exists('can_manage_workspace')) {
    function can_manage_workspace(int $workspaceId, ?int $userId = null): bool
    {
        return in_array(workspace_role($workspaceId, $userId), ['owner', 'admin'], true);
    }
}

if (! function_exists('can_edit_project')) {
    function can_edit_project(int $projectId, ?int $userId = null): bool
    {
        return in_array(project_role($projectId, $userId), ['owner', 'admin', 'member'], true);
    }
}
