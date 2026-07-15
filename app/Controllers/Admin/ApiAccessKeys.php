<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Admin CRUD over every `users_api_access_keys` row. Gated by
 * `authFilter` + `adminFilter` at the route (see Config/Routes.php).
 */
class ApiAccessKeys extends BaseController
{
    public function index(): string
    {
        helper('service_config');

        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $keyword = trim((string) $this->request->getGet('keyword'));
        $filters = ['keyword' => $keyword];

        $keyModel = model(ApiAccessKeyModel::class);

        return view('admin/api_access_keys/index', [
            'items'    => $keyModel->searchAdmin($filters, $limit, $offset),
            'total'    => $keyModel->countAdmin($filters),
            'page'     => $page,
            'limit'    => $limit,
            'keyword'  => $keyword,
            'users'    => model(UserModel::class)->select('id, name, email')->orderBy('name', 'ASC')->findAll(),
            'services' => list_api_services(),
        ]);
    }

    public function create(): ResponseInterface
    {
        helper('service_config');

        if (! $this->validate([
            'user_id'    => 'required|is_natural_no_zero',
            'service_id' => 'required|is_natural_no_zero',
        ])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $serviceId = (int) $this->request->getPost('service_id');

        if (! $this->isKnownService($serviceId)) {
            return $this->jsonFailed('Unknown service.');
        }

        $user = model(UserModel::class)->find((int) $this->request->getPost('user_id'));

        if (! $user) {
            return $this->jsonFailed('User not found.', 404);
        }

        try {
            $accessKey = model(ApiAccessKeyModel::class)->createForUser(
                $user,
                $serviceId,
                trim((string) $this->request->getPost('description')),
            );
        } catch (RuntimeException $e) {
            return $this->jsonFailed($e->getMessage());
        }

        return $this->jsonSuccess('New API access key has been created successfully.', ['access_key' => $accessKey]);
    }

    /**
     * Note: `user_id` is intentionally NOT accepted here. The minted token
     * still decodes to the user it was created for, and `ApiFilter`
     * cross-checks the stored `user_id` against `decoded[0]` — reassigning
     * the row's `user_id` would silently break the key. Delete + recreate
     * instead (kanban plan §7 open question, resolved this way).
     */
    public function update(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id       = (int) $this->request->getPost('id');
        $keyModel = model(ApiAccessKeyModel::class);
        $existing = $keyModel->findKey($id);

        if (! $existing) {
            return $this->jsonFailed('Record not found.', 404);
        }

        // service_id is deliberately NOT updatable: it is encoded into the
        // Hashids access token at creation, and ApiFilter rejects the key
        // when the row's service_id no longer matches the token. Changing it
        // here would silently break the key (every call would 401). To point
        // at a different service, create a new key.
        $keyModel->update($id, [
            'description' => trim((string) $this->request->getPost('description')),
            'is_enable'   => $this->resolveFlag($this->request->getPost('is_enable'), (int) $existing->is_enable),
            'enable_log'  => $this->resolveFlag($this->request->getPost('enable_log'), (int) $existing->enable_log),
        ]);

        return $this->jsonSuccess('API access key has been updated successfully.');
    }

    public function toggle(): ResponseInterface
    {
        return $this->toggleField('is_enable');
    }

    public function toggleLog(): ResponseInterface
    {
        return $this->toggleField('enable_log');
    }

    public function delete(): ResponseInterface
    {
        $id = (int) $this->request->getPost('id');

        if ($id <= 0) {
            return $this->jsonFailed('Invalid ID.');
        }

        $keyModel = model(ApiAccessKeyModel::class);

        if (! $keyModel->find($id)) {
            return $this->jsonFailed('Record not found.', 404);
        }

        $keyModel->delete($id);

        return $this->jsonSuccess('API access key has been deleted.');
    }

    private function toggleField(string $field): ResponseInterface
    {
        $id    = (int) $this->request->getPost('id');
        $value = (int) $this->request->getPost($field);

        if ($id <= 0 || ! in_array($value, [0, 1], true)) {
            return $this->jsonFailed('Invalid request.');
        }

        $keyModel = model(ApiAccessKeyModel::class);

        if (! $keyModel->find($id)) {
            return $this->jsonFailed('Record not found.', 404);
        }

        $keyModel->update($id, [$field => $value]);

        return $this->jsonSuccess('Updated.');
    }

    /**
     * `is_enable`/`enable_log` are explicit 0/1 values on update (unlike an
     * HTML checkbox's presence-only convention) so a field left out of the
     * payload leaves the existing flag untouched.
     */
    private function resolveFlag(mixed $postValue, int $existingValue): int
    {
        if ($postValue === null) {
            return $existingValue;
        }

        return (int) $postValue === 1 ? 1 : 0;
    }

    private function isKnownService(int $serviceId): bool
    {
        return in_array($serviceId, array_column(list_api_services(), 'id'), true);
    }
}
