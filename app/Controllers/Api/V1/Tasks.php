<?php

namespace App\Controllers\Api\V1;

use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `/api/v1/tasks/{id}` — every handler loads the task first, derives its
 * `project_id`, then checks access against that project (never trusts the
 * path id alone) — the same IDOR guard `Board` uses on the web side. See
 * `kanban/processing/20260721-07-rest-api.md`.
 */
class Tasks extends BaseApiController
{
    public function show(int $id): ResponseInterface
    {
        $task = model(TaskModel::class)->findWithAssignee($id);

        if ($task === null || project_role((int) $task->project_id, $this->userId()) === null) {
            return $this->fail('not_found', 'Task not found.', 404);
        }

        return $this->ok($this->taskPayload($task));
    }

    /**
     * `PATCH /api/v1/tasks/{id}` — `{title, description?, priority?,
     * assignee_id?, due_date?, column_id?}`, mirrors `Board::updateTask()`:
     * `column_id`, when given, must belong to the same project.
     */
    public function update(int $id): ResponseInterface
    {
        $taskModel = model(TaskModel::class);
        $task      = $taskModel->findTask($id);

        if ($task === null) {
            return $this->fail('not_found', 'Task not found.', 404);
        }

        $projectId = (int) $task->project_id;

        if (! can_edit_project($projectId, $this->userId())) {
            return $this->fail('not_found', 'Task not found.', 404);
        }

        if (! $this->validate($this->taskValidationRules())) {
            return $this->failValidation($this->validator->getErrors());
        }

        $fields = $this->validator->getValidated();

        $assigneeId = $this->resolveAssigneeField($projectId, $fields['assignee_id'] ?? null);

        if ($assigneeId === false) {
            return $this->fail('validation', 'The assignee must be a member of this project.', 422);
        }

        $data = [
            'title'       => trim((string) $fields['title']),
            'description' => $this->nullableValue($fields['description'] ?? null),
            'priority'    => (string) (($fields['priority'] ?? '') !== '' ? $fields['priority'] : 'normal'),
            'assignee_id' => $assigneeId,
            'due_date'    => $this->nullableValue($fields['due_date'] ?? null),
        ];

        $columnRaw = $fields['column_id'] ?? null;

        if ($columnRaw !== null && $columnRaw !== '') {
            $columnId = $this->resolveColumnField($projectId, $columnRaw);

            if ($columnId === false) {
                return $this->fail('validation', 'That column does not belong to this project.', 422);
            }

            $data['column_id'] = $columnId;
        }

        $taskModel->update($id, $data);

        return $this->ok($this->taskPayload($taskModel->findWithAssignee($id)));
    }

    public function delete(int $id): ResponseInterface
    {
        $taskModel = model(TaskModel::class);
        $task      = $taskModel->findTask($id);

        if ($task === null) {
            return $this->fail('not_found', 'Task not found.', 404);
        }

        if (! can_edit_project((int) $task->project_id, $this->userId())) {
            return $this->fail('not_found', 'Task not found.', 404);
        }

        $taskModel->delete($id);

        return $this->ok(['deleted' => true]);
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
}
