<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\GroupMemberModel;
use App\Models\GroupModel;
use App\Models\UserModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin user-group management (`user_groups` + `group_members`): list/search/
 * paginate, create/edit/delete, and per-group membership. Gated by
 * `authFilter` + `adminFilter` at the route (see Config/Routes.php). Mirrors
 * `Admin\Settings` for CRUD, `Admin\Users::view()` for the not-found pattern.
 */
class Groups extends BaseController
{
    public function index(): string
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sort    = (string) $this->request->getGet('sort');
        $order   = (string) $this->request->getGet('order');
        $filters = [
            'keyword' => trim((string) $this->request->getGet('keyword')),
        ];

        $groupModel = model(GroupModel::class);

        return view('admin/groups/index', [
            'items'   => $groupModel->searchAdmin($filters, $sort !== '' ? $sort : 'name', $order !== '' ? $order : 'asc', $limit, $offset),
            'total'   => $groupModel->countAdmin($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sort'    => $sort,
            'order'   => $order,
            'filters' => $filters,
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->groupValidationRules(false))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $description = trim((string) $this->request->getPost('description'));

        model(GroupModel::class)->createGroup(
            trim((string) $this->request->getPost('name')),
            $description !== '' ? $description : null,
        );

        return $this->jsonSuccess('Group has been created successfully.');
    }

    /**
     * JSON group record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $group = model(GroupModel::class)->findGroup($id);

        if (! $group) {
            return $this->jsonFailed('Group not found.', 404);
        }

        return $this->jsonSuccess('OK', ['group' => [
            'id'          => (int) $group->id,
            'name'        => $group->name,
            'slug'        => $group->slug,
            'description' => $group->description,
        ]]);
    }

    public function update(): ResponseInterface
    {
        if (! $this->validate($this->groupValidationRules(true))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id         = (int) $this->request->getPost('id');
        $groupModel = model(GroupModel::class);

        if (! $groupModel->findGroup($id)) {
            return $this->jsonFailed('Group not found.', 404);
        }

        $description = trim((string) $this->request->getPost('description'));

        $groupModel->update($id, [
            'name'        => trim((string) $this->request->getPost('name')),
            'description' => $description !== '' ? $description : null,
        ]);

        return $this->jsonSuccess('Group has been updated successfully.');
    }

    /**
     * Soft-deletes the group and hard-deletes its `group_members` rows (that
     * table has no soft-delete column) so membership doesn't linger for a
     * group that no longer shows up anywhere.
     */
    public function delete(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id         = (int) $this->request->getPost('id');
        $groupModel = model(GroupModel::class);

        if (! $groupModel->findGroup($id)) {
            return $this->jsonFailed('Group not found.', 404);
        }

        $groupModel->delete($id);
        model(GroupMemberModel::class)->where('group_id', $id)->delete();

        return $this->jsonSuccess('Group has been deleted.');
    }

    /**
     * Members page: current members + a server-side picker of active users
     * not already in the group.
     */
    public function members(int $id): string
    {
        $group = model(GroupModel::class)->findGroup($id);

        if (! $group) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/groups/members', [
            'group'          => $group,
            'members'        => model(GroupMemberModel::class)->membersOf($id),
            'availableUsers' => model(UserModel::class)->activeUsersNotInGroup($id),
        ]);
    }

    /**
     * JSON member list (AJAX refresh after add/remove without a full reload).
     */
    public function membersData(int $id): ResponseInterface
    {
        $group = model(GroupModel::class)->findGroup($id);

        if (! $group) {
            return $this->jsonFailed('Group not found.', 404);
        }

        return $this->jsonSuccess('OK', ['members' => model(GroupMemberModel::class)->membersOf($id)]);
    }

    public function addMember(int $id): ResponseInterface
    {
        if (! $this->validate(['user_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $group = model(GroupModel::class)->findGroup($id);

        if (! $group) {
            return $this->jsonFailed('Group not found.', 404);
        }

        $userId = (int) $this->request->getPost('user_id');

        if (! model(UserModel::class)->findUser($userId)) {
            return $this->jsonFailed('User not found.', 404);
        }

        model(GroupMemberModel::class)->add($id, $userId);

        return $this->jsonSuccess('Member added.');
    }

    public function removeMember(int $id): ResponseInterface
    {
        if (! $this->validate(['user_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $group = model(GroupModel::class)->findGroup($id);

        if (! $group) {
            return $this->jsonFailed('Group not found.', 404);
        }

        model(GroupMemberModel::class)->remove($id, (int) $this->request->getPost('user_id'));

        return $this->jsonSuccess('Member removed.');
    }

    /**
     * `name` isn't required to be unique — `GroupModel::createGroup()`
     * derives a de-duplicated `slug` from it, so two groups can share a
     * display name (e.g. "Support") while staying distinct by slug.
     *
     * @return array<string, string>
     */
    private function groupValidationRules(bool $isUpdate): array
    {
        $rules = [
            'name'        => 'required|max_length[150]',
            'description' => 'permit_empty',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|is_natural_no_zero';
        }

        return $rules;
    }
}
