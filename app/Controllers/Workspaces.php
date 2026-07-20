<?php

namespace App\Controllers;

use App\Models\GroupMemberModel;
use App\Models\GroupModel;
use App\Models\ProjectModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * User-created workspaces (`workspaces` + `workspace_members`): create/join/
 * leave, and owner/admin membership management (incl. bulk-add a whole
 * `user_groups` group). Gated by `authFilter` at the route (any signed-in
 * user may create/join) plus per-workspace `workspace_role()`/
 * `can_manage_workspace()` from `access_helper.php` — the IDOR guard, since a
 * path id alone never proves access. See
 * `kanban/processing/20260721-03-workspaces.md`.
 */
class Workspaces extends BaseController
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
     * "Mine" = every workspace the user owns, is a member of, or that's
     * company-wide (`WorkspaceModel::forUser()`); "discoverable" = the
     * company-wide ones from that set they haven't joined yet, for a
     * separate Join call-to-action.
     */
    public function index(): string
    {
        $userId = current_user_id();

        return view('workspaces/index', [
            'mine'         => model(WorkspaceModel::class)->forUser($userId),
            'discoverable' => model(WorkspaceModel::class)->discoverableFor($userId),
        ]);
    }

    /**
     * Creates a workspace and seeds the creator as its `owner` member.
     */
    public function create(): ResponseInterface
    {
        if (! $this->validate($this->workspaceValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $ownerId     = current_user_id();
        $description = trim((string) $this->request->getPost('description'));

        $id = model(WorkspaceModel::class)->createWorkspace(
            trim((string) $this->request->getPost('name')),
            $ownerId,
            $description !== '' ? $description : null,
            $this->normalizedVisibility(),
        );

        model(WorkspaceMemberModel::class)->add($id, $ownerId, 'owner');

        return $this->jsonSuccess('Workspace has been created.', ['id' => $id]);
    }

    /**
     * Overview + members + projects. Redirects non-members back to the
     * index instead of exposing a 403 page — a path id alone never proves
     * access, so this is the IDOR guard for the whole workspace.
     */
    public function show(int $id): ResponseInterface|string
    {
        $role = workspace_role($id);

        if ($role === null) {
            return redirect()->to('/workspaces');
        }

        $workspace = model(WorkspaceModel::class)->findWithMeta($id);

        if (! $workspace) {
            return redirect()->to('/workspaces');
        }

        return view('workspaces/show', [
            'workspace'       => $workspace,
            'role'            => $role,
            'members'         => model(WorkspaceMemberModel::class)->membersOf($id),
            'canManage'       => can_manage_workspace($id),
            'availableUsers'  => model(UserModel::class)->activeUsersNotInWorkspace($id),
            'availableGroups' => model(GroupModel::class)->findAll(),
            'projects'        => model(ProjectModel::class)->forWorkspace($id),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        $workspaceModel = model(WorkspaceModel::class);

        if (! $workspaceModel->findWorkspace($id)) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if (! can_manage_workspace($id)) {
            return $this->jsonFailed('You do not have permission to manage this workspace.', 403);
        }

        if (! $this->validate($this->workspaceValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $description = trim((string) $this->request->getPost('description'));

        $workspaceModel->update($id, [
            'name'        => trim((string) $this->request->getPost('name')),
            'description' => $description !== '' ? $description : null,
            'visibility'  => $this->normalizedVisibility(),
        ]);

        return $this->jsonSuccess('Workspace has been updated.');
    }

    /**
     * Soft-deletes the workspace. Owner-only (stricter than the usual
     * owner/admin `can_manage_workspace()` gate) — projects/tasks under it
     * are left in place but become unreachable once the workspace is
     * deleted (feature 04 guards on the workspace not being soft-deleted).
     */
    public function delete(int $id): ResponseInterface
    {
        $workspaceModel = model(WorkspaceModel::class);

        if (! $workspaceModel->findWorkspace($id)) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if (workspace_role($id) !== 'owner') {
            return $this->jsonFailed('Only the owner can delete this workspace.', 403);
        }

        $workspaceModel->delete($id);

        return $this->jsonSuccess('Workspace has been deleted.');
    }

    /**
     * Self-service join — only for `visibility='company'` workspaces.
     */
    public function join(int $id): ResponseInterface
    {
        $workspace = model(WorkspaceModel::class)->findWorkspace($id);

        if (! $workspace) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if ($workspace->visibility !== 'company') {
            return $this->jsonFailed('This workspace is private; ask an owner or admin to add you.', 403);
        }

        $userId      = current_user_id();
        $memberModel = model(WorkspaceMemberModel::class);

        if ($memberModel->roleFor($id, $userId) !== null) {
            return $this->jsonFailed('You are already a member of this workspace.');
        }

        $memberModel->add($id, $userId, 'member');

        return $this->jsonSuccess('You have joined the workspace.');
    }

    /**
     * Self-service leave. Blocked when the caller is the workspace's sole
     * `owner` — every workspace must keep at least one owner able to manage
     * it.
     */
    public function leave(int $id): ResponseInterface
    {
        $userId      = current_user_id();
        $memberModel = model(WorkspaceMemberModel::class);
        $role        = $memberModel->roleFor($id, $userId);

        if ($role === null) {
            return $this->jsonFailed('You are not a member of this workspace.', 404);
        }

        if ($role === 'owner' && $this->ownerCount($id) <= 1) {
            return $this->jsonFailed('You are the only owner; promote another member first.');
        }

        $memberModel->remove($id, $userId);

        return $this->jsonSuccess('You have left the workspace.');
    }

    /**
     * Manager-only add — dedups against an existing membership row (see
     * `WorkspaceMemberModel::add()`), so re-adding an existing member is a
     * no-op rather than a role change.
     */
    public function addMember(int $id): ResponseInterface
    {
        if (! model(WorkspaceModel::class)->findWorkspace($id)) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if (! can_manage_workspace($id)) {
            return $this->jsonFailed('You do not have permission to manage this workspace.', 403);
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

        model(WorkspaceMemberModel::class)->add($id, $userId, (string) $this->request->getPost('role'));

        return $this->jsonSuccess('Member added.');
    }

    /**
     * Manager-only remove. Blocked when the target is the workspace's sole
     * `owner` — mirrors the `leave()` guard so a manager can't strand a
     * workspace ownerless by removing someone else either.
     */
    public function removeMember(int $id): ResponseInterface
    {
        if (! model(WorkspaceModel::class)->findWorkspace($id)) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if (! can_manage_workspace($id)) {
            return $this->jsonFailed('You do not have permission to manage this workspace.', 403);
        }

        if (! $this->validate(['user_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $userId      = (int) $this->request->getPost('user_id');
        $memberModel = model(WorkspaceMemberModel::class);

        if ($memberModel->roleFor($id, $userId) === 'owner' && $this->ownerCount($id) <= 1) {
            return $this->jsonFailed('Cannot remove the only owner.');
        }

        $memberModel->remove($id, $userId);

        return $this->jsonSuccess('Member removed.');
    }

    /**
     * Manager-only bulk-add: every current member of `group_id` joins as
     * `role='member'`, deduped against existing membership. Returns the
     * number actually added (excludes already-members) for the toast.
     */
    public function addGroup(int $id): ResponseInterface
    {
        if (! model(WorkspaceModel::class)->findWorkspace($id)) {
            return $this->jsonFailed('Workspace not found.', 404);
        }

        if (! can_manage_workspace($id)) {
            return $this->jsonFailed('You do not have permission to manage this workspace.', 403);
        }

        if (! $this->validate(['group_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $groupId = (int) $this->request->getPost('group_id');

        if (! model(GroupModel::class)->findGroup($groupId)) {
            return $this->jsonFailed('Group not found.', 404);
        }

        $memberModel = model(WorkspaceMemberModel::class);
        $added       = 0;

        foreach (model(GroupMemberModel::class)->membersOf($groupId) as $user) {
            if ($memberModel->roleFor($id, (int) $user->id) === null) {
                $memberModel->add($id, (int) $user->id, 'member');
                $added++;
            }
        }

        return $this->jsonSuccess('Added ' . $added . ' member(s).', ['added' => $added]);
    }

    /**
     * How many `owner`-role members a workspace currently has — backs the
     * "sole owner can't leave/be removed" guard shared by `leave()` and
     * `removeMember()`.
     */
    private function ownerCount(int $workspaceId): int
    {
        return model(WorkspaceMemberModel::class)
            ->where('workspace_id', $workspaceId)
            ->where('role', 'owner')
            ->countAllResults();
    }

    /**
     * `visibility` posted by create()/update(), constrained to the
     * allowlist — anything else (including empty) falls back to 'private',
     * the table's own default.
     */
    private function normalizedVisibility(): string
    {
        $visibility = (string) $this->request->getPost('visibility');

        return $visibility === 'company' ? 'company' : 'private';
    }

    /**
     * @return array<string, string>
     */
    private function workspaceValidationRules(): array
    {
        return [
            'name'        => 'required|max_length[150]',
            'description' => 'permit_empty',
            'visibility'  => 'permit_empty|in_list[private,company]',
        ];
    }
}
