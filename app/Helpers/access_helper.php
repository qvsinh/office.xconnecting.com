<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\WorkspaceMemberModel;

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

        if (is_admin()) {
            return 'owner';
        }

        $userId ??= current_user_id();

        $direct = model(ProjectMemberModel::class)->roleFor($projectId, $userId);
        if ($direct !== null) {
            return $direct;
        }

        $project = model(ProjectModel::class)->find($projectId);

        return is_object($project) ? workspace_role((int) $project->workspace_id, $userId) : null;
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
