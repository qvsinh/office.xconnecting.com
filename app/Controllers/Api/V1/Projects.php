<?php

namespace App\Controllers\Api\V1;

use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `/api/v1/projects` — read access via `project_role()`, task creation via
 * `can_edit_project()`, both checked against `$this->userId()` (the API
 * key's owner), never the web session. Same IDOR posture as `Projects`/
 * `Board` on the web side. See
 * `kanban/processing/20260721-07-rest-api.md`.
 */
class Projects extends BaseApiController
{
    /**
     * Projects the key's user can access, direct member or via their
     * workspace.
     */
    public function index(): ResponseInterface
    {
        $projects = array_map($this->projectPayload(...), model(ProjectModel::class)->forUser($this->userId()));

        return $this->ok($projects);
    }

    public function show(int $id): ResponseInterface
    {
        if (project_role($id, $this->userId()) === null) {
            return $this->fail('not_found', 'Project not found.', 404);
        }

        $payload            = $this->projectPayload(model(ProjectModel::class)->findProject($id));
        $payload['columns'] = array_map(static fn (object $c): array => [
            'id'       => (int) $c->id,
            'name'     => $c->name,
            'position' => (int) $c->position,
        ], model(TaskColumnModel::class)->forProject($id));

        return $this->ok($payload);
    }

    /**
     * `GET /api/v1/projects/{id}/tasks` — optional `?column_id`/
     * `?assignee_id`/`?priority` filters, applied in PHP over
     * `TaskModel::forProject()` (already ordered/joined for the assignee
     * name), the same result set the board view fetches.
     */
    public function tasks(int $id): ResponseInterface
    {
        if (project_role($id, $this->userId()) === null) {
            return $this->fail('not_found', 'Project not found.', 404);
        }

        $tasks = model(TaskModel::class)->forProject($id);

        $columnId   = $this->request->getGet('column_id');
        $assigneeId = $this->request->getGet('assignee_id');
        $priority   = $this->request->getGet('priority');

        if ($columnId !== null && $columnId !== '') {
            $tasks = array_values(array_filter($tasks, static fn (object $t): bool => (int) $t->column_id === (int) $columnId));
        }

        if ($assigneeId !== null && $assigneeId !== '') {
            $tasks = array_values(array_filter($tasks, static fn (object $t): bool => (int) $t->assignee_id === (int) $assigneeId));
        }

        if ($priority !== null && $priority !== '') {
            $tasks = array_values(array_filter($tasks, static fn (object $t): bool => $t->priority === $priority));
        }

        return $this->ok(array_map($this->taskPayload(...), $tasks));
    }

    /**
     * `POST /api/v1/projects/{id}/tasks` — `{title, description?, priority?,
     * assignee_id?, due_date?, column_id?}`. Defaults `column_id` to the
     * project's first column, mirrors `Board::createTask()`'s
     * assignee-must-be-a-member guard. Accepts a JSON body or form-encoded
     * fields — `$this->validate()`'s `withRequest()` sources either.
     */
    public function createTask(int $id): ResponseInterface
    {
        if (project_role($id, $this->userId()) === null) {
            return $this->fail('not_found', 'Project not found.', 404);
        }

        if (! can_edit_project($id, $this->userId())) {
            return $this->fail('forbidden', 'You do not have permission to manage this project\'s board.', 403);
        }

        if (! $this->validate($this->taskValidationRules())) {
            return $this->failValidation($this->validator->getErrors());
        }

        $fields = $this->validator->getValidated();

        $assigneeId = $this->resolveAssigneeField($id, $fields['assignee_id'] ?? null);

        if ($assigneeId === false) {
            return $this->fail('validation', 'The assignee must be a member of this project.', 422);
        }

        $columnId = $this->resolveColumnIdOrDefault($id, $fields['column_id'] ?? null);

        if ($columnId === false) {
            return $this->fail('validation', 'That column does not belong to this project.', 422);
        }

        $taskModel = model(TaskModel::class);
        $position  = $taskModel->where('project_id', $id)->where('column_id', $columnId)->countAllResults();

        $taskId = (int) $taskModel->insert([
            'project_id'  => $id,
            'column_id'   => $columnId,
            'title'       => trim((string) $fields['title']),
            'description' => $this->nullableValue($fields['description'] ?? null),
            'priority'    => (string) (($fields['priority'] ?? '') !== '' ? $fields['priority'] : 'normal'),
            'assignee_id' => $assigneeId,
            'due_date'    => $this->nullableValue($fields['due_date'] ?? null),
            'position'    => $position,
            'created_by'  => $this->userId(),
        ]);

        return $this->ok($this->taskPayload($taskModel->findWithAssignee($taskId)))->setStatusCode(201);
    }

    /**
     * @return array<string, string>
     */
    private function taskValidationRules(): array
    {
        return [
            'title'       => 'required|max_length[255]',
            'description' => 'permit_empty',
            'priority'    => 'permit_empty|in_list[low,normal,high,urgent]',
            'assignee_id' => 'permit_empty|is_natural_no_zero',
            'due_date'    => 'permit_empty|valid_date',
            'column_id'   => 'permit_empty|is_natural_no_zero',
        ];
    }

    /**
     * Resolves the optional `column_id` field, defaulting to the project's
     * first column when omitted. Returns `false` when a given id doesn't
     * belong to this project.
     */
    private function resolveColumnIdOrDefault(int $projectId, mixed $raw): false|int|null
    {
        if ($raw === null || $raw === '') {
            $first = model(TaskColumnModel::class)->firstColumn($projectId);

            return $first ? (int) $first->id : null;
        }

        return $this->resolveColumnField($projectId, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(object $project): array
    {
        return [
            'id'           => (int) $project->id,
            'name'         => $project->name,
            'slug'         => $project->slug,
            'description'  => $project->description,
            'status'       => $project->status,
            'workspace_id' => (int) $project->workspace_id,
        ];
    }
}
