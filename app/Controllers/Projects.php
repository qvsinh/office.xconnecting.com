<?php

namespace App\Controllers;

use App\Models\ProjectFileModel;
use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\UserModel;
use App\Models\WorkspaceModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects (`projects` + `project_members`) — sit inside a `workspaces` row.
 * Gated by `authFilter` at the route (any signed-in user may reach these
 * actions) plus per-project `project_role()`/`can_edit_project()` from
 * `access_helper.php` — the IDOR guard, since a path id alone never proves
 * access. See `kanban/processing/20260721-04-projects-and-files.md`.
 */
class Projects extends BaseController
{
    /**
     * Membership roles a manager may assign via `addMember()`.
     */
    private const ROLE_ALLOWLIST = ['owner', 'admin', 'member', 'viewer'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper(['auth', 'access']);
    }

    /**
     * Creates a project inside a workspace and seeds the creator as its
     * `owner` project member plus the default kanban columns.
     */
    public function create(int $workspaceId): ResponseInterface
    {
        if (! model(WorkspaceModel::class)->findWorkspace($workspaceId)) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if (! can_manage_workspace($workspaceId)) {
            return $this->jsonFailed('You do not have permission to manage this workspace.', 403);
        }

        if (! $this->validate($this->projectValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $ownerId     = current_user_id();
        $description = trim((string) $this->request->getPost('description'));
        $color       = trim((string) $this->request->getPost('color'));

        $id = model(ProjectModel::class)->createProject(
            $workspaceId,
            trim((string) $this->request->getPost('name')),
            $ownerId,
            $description !== '' ? $description : null,
            $color !== '' ? $color : null,
        );

        model(ProjectMemberModel::class)->add($id, $ownerId, 'owner');
        model(TaskColumnModel::class)->seedDefaults($id);

        return $this->jsonSuccess('Project has been created.', ['id' => $id]);
    }

    /**
     * Overview + members + files. Redirects non-members (and requests for a
     * project whose parent workspace has been soft-deleted) back to the
     * workspaces index instead of exposing a 403 page — a path id alone
     * never proves access, so this is the IDOR guard for the whole project.
     */
    public function show(int $id): ResponseInterface|string
    {
        $project = model(ProjectModel::class)->findProject($id);

        if (! $project) {
            return redirect()->to('/workspaces');
        }

        $workspace = model(WorkspaceModel::class)->findWorkspace((int) $project->workspace_id);

        if (! $workspace) {
            return redirect()->to('/workspaces');
        }

        $role = project_role($id);

        if ($role === null) {
            return redirect()->to('/workspaces');
        }

        return view('projects/show', [
            'project'        => $project,
            'workspace'      => $workspace,
            'role'           => $role,
            'canEdit'        => can_edit_project($id),
            'members'        => model(ProjectMemberModel::class)->membersOf($id),
            'availableUsers' => model(UserModel::class)->activeUsersNotInProject($id),
            'files'          => model(ProjectFileModel::class)->forProject($id),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        $projectModel = model(ProjectModel::class);

        if (! $projectModel->findProject($id)) {
            return $this->jsonFailed('Project not found.', 404);
        }

        if (! can_edit_project($id)) {
            return $this->jsonFailed('You do not have permission to edit this project.', 403);
        }

        if (! $this->validate(array_merge($this->projectValidationRules(), [
            'status' => 'permit_empty|in_list[active,archived]',
        ]))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $description = trim((string) $this->request->getPost('description'));
        $color       = trim((string) $this->request->getPost('color'));
        $status      = (string) $this->request->getPost('status');

        $projectModel->update($id, [
            'name'        => trim((string) $this->request->getPost('name')),
            'description' => $description !== '' ? $description : null,
            'color'       => $color !== '' ? $color : null,
            'status'      => in_array($status, ['active', 'archived'], true) ? $status : 'active',
        ]);

        return $this->jsonSuccess('Project has been updated.');
    }

    /**
     * Soft-deletes the project. Allowed for the project's own `owner` or
     * anyone who manages the parent workspace (owner/admin there) — either
     * path is a legitimate "responsible for this project" signal.
     */
    public function delete(int $id): ResponseInterface
    {
        $project = model(ProjectModel::class)->findProject($id);

        if (! $project) {
            return $this->jsonFailed('Project not found.', 404);
        }

        if (project_role($id) !== 'owner' && ! can_manage_workspace((int) $project->workspace_id)) {
            return $this->jsonFailed('You do not have permission to delete this project.', 403);
        }

        model(ProjectModel::class)->delete($id);

        return $this->jsonSuccess('Project has been deleted.');
    }

    /**
     * Project owner/admin-only add — dedups against an existing membership
     * row (see `ProjectMemberModel::add()`), so re-adding an existing member
     * is a no-op rather than a role change.
     */
    public function addMember(int $id): ResponseInterface
    {
        if (! model(ProjectModel::class)->findProject($id)) {
            return $this->jsonFailed('Project not found.', 404);
        }

        if (! in_array(project_role($id), ['owner', 'admin'], true)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s members.', 403);
        }

        if (! $this->validate([
            'user_id' => 'required|is_natural_no_zero',
            'role'    => 'required|in_list[' . implode(',', self::ROLE_ALLOWLIST) . ']',
        ])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $userId = (int) $this->request->getPost('user_id');

        if (! model(UserModel::class)->findUser($userId)) {
            return $this->jsonFailed('User not found.', 404);
        }

        model(ProjectMemberModel::class)->add($id, $userId, (string) $this->request->getPost('role'));

        return $this->jsonSuccess('Member added.');
    }

    /**
     * Project owner/admin-only remove. Blocked when the target is the
     * project's sole `owner` — mirrors `Workspaces::removeMember()` so a
     * manager can't strand a project ownerless.
     */
    public function removeMember(int $id): ResponseInterface
    {
        if (! model(ProjectModel::class)->findProject($id)) {
            return $this->jsonFailed('Project not found.', 404);
        }

        if (! in_array(project_role($id), ['owner', 'admin'], true)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s members.', 403);
        }

        if (! $this->validate(['user_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $userId      = (int) $this->request->getPost('user_id');
        $memberModel = model(ProjectMemberModel::class);

        if ($memberModel->roleFor($id, $userId) === 'owner' && $this->ownerCount($id) <= 1) {
            return $this->jsonFailed('Cannot remove the only owner.');
        }

        $memberModel->remove($id, $userId);

        return $this->jsonSuccess('Member removed.');
    }

    /**
     * How many `owner`-role members a project currently has — backs the
     * "sole owner can't be removed" guard in `removeMember()`.
     */
    private function ownerCount(int $projectId): int
    {
        return model(ProjectMemberModel::class)
            ->where('project_id', $projectId)
            ->where('role', 'owner')
            ->countAllResults();
    }

    /**
     * @return array<string, string>
     */
    private function projectValidationRules(): array
    {
        return [
            'name'        => 'required|max_length[150]',
            'description' => 'permit_empty',
            'color'       => 'permit_empty|max_length[20]',
        ];
    }
}
