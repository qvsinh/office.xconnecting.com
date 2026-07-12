<?php

namespace App\Filters;

use App\Libraries\ApiAuthContext;
use App\Models\ApiAccessKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Applied to the `api` route group. Authenticates the caller by Hashids
 * Bearer token (see ApiController's original contract), scopes the request
 * to the token's env (live/testing) and service, and stashes the resolved
 * context in the `apiAuthContext` service for `ApiController::initController()`
 * to pick up. Rejects return a JSON `ResponseInterface` here — CI4 natively
 * short-circuits the request when `before()` returns a response, so no
 * controller is ever instantiated on a reject path (no exit()/send() needed).
 * Stateless — no session/cookie should persist across calls.
 */
class ApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('app');

        if (! $request instanceof IncomingRequest) {
            return $this->reject('Unauthorized');
        }

        $bearer = $request->getHeaderLine('Authorization');

        if (empty($bearer) || ! str_starts_with($bearer, 'Bearer ')) {
            return $this->reject('Unauthorized');
        }

        $accessToken = trim(substr($bearer, 7));

        if ($accessToken === '') {
            return $this->reject('Unauthorized');
        }

        try {
            $decoded = get_hashids(32)->decode($accessToken);
        } catch (Throwable) {
            return $this->reject('Unauthorized');
        }

        $userId    = (int) ($decoded[0] ?? 0);
        $envFlag   = (int) ($decoded[1] ?? -1);
        $serviceId = (int) ($decoded[2] ?? 0);

        if ($userId === 0 || ! in_array($envFlag, [0, 1], true) || $serviceId === 0) {
            return $this->reject('Unauthorized');
        }

        $keyModel = model(ApiAccessKeyModel::class);
        $keyRow   = $keyModel->findByAccessKey($accessToken);

        if (! $keyRow
            || (int) $keyRow->is_enable !== 1
            || (int) $keyRow->user_id !== $userId
            || (int) $keyRow->service_id !== $serviceId
        ) {
            return $this->reject('Unauthorized');
        }

        $env = $envFlag === 1 ? 'LIVE' : 'TESTING';

        if (! $this->validateEndpoint($request, $env)) {
            return $this->reject('Invalid endpoint');
        }

        $keyModel->touchLastAccess($accessToken);

        $context               = service('apiAuthContext');
        $context->user         = (object) ['id' => $userId, 'env' => $env];
        $context->serviceId    = $serviceId;
        $context->keyEnableLog = (int) $keyRow->enable_log;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        session()->destroy();
    }

    /**
     * URI segment 3 (.../api/{cnr|jprs}/{live|testing}/...) must be a valid
     * env value and must match the env encoded in the caller's token.
     */
    private function validateEndpoint(IncomingRequest $request, string $env): bool
    {
        $envSegment = $request->getUri()->getSegment(3);

        if (! in_array($envSegment, ['live', 'testing'], true)) {
            return false;
        }

        return ($env === 'LIVE' && $envSegment === 'live')
            || ($env === 'TESTING' && $envSegment === 'testing');
    }

    private function reject(string $message): ResponseInterface
    {
        return service('response')
            ->setJSON(['status' => 'error', 'message' => $message])
            ->setStatusCode(401);
    }
}
