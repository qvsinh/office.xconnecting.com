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
 * The upstream call itself (and its `api_history` logging via
 * `ApiHistoryModel::record()`) happens in the shared `CnrClient`/`JprsClient`
 * libraries; this base only provides the `command=History` read shortcut.
 */
abstract class ApiController extends BaseController
{
    protected $helpers             = ['app', 'service_config'];
    public ?object $user           = null;
    public ?int $service_id        = null;
    protected ?int $key_enable_log = null;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $context = service('apiAuthContext');

        $this->user           = $context->user;
        $this->service_id     = $context->serviceId;
        $this->key_enable_log = $context->keyEnableLog;
    }

    /**
     * Paginated/filtered read of `api_history` scoped to this key's service,
     * used for the `command=History` shortcut on both CNR and JPRS.
     */
    protected function fetchHistory(string $serviceName): ResponseInterface
    {
        $req = $this->request;

        $sortBy = (string) ($req->getVar('sort_by') ?? 'created_at');
        if (! in_array($sortBy, ApiHistoryModel::SCOPED_SORT_ALLOWLIST, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtolower((string) ($req->getVar('sort') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $offset = max(0, (int) ($req->getVar('offset') ?? 0));
        $limit  = (int) ($req->getVar('limit') ?? 100);
        $limit  = $limit <= 0 ? 100 : min($limit, 200);

        $keyword = trim((string) ($req->getVar('keyword') ?? ''));
        $domain  = trim((string) ($req->getVar('domain') ?? ''));

        try {
            $rows = model(ApiHistoryModel::class)->searchScoped(
                $serviceName,
                $this->service_id,
                $this->user->id,
                $sortBy,
                $sortDir,
                $limit,
                $offset,
                $keyword,
                $domain,
            );

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
