<?php

namespace App\Controllers\Api\V1;

use App\Libraries\ApiContext;
use App\Models\ProjectMemberModel;
use App\Models\TaskColumnModel;
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
     * Shared task JSON shape for `Projects::createTask()`/`Tasks::show()`/
     * `update()` — expects a row carrying the `assignee_name` join (see
     * `TaskModel::findWithAssignee()`/`forProject()`).
     *
     * @return array<string, mixed>
     */
    protected function taskPayload(object $task): array
    {
        return [
            'id'            => (int) $task->id,
            'project_id'    => (int) $task->project_id,
            'column_id'     => $task->column_id !== null ? (int) $task->column_id : null,
            'title'         => $task->title,
            'description'   => $task->description,
            'priority'      => $task->priority,
            'assignee_id'   => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_name' => $task->assignee_name ?? null,
            'due_date'      => $task->due_date,
            'position'      => (int) $task->position,
            'created_at'    => $task->created_at,
            'updated_at'    => $task->updated_at,
        ];
    }

    /**
     * Resolves an `assignee_id` value against project membership — shared by
     * `Projects::createTask()`/`Tasks::update()` so "assignee must be a
     * project member" can't drift between the two call sites. `null`/empty
     * means "no assignee"; a non-member id returns `false`.
     */
    protected function resolveAssigneeField(int $projectId, mixed $raw): false|int|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $userId = (int) $raw;

        return model(ProjectMemberModel::class)->isMember($projectId, $userId) ? $userId : false;
    }

    /**
     * Whether a `column_id` value belongs to the project — `false` on a
     * foreign/unknown id. Callers that need a default (e.g. task creation)
     * handle the null/empty case themselves before calling this.
     */
    protected function resolveColumnField(int $projectId, mixed $raw): false|int
    {
        return model(TaskColumnModel::class)->belongsToProject((int) $raw, $projectId) ? (int) $raw : false;
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
