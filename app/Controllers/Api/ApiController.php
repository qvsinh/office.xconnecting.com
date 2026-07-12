<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ApiHistoryModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base for registrar API proxy endpoints (CnrApi, JprsApi). Bearer-token
 * authentication, env/service scoping, and last-access bookkeeping happen in
 * `ApiFilter::before()` (registered as the `apiFilter` route filter) since
 * CI4 can short-circuit a filter with a 401 `ResponseInterface` without ever
 * instantiating this controller. On success the filter stashes the resolved
 * caller in the `apiAuthContext` service, which `initController()` copies
 * onto `$this->user` / `$this->service_id` / `$this->key_enable_log` below.
 * This controller logs the call to `api_history`.
 */
abstract class ApiController extends BaseController
{
    protected $helpers = ['app', 'service_config'];

    public ?object $user = null;
    public ?int $service_id = null;
    protected ?int $key_enable_log = null;

    private const SKIP_LOG_LISTING_COMMANDS = [
        'QueryEventList',
        'QueryDNSZoneList',
        'QueryContactList',
        'QueryDomainList',
    ];

    private const HISTORY_SORT_ALLOWLIST = ['created_at', 'id', 'status', 'command'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $context = service('apiAuthContext');

        $this->user           = $context->user;
        $this->service_id     = $context->serviceId;
        $this->key_enable_log = $context->keyEnableLog;
    }

    /**
     * Persist a proxy call to `api_history`, gated by the key's `enable_log`.
     * Never throws into the response path — logging failures are logged and swallowed.
     *
     * Expected keys: env, service_name, service_id, url, command, domain, params
     * (array|string), response (array|string), metadata, status.
     */
    protected function logApiCall(array $params): void
    {
        if ((int) $this->key_enable_log !== 1) {
            return;
        }

        $command    = $params['command'] ?? null;
        $callParams = $params['params'] ?? null;

        if (in_array($command, self::SKIP_LOG_LISTING_COMMANDS, true)
            && is_array($callParams)
            && isset($callParams['limit'])
        ) {
            return;
        }

        if (is_array($callParams)) {
            unset($callParams['AgentLoginID'], $callParams['OperationPassword'], $callParams['AgentLoginPassword']);
        }

        $domain = $params['domain'] ?? null;
        if (empty($domain) && is_array($callParams)) {
            $domain = $callParams['dnszone'] ?? null;
        }

        $response = $params['response'] ?? null;

        $row = [
            'user_id'      => $this->user->id ?? null,
            'env'          => $params['env'] ?? null,
            'service_name' => $params['service_name'] ?? null,
            'service_id'   => $params['service_id'] ?? $this->service_id,
            'url'          => $params['url'] ?? null,
            'status'       => $params['status'] ?? 1,
            'command'      => $command,
            'domain'       => $domain,
            'params'       => is_string($callParams) ? $callParams : json_encode($callParams, JSON_PRETTY_PRINT),
            'response'     => is_string($response) ? $response : json_encode($response, JSON_PRETTY_PRINT),
            'metadata'     => $params['metadata'] ?? null,
        ];

        try {
            model(ApiHistoryModel::class)->insert($row);
        } catch (Throwable $e) {
            log_message('error', 'Failed to log API history: ' . $e->getMessage());
        }
    }

    /**
     * Paginated/filtered read of `api_history` scoped to this key's service,
     * used for the `command=History` shortcut on both CNR and JPRS.
     */
    protected function fetchHistory(string $serviceName): ResponseInterface
    {
        $req = $this->request;

        $sortBy = (string) ($req->getVar('sort_by') ?? 'created_at');
        if (! in_array($sortBy, self::HISTORY_SORT_ALLOWLIST, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtolower((string) ($req->getVar('sort') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $offset = max(0, (int) ($req->getVar('offset') ?? 0));
        $limit  = (int) ($req->getVar('limit') ?? 100);
        $limit  = $limit <= 0 ? 100 : min($limit, 200);

        $keyword = trim((string) ($req->getVar('keyword') ?? ''));
        $domain  = trim((string) ($req->getVar('domain') ?? ''));

        try {
            $builder = model(ApiHistoryModel::class)->builder();
            $builder->select('id, user_id, env, service_name, service_id, url, status, command, domain, params, response, metadata, created_at')
                ->where('service_name', $serviceName)
                ->where('service_id', $this->service_id);

            if ($domain !== '') {
                $builder->like('domain', $domain);
            }

            if ($keyword !== '') {
                $builder->groupStart()
                    ->like('response', $keyword)
                    ->orLike('params', $keyword)
                    ->orLike('command', $keyword)
                    ->groupEnd();
            }

            $rows = $builder->orderBy($sortBy, $sortDir)->get($limit, $offset)->getResultArray();

            return $this->response->setJSON([
                'status'         => 'success',
                'api_service_id' => $serviceName . '.' . $this->service_id,
                'data'           => [
                    'rows'   => $rows,
                    'offset' => $offset,
                    'limit'  => $limit,
                    'sort'   => ['by' => $sortBy, 'dir' => $sortDir],
                ],
            ])->setStatusCode(200);
        } catch (Throwable $e) {
            return $this->response->setJSON([
                'status'         => 'error',
                'api_service_id' => $serviceName . '.' . $this->service_id,
                'message'        => 'Failed to fetch history: ' . $e->getMessage(),
            ])->setStatusCode(500);
        }
    }
}
