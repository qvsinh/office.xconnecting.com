<?php

namespace App\Controllers;

use App\Libraries\ApiConnectionTester;
use App\Models\ApiAccessKeyModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared API connection self-test flow behind both zones (admin/client):
 * resolves the target key row with the same ownership scoping as
 * `ConsoleController`, derives provider/env FROM THE RESOLVED KEY ROW
 * (never from request input), and runs the `History`-command loopback
 * probe (`App\Libraries\ApiConnectionTester`). Unlike `ConsoleController`
 * this isn't provider-specific — one controller covers CNR and JPRS keys
 * alike — so the zone split is only `requireOwnKey()`.
 */
abstract class ApiConnectionController extends BaseController
{
    protected $helpers = ['service_config'];

    /**
     * True for the client zone (caller may only test its own keys); false
     * for the admin zone (any key, identity is still always taken from the
     * row).
     */
    abstract protected function requireOwnKey(): bool;

    public function index(): string
    {
        $userId = $this->requireOwnKey() ? (int) session('userWorking')->id : null;

        return view('api_test/index', [
            'keys' => model(ApiAccessKeyModel::class)->listForConnectionTest($userId),
            'zone' => $this->requireOwnKey() ? 'client' : 'admin',
        ]);
    }

    public function run(): ResponseInterface
    {
        if (! $this->validate(['key_id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors(), 400);
        }

        $key = $this->resolveKey((int) $this->request->getPost('key_id'));

        if ($key === null) {
            return $this->jsonFailed('Record not found.', 404);
        }

        $provider = provider_of_service((int) $key->service_id);

        if ($provider === null) {
            return $this->jsonFailed('Record not found.', 404);
        }

        $env = (int) $key->user_status === 1 ? 'LIVE' : 'TESTING';

        // Probe runs regardless of is_enable/is_disable — surfacing the real
        // 401 a disabled/misconfigured key gets from ApiFilter IS the result.
        $result = (new ApiConnectionTester())->probe($key, $provider, $env);

        return $this->jsonSuccess('Connection test completed.', ['result' => $result]);
    }

    /**
     * Fetch the key named by `id`, scoped (for the client zone) to the
     * session user. Returns null — indistinguishable from "doesn't exist"
     * — when the id is missing or (client zone only) belongs to someone
     * else. No IDOR, no existence leak.
     */
    private function resolveKey(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $key = model(ApiAccessKeyModel::class)->findKey($id);

        if ($key === null) {
            return null;
        }

        if ($this->requireOwnKey() && (int) $key->user_id !== (int) session('userWorking')->id) {
            return null;
        }

        return $key;
    }
}
