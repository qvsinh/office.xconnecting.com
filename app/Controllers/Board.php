<?php

namespace App\Controllers;

use App\Models\ProjectMemberModel;
use App\Models\TaskColumnModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Kanban board (`task_columns` + `tasks`) for a project — column and task
 * CRUD plus drag-and-drop reorder. Gated by `authFilter` at the route plus
 * per-project `project_role()`/`can_edit_project()` (`access_helper.php`):
 * `data()` only needs read access, every mutation needs edit access. Task
 * and column endpoints take the child id, load it, then derive the owning
 * `project_id` from that row before checking access — the IDOR guard, since
 * a path id alone never proves access. See
 * `kanban/processing/20260721-05-kanban-tasks.md`.
 */
class Board extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper(['auth', 'access']);
    }

    /**
     * Board data: columns (with their tasks nested, ordered by position)
     * plus the project's members — backs the board view's initial `fetch()`.
     */
    public function data(int $projectId): ResponseInterface
    {
        if (project_role($projectId) === null) {
            return $this->jsonFailed('You do not have access to this project.', 403);
        }

        $tasksByColumn = [];

        foreach (model(TaskModel::class)->forProject($projectId) as $task) {
            $tasksByColumn[(int) $task->column_id][] = $this->taskPayload($task);
        }

        $columns = array_map(static fn (object $column): array => [
            'id'       => (int) $column->id,
            'name'     => $column->name,
            'position' => (int) $column->position,
            'tasks'    => $tasksByColumn[(int) $column->id] ?? [],
        ], model(TaskColumnModel::class)->forProject($projectId));

        $members = array_map(
            static fn (object $member): array => ['id' => (int) $member->id, 'name' => $member->name],
            model(ProjectMemberModel::class)->membersOf($projectId),
        );

        return $this->jsonSuccess('OK', ['columns' => $columns, 'members' => $members]);
    }

    /**
     * Creates a task in a project. Defaults `column_id` to the project's
     * first column when omitted, and `position` to the end of that column.
     */
    public function createTask(int $projectId): ResponseInterface
    {
        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        if (! $this->validate($this->taskValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $assigneeId = $this->resolveAssignee($projectId);

        if ($assigneeId === false) {
            return $this->jsonFailed('The assignee must be a member of this project.');
        }

        $columnId = $this->resolveColumnIdOrDefault($projectId);

        if ($columnId === false) {
            return $this->jsonFailed('That column does not belong to this project.');
        }

        $taskModel = model(TaskModel::class);
        $position  = $taskModel->where('project_id', $projectId)->where('column_id', $columnId)->countAllResults();

        $id = $taskModel->insert([
            'project_id'  => $projectId,
            'column_id'   => $columnId,
            'title'       => trim((string) $this->request->getPost('title')),
            'description' => $this->nullableField('description'),
            'priority'    => (string) ($this->request->getPost('priority') ?: 'normal'),
            'assignee_id' => $assigneeId,
            'due_date'    => $this->nullableField('due_date'),
            'position'    => $position,
            'created_by'  => current_user_id(),
        ]);

        return $this->jsonSuccess('Task created.', ['task' => $taskModel->findWithAssignee((int) $id)]);
    }

    /**
     * Updates a task's title/description/priority/assignee/due date, and
     * (when provided) moves it to another column of the same project.
     */
    public function updateTask(int $taskId): ResponseInterface
    {
        $taskModel = model(TaskModel::class);
        $task      = $taskModel->findTask($taskId);

        if (! $task) {
            return $this->jsonFailed('Task not found.', 404);
        }

        $projectId = (int) $task->project_id;

        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        if (! $this->validate($this->taskValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $assigneeId = $this->resolveAssignee($projectId);

        if ($assigneeId === false) {
            return $this->jsonFailed('The assignee must be a member of this project.');
        }

        $data = [
            'title'       => trim((string) $this->request->getPost('title')),
            'description' => $this->nullableField('description'),
            'priority'    => (string) ($this->request->getPost('priority') ?: 'normal'),
            'assignee_id' => $assigneeId,
            'due_date'    => $this->nullableField('due_date'),
        ];

        $columnRaw = $this->request->getPost('column_id');

        if ($columnRaw !== null && $columnRaw !== '') {
            $columnId = (int) $columnRaw;

            if (! model(TaskColumnModel::class)->belongsToProject($columnId, $projectId)) {
                return $this->jsonFailed('That column does not belong to this project.');
            }

            $data['column_id'] = $columnId;
        }

        $taskModel->update($taskId, $data);

        return $this->jsonSuccess('Task updated.', ['task' => $taskModel->findWithAssignee($taskId)]);
    }

    /**
     * Drag-and-drop reorder: sets `column_id` + `position` in one call
     * (`TaskModel::reorder()`) — the one place these two fields are written
     * together.
     */
    public function moveTask(int $taskId): ResponseInterface
    {
        $taskModel = model(TaskModel::class);
        $task      = $taskModel->findTask($taskId);

        if (! $task) {
            return $this->jsonFailed('Task not found.', 404);
        }

        $projectId = (int) $task->project_id;

        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        if (! $this->validate([
            'column_id' => 'required|is_natural',
            'position'  => 'required|is_natural',
        ])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $columnId = (int) $this->request->getPost('column_id');

        if (! model(TaskColumnModel::class)->belongsToProject($columnId, $projectId)) {
            return $this->jsonFailed('That column does not belong to this project.');
        }

        $taskModel->reorder($taskId, $columnId, (int) $this->request->getPost('position'));

        return $this->jsonSuccess('Task moved.');
    }

    public function deleteTask(int $taskId): ResponseInterface
    {
        $taskModel = model(TaskModel::class);
        $task      = $taskModel->findTask($taskId);

        if (! $task) {
            return $this->jsonFailed('Task not found.', 404);
        }

        if (! can_edit_project((int) $task->project_id)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        $taskModel->delete($taskId);

        return $this->jsonSuccess('Task deleted.');
    }

    public function createColumn(int $projectId): ResponseInterface
    {
        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        if (! $this->validate(['name' => 'required|max_length[100]'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $columnModel = model(TaskColumnModel::class);
        $position    = $columnModel->where('project_id', $projectId)->countAllResults();

        $id = $columnModel->insert([
            'project_id' => $projectId,
            'name'       => trim((string) $this->request->getPost('name')),
            'position'   => $position,
        ]);

        return $this->jsonSuccess('Column created.', ['column' => $columnModel->find($id)]);
    }

    /**
     * Reorder a project's board columns. Accepts `order` as a comma-separated
     * list of column ids in the desired left-to-right order (the board's
     * postForm() helper can't send array fields, hence CSV). Each reposition
     * is scoped to the project, so a foreign id can't be moved.
     */
    public function reorderColumns(int $projectId): ResponseInterface
    {
        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        $raw = (string) $this->request->getPost('order');
        $ids = array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $id): bool => $id > 0,
        ));

        if ($ids === []) {
            return $this->jsonFailed('No column order was provided.');
        }

        model(TaskColumnModel::class)->applyOrder($projectId, $ids);

        return $this->jsonSuccess('Columns reordered.');
    }

    public function renameColumn(int $columnId): ResponseInterface
    {
        $columnModel = model(TaskColumnModel::class);
        $column      = $columnModel->findOne($columnId);

        if (! $column) {
            return $this->jsonFailed('Column not found.', 404);
        }

        if (! can_edit_project((int) $column->project_id)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        if (! $this->validate(['name' => 'required|max_length[100]'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $columnModel->update($columnId, ['name' => trim((string) $this->request->getPost('name'))]);

        return $this->jsonSuccess('Column renamed.', ['column' => $columnModel->find($columnId)]);
    }

    /**
     * Deletes a column, relocating any tasks it holds to the project's next
     * remaining column (lowest position). Refuses when it's the project's
     * only column — a board always needs at least one.
     */
    public function deleteColumn(int $columnId): ResponseInterface
    {
        $columnModel = model(TaskColumnModel::class);
        $column      = $columnModel->findOne($columnId);

        if (! $column) {
            return $this->jsonFailed('Column not found.', 404);
        }

        $projectId = (int) $column->project_id;

        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s board.', 403);
        }

        $remaining = array_values(array_filter(
            $columnModel->forProject($projectId),
            static fn (object $row): bool => (int) $row->id !== $columnId,
        ));

        if ($remaining === []) {
            return $this->jsonFailed('A project needs at least one column.');
        }

        model(TaskModel::class)->where('column_id', $columnId)->set(['column_id' => $remaining[0]->id])->update();
        $columnModel->delete($columnId);

        return $this->jsonSuccess('Column deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(object $task): array
    {
        return [
            'id'            => (int) $task->id,
            'title'         => $task->title,
            'description'   => $task->description,
            'priority'      => $task->priority,
            'assignee_id'   => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_name' => $task->assignee_name,
            'due_date'      => $task->due_date,
            'position'      => (int) $task->position,
            'column_id'     => $task->column_id !== null ? (int) $task->column_id : null,
        ];
    }

    /**
     * Resolves the optional `column_id` request field to an int, defaulting
     * to the project's first column when omitted — shared by `createTask()`.
     * Returns `false` when a given id doesn't belong to this project.
     */
    private function resolveColumnIdOrDefault(int $projectId): false|int|null
    {
        $raw = $this->request->getPost('column_id');

        if ($raw === null || $raw === '') {
            $first = model(TaskColumnModel::class)->firstColumn($projectId);

            return $first ? (int) $first->id : null;
        }

        $columnId = (int) $raw;

        return model(TaskColumnModel::class)->belongsToProject($columnId, $projectId) ? $columnId : false;
    }

    /**
     * Resolves the optional `assignee_id` request field against project
     * membership — shared by `createTask()`/`updateTask()` so "assignee must
     * be a project member" can't drift between the two call sites. Returns
     * `null` when absent/empty, the member's user id when valid, or `false`
     * when the given id isn't a member of this project.
     */
    private function resolveAssignee(int $projectId): false|int|null
    {
        $raw = $this->request->getPost('assignee_id');

        if ($raw === null || $raw === '') {
            return null;
        }

        $userId = (int) $raw;

        return model(ProjectMemberModel::class)->isMember($projectId, $userId) ? $userId : false;
    }

    /**
     * `trim($this->request->getPost($field))` mapped to `null` when empty —
     * the "empty string means unset" convention for `description`/
     * `due_date` shared by `createTask()`/`updateTask()`.
     */
    private function nullableField(string $field): ?string
    {
        $value = trim((string) $this->request->getPost($field));

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function taskValidationRules(): array
    {
        return [
            'title'    => 'required|max_length[255]',
            'priority' => 'permit_empty|in_list[low,normal,high,urgent]',
            'due_date' => 'permit_empty|valid_date',
        ];
    }
}
