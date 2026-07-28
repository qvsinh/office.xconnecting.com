<?php

namespace App\Controllers\Api\V1;

use App\Libraries\ApiContext;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared JSON envelope + auth context for `/api/v1` controllers. Extends the
 * bare `CodeIgniter\Controller` (not `App\Controllers\BaseController`) — a
 * stateless Bearer-token API needs neither CSRF nor the web-session helpers
 * the web `BaseController` pulls in. See
 * `kanban/processing/20260721-07-rest-api.md`.
 */
abstract class BaseApiController extends Controller
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper(['auth', 'access']);
    }

    /**
     * @param array<string, mixed> $meta
     * @param mixed                $data
     */
    protected function ok($data, array $meta = []): ResponseInterface
    {
        return $this->response->setJSON(['data' => $data, 'error' => null, 'meta' => $meta]);
    }

    protected function fail(string $error, string $message, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'data'    => null,
            'error'   => $error,
            'message' => $message,
        ]);
    }

    /**
     * 422 for a failed `$this->validate()` call — same envelope as `fail()`
     * plus the field-level `errors` array, shared by every controller that
     * validates request input.
     *
     * @param array<string, string> $errors
     */
    protected function failValidation(array $errors): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'data'    => null,
            'error'   => 'validation',
            'message' => (string) reset($errors),
            'errors'  => $errors,
        ]);
    }

    /**
     * The API key's owning user id — resolved by `ApiKeyFilter`, never the
     * (nonexistent) web session. Every access-helper call in an `Api\V1`
     * controller MUST pass this explicitly (`project_role($id,
     * $this->userId())`) since `project_role()`/`can_edit_project()` default
     * to `current_user_id()`, which reads the session and is always `0` here.
     */
    protected function userId(): int
    {
        return (int) ApiContext::userId();
    }

    /**
     * `trim()`+empty-to-null for optional `description`/`due_date` fields —
     * the "empty string means unset" convention shared by every task
     * create/update handler.
     */
    protected function nullableValue(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value !== '' ? $value : null;
    }
}
