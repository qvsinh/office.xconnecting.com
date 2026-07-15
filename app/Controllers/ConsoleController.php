<?php

namespace App\Controllers;

use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared web-console `run()` flow behind both provider consoles (CNR/JPRS)
 * and both zones (admin/client): resolves the target key row, verifies
 * ownership + enabled state + provider match, derives `env`/`service_id`/
 * `user_id` FROM THE RESOLVED KEY ROW (never from request input), and builds
 * the same success/error envelope the Bearer proxy returns
 * (`{status, api_service_id, data|message}`) so the console UI can reuse the
 * proxy's response viewer. The per-provider upstream call itself lives in
 * `CnrConsoleController` / `JprsConsoleController`; the admin/client split
 * is only `requireOwnKey()`.
 */
abstract class ConsoleController extends BaseController
{
    protected $helpers = ['service_config', 'console_commands'];

    abstract protected function provider(): string;

    /**
     * True for the client zone (caller may only run its own keys); false for
     * the admin zone (any key, identity is still always taken from the row).
     */
    abstract protected function requireOwnKey(): bool;

    /**
     * @param array<string, mixed> $params
     *
     * @return array{success: bool, data: mixed, message: ?string, http_status?: int}
     */
    abstract protected function callUpstream(object $key, string $env, string $command, array $params, int $userId): array;

    /**
     * CNR overrides this for the `dnp.sg`/`ModifyDomain` restriction; JPRS
     * has none.
     */
    protected function domainRestrictionMessage(string $command, array $params): ?string
    {
        return null;
    }

    public function run(): ResponseInterface
    {
        if (! $this->validate(['key_id' => 'required|is_natural_no_zero'])) {
            return $this->errorEnvelope(null, $this->validator->getErrors(), 400);
        }

        $key = $this->resolveKey((int) $this->request->getPost('key_id'));

        if ($key === null) {
            return $this->errorEnvelope(null, 'Record not found.', 404);
        }

        if ((int) $key->is_enable !== 1) {
            return $this->errorEnvelope($key, 'This API access key is disabled.', 400);
        }

        $command = trim((string) $this->request->getPost('command'));

        if ($command === '') {
            return $this->errorEnvelope($key, 'Command parameter is missing.', 400);
        }

        $rawParams = $this->request->getPost('params');
        $params    = is_array($rawParams) ? $rawParams : [];

        if (strcasecmp($command, 'History') === 0) {
            return $this->historyEnvelope($key, $params);
        }

        $restriction = $this->domainRestrictionMessage($command, $params);

        if ($restriction !== null) {
            return $this->errorEnvelope($key, $restriction, 403);
        }

        $env    = (int) $key->user_status === 1 ? 'LIVE' : 'TESTING';
        $result = $this->callUpstream($key, $env, $command, $params, (int) $key->user_id);

        if ($result['success']) {
            return $this->successEnvelope($key, $result['data']);
        }

        return $this->errorEnvelope($key, $result['message'] ?? 'An error occurred', $result['http_status'] ?? 200, $result['data'] ?? null);
    }

    /**
     * Fetch the key named by `id`, scoped to this console's provider and (for
     * the client zone) the session user. Returns null — indistinguishable
     * from "doesn't exist" — when the id is missing, belongs to a different
     * provider, or (client zone only) belongs to someone else. No IDOR, no
     * existence leak.
     */
    private function resolveKey(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $key = model(ApiAccessKeyModel::class)->findKey($id);

        if ($key === null || $this->providerOfService((int) $key->service_id) !== $this->provider()) {
            return null;
        }

        if ($this->requireOwnKey() && (int) $key->user_id !== (int) session('userWorking')->id) {
            return null;
        }

        return $key;
    }

    private function providerOfService(int $serviceId): ?string
    {
        foreach (list_api_services() as $service) {
            if ($service['id'] === $serviceId) {
                return $service['provider'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function historyEnvelope(object $key, array $params): ResponseInterface
    {
        $sortBy = (string) ($params['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, ApiHistoryModel::SCOPED_SORT_ALLOWLIST, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtolower((string) ($params['sort'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit  = (int) ($params['limit'] ?? 100);
        $limit  = $limit <= 0 ? 100 : min($limit, 200);

        $keyword = trim((string) ($params['keyword'] ?? ''));
        $domain  = trim((string) ($params['domain'] ?? ''));

        $rows = model(ApiHistoryModel::class)->searchScoped(
            $this->provider(),
            (int) $key->service_id,
            (int) $key->user_id,
            $sortBy,
            $sortDir,
            $limit,
            $offset,
            $keyword,
            $domain,
        );

        return $this->successEnvelope($key, [
            'rows'   => $rows,
            'offset' => $offset,
            'limit'  => $limit,
            'sort'   => ['by' => $sortBy, 'dir' => $sortDir],
        ]);
    }

    private function successEnvelope(object $key, mixed $data): ResponseInterface
    {
        return $this->response->setJSON([
            'status'         => 'success',
            'api_service_id' => $this->provider() . '.' . $key->service_id,
            'data'           => $data,
            'csrf_hash'      => csrf_hash(),
        ])->setStatusCode(200);
    }

    /**
     * @param array<string, string>|string $message
     */
    private function errorEnvelope(?object $key, array|string $message, int $status, mixed $data = null): ResponseInterface
    {
        $body = [
            'status'    => 'error',
            'message'   => is_array($message) ? implode(' ', $message) : $message,
            'csrf_hash' => csrf_hash(),
        ];

        if ($key !== null) {
            $body['api_service_id'] = $this->provider() . '.' . $key->service_id;
        }

        if ($data !== null) {
            $body['data'] = $data;
        }

        return $this->response->setJSON($body)->setStatusCode($status);
    }
}
