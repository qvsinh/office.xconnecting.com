<?php

namespace App\Filters;

use App\Libraries\ApiContext;
use App\Models\ApiKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bearer-token auth for `/api/v1/*` (alias `apikey`) — resolves the token via
 * `ApiKeyModel::resolve()` (prefix+sha256, rejects revoked/expired, stamps
 * `last_used_at`) and stashes the owning user id + scopes in `ApiContext` for
 * the `Api\V1` controllers to read. Also gates write verbs (POST/PATCH/PUT/
 * DELETE) behind a `write`-scoped key. See
 * `kanban/processing/20260721-07-rest-api.md`.
 */
class ApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        ApiContext::reset();

        $token = $this->bearerToken($request) ?? (string) $request->getHeaderLine('X-Api-Key');

        if ($token === '') {
            return $this->unauthorized('Missing API token.');
        }

        $row = model(ApiKeyModel::class)->resolve($token);

        if ($row === null) {
            return $this->unauthorized('Invalid or expired API token.');
        }

        ApiContext::set((int) $row->user_id, (string) $row->scopes);

        $writeMethods = ['POST', 'PATCH', 'PUT', 'DELETE'];

        if (in_array($request->getMethod(), $writeMethods, true) && ! ApiContext::hasWrite()) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'forbidden', 'message' => 'This API key is read-only.']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function bearerToken(RequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '' || ! str_starts_with(strtolower($header), strtolower('Bearer '))) {
            return null;
        }

        return trim(substr($header, 7));
    }

    private function unauthorized(string $message)
    {
        return service('response')
            ->setStatusCode(401)
            ->setJSON(['error' => 'unauthorized', 'message' => $message]);
    }
}
