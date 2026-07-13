<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Self-service API access key management, strictly scoped to the current
 * session user. Every read/mutation is derived from `session('userWorking')`
 * — the owning `user_id` is never accepted from request input, and every
 * mutation fetches the target row by `id` AND `user_id` first (no IDOR).
 */
class ApiAccessKeys extends BaseController
{
    public function index(): string
    {
        helper('service_config');

        $me = session('userWorking');

        return view('client/api_access_keys/index', [
            'items'    => model(ApiAccessKeyModel::class)->listForUser((int) $me->id),
            'services' => list_api_services(),
            'maxKeys'  => ApiAccessKeyModel::MAX_KEYS_PER_USER,
        ]);
    }

    public function create(): ResponseInterface
    {
        helper('service_config');

        if (! $this->validate(['service_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $serviceId = (int) $this->request->getPost('service_id');

        if (! in_array($serviceId, array_column(list_api_services(), 'id'), true)) {
            return $this->jsonFailed('Unknown service.');
        }

        try {
            $accessKey = model(ApiAccessKeyModel::class)->createForUser(session('userWorking'), $serviceId);
        } catch (RuntimeException $e) {
            return $this->jsonFailed($e->getMessage());
        }

        return $this->jsonSuccess('Your new API access key has been created.', ['access_key' => $accessKey]);
    }

    public function toggle(): ResponseInterface
    {
        return $this->toggleField('is_enable');
    }

    public function toggleLog(): ResponseInterface
    {
        return $this->toggleField('enable_log');
    }

    public function remove(): ResponseInterface
    {
        $key = $this->ownedKeyOrNull();

        if (! $key) {
            return $this->jsonFailed('Record not found.', 404);
        }

        model(ApiAccessKeyModel::class)->delete($key->id);

        return $this->jsonSuccess('API access key has been deleted.');
    }

    private function toggleField(string $field): ResponseInterface
    {
        $value = (int) $this->request->getPost($field);

        if (! in_array($value, [0, 1], true)) {
            return $this->jsonFailed('Invalid request.');
        }

        $key = $this->ownedKeyOrNull();

        if (! $key) {
            return $this->jsonFailed('Record not found.', 404);
        }

        model(ApiAccessKeyModel::class)->update($key->id, [$field => $value]);

        return $this->jsonSuccess('Updated.');
    }

    /**
     * Fetch the key named by the `id` POST param, scoped to the session
     * user. Returns null (no existence leak) if it's missing OR belongs to
     * someone else — the two cases are indistinguishable to the caller.
     */
    private function ownedKeyOrNull(): ?object
    {
        $id = (int) $this->request->getPost('id');

        if ($id <= 0) {
            return null;
        }

        $me = session('userWorking');

        return model(ApiAccessKeyModel::class)
            ->where('id', $id)
            ->where('user_id', (int) $me->id)
            ->first();
    }
}
