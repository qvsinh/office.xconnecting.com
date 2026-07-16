<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin user management: list/search/paginate, create/edit, per-user admin
 * note, and act-as impersonation. Gated by `authFilter` + `adminFilter` at
 * the route (see Config/Routes.php). Sub-user delegation, delete, and
 * billing/2FA fields are later cards (kanban plan §2).
 */
class Users extends BaseController
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
            'zone'    => (string) $this->request->getGet('zone'),
            'status'  => (string) $this->request->getGet('status'),
        ];

        $userModel = model(UserModel::class);

        return view('admin/users/index', [
            'items'   => $userModel->searchAdmin($filters, $sort !== '' ? $sort : 'login_at', $order !== '' ? $order : 'desc', $limit, $offset),
            'total'   => $userModel->countAdmin($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sort'    => $sort,
            'order'   => $order,
            'filters' => $filters,
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->userValidationRules(false))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $password = (string) $this->request->getPost('password');
        // ponytail: admin left password blank -> generate a random one instead of
        // blocking creation; never surfaced back to the client or logged.
        if ($password === '') {
            $password = bin2hex(random_bytes(8));
        }

        $zone = (string) $this->request->getPost('zone');

        model(UserModel::class)->insert([
            'name'     => trim((string) $this->request->getPost('name')),
            'email'    => trim((string) $this->request->getPost('email')),
            'password' => $this->hashPassword($password),
            'zone'     => $zone,
            'role_id'  => $this->roleIdForZone($zone),
            'env'      => (string) $this->request->getPost('env'),
            'status'   => (int) $this->request->getPost('status'),
            'language' => trim((string) $this->request->getPost('language')) ?: 'en',
            'timezone' => trim((string) $this->request->getPost('timezone')) ?: 'Asia/Tokyo',
        ]);

        return $this->jsonSuccess('User has been created successfully.');
    }

    /**
     * JSON user record for the edit modal (AJAX-fetched), never including
     * `password`/`google2fa_secret`.
     */
    public function edit(int $id): ResponseInterface
    {
        $user = model(UserModel::class)->findUser($id);

        if (! $user) {
            return $this->jsonFailed('User not found.', 404);
        }

        return $this->jsonSuccess('OK', ['user' => [
            'id'       => (int) $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'company'  => $user->company ?? null,
            'zone'     => $user->zone,
            'env'      => $user->env,
            'status'   => (int) $user->status,
            'language' => $user->language,
            'timezone' => $user->timezone,
        ]]);
    }

    public function update(): ResponseInterface
    {
        if (! $this->validate($this->userValidationRules(true))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id        = (int) $this->request->getPost('id');
        $userModel = model(UserModel::class);
        $existing  = $userModel->findUser($id);

        if (! $existing) {
            return $this->jsonFailed('User not found.', 404);
        }

        $newZone   = (string) $this->request->getPost('zone');
        $newStatus = (int) $this->request->getPost('status');

        $guardMessage = $this->lockoutGuardMessage($existing, $newZone, $newStatus);
        if ($guardMessage !== null) {
            return $this->jsonFailed($guardMessage);
        }

        $data = [
            'name'     => trim((string) $this->request->getPost('name')),
            'email'    => trim((string) $this->request->getPost('email')),
            'zone'     => $newZone,
            'role_id'  => $this->roleIdForZone($newZone),
            'env'      => (string) $this->request->getPost('env'),
            'status'   => $newStatus,
            'language' => trim((string) $this->request->getPost('language')) ?: $existing->language,
            'timezone' => trim((string) $this->request->getPost('timezone')) ?: $existing->timezone,
        ];

        $password = (string) $this->request->getPost('password');
        if ($password !== '') {
            $data['password'] = $this->hashPassword($password);
        }

        $userModel->update($id, $data);

        return $this->jsonSuccess('User has been updated successfully.');
    }

    public function view(int $id): string
    {
        $user = model(UserModel::class)->findUser($id);

        if (! $user) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/users/detail', ['user' => $user]);
    }

    public function note(int $id): string
    {
        $user = model(UserModel::class)->findUser($id);

        if (! $user) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/users/note', ['user' => $user]);
    }

    public function updateNote(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id        = (int) $this->request->getPost('id');
        $userModel = model(UserModel::class);

        if (! $userModel->findUser($id)) {
            return $this->jsonFailed('User not found.', 404);
        }

        $userModel->update($id, ['note' => trim((string) $this->request->getPost('note'))]);

        return $this->jsonSuccess('Note has been saved.');
    }

    /**
     * SECURITY CRITICAL: sets only `userWorking` to the target user; the
     * real admin's `userAuthenticated` is never touched, so authorization
     * (`is_admin()`) stays anchored to the acting admin while every
     * client-scoped controller (reading `userWorking`) renders the target's
     * data. Redirects into the client-scoped dashboard.
     */
    public function actAs(int $id): ResponseInterface
    {
        $adminUser = session('userAuthenticated');
        $target    = model(UserModel::class)->findUser($id);

        if (! $target) {
            return $this->jsonFailed('User not found.', 404);
        }

        if ((int) $target->id === (int) $adminUser->id) {
            return redirect()->to('/dashboard');
        }

        session()->set('userWorking', $target);

        log_message('info', 'Admin #{adminId} is now acting as user #{targetId}.', [
            'adminId'  => $adminUser->id,
            'targetId' => $target->id,
        ]);

        return redirect()->to('/dashboard');
    }

    /**
     * Restores `userWorking = userAuthenticated`. Idempotent — safe to call
     * when not currently impersonating.
     */
    public function stopActing(): ResponseInterface
    {
        session()->set('userWorking', session('userAuthenticated'));

        return redirect()->to('/admin/users');
    }

    /**
     * @return array<string, string>
     */
    private function userValidationRules(bool $isUpdate): array
    {
        $emailRule = $isUpdate
            ? 'required|valid_email|max_length[255]|is_unique[users.email,id,{id}]'
            : 'required|valid_email|max_length[255]|is_unique[users.email]';

        $rules = [
            'name'     => 'required|max_length[255]',
            'email'    => $emailRule,
            'zone'     => 'required|in_list[global,client]',
            'env'      => 'required|in_list[LIVE,TESTING]',
            'status'   => 'permit_empty|in_list[0,1]',
            'language' => 'permit_empty|max_length[5]',
            'timezone' => 'permit_empty|max_length[30]',
            'password' => 'permit_empty|min_length[5]',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|is_natural_no_zero';
        }

        return $rules;
    }

    /**
     * Two independent guards, both checked before any write:
     * - an admin can never demote (`zone !== global`) or deactivate
     *   (`status = 0`) their own account, regardless of how many other
     *   admins exist;
     * - the last remaining active `zone=global` admin can never be demoted
     *   or deactivated by anyone, or every admin session would lock out.
     *
     * Returns a user-facing failure message, or null when the change is safe.
     */
    private function lockoutGuardMessage(object $existing, string $newZone, int $newStatus): ?string
    {
        $wasActiveAdmin = $existing->zone === 'global' && (int) $existing->status === 1;
        $willLoseAdmin  = $newZone !== 'global' || $newStatus !== 1;

        if (! $wasActiveAdmin || ! $willLoseAdmin) {
            return null;
        }

        $authUser = session('userAuthenticated');
        if ($authUser !== null && (int) $authUser->id === (int) $existing->id) {
            return 'You cannot demote or deactivate your own admin account.';
        }

        if (model(UserModel::class)->countActiveAdmins() <= 1) {
            return 'You cannot demote or deactivate the last remaining active admin.';
        }

        return null;
    }

    private function hashPassword(string $plain): string
    {
        $authConfig = config('Auth');

        return hash($authConfig->password_algorithm, $plain . $authConfig->password_salt);
    }

    /**
     * Default role for a user given its `zone`: `global` -> the `admin`
     * role, anything else -> the `user` role. Null-safe: returns null (no
     * role assigned) if the corresponding role hasn't been seeded yet,
     * rather than failing the create/update.
     */
    private function roleIdForZone(string $zone): ?int
    {
        $roleName = $zone === 'global' ? 'admin' : 'user';
        $role     = model(RoleModel::class)->findByName($roleName);

        return $role->id ?? null;
    }
}
