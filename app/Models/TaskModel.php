<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `tasks` table — kanban cards / task-list items belonging to a
 * project and (optionally) a `task_columns` row (see
 * `kanban/processing/20260721-00-master-social-hub.md`).
 */
class TaskModel extends Model
{
    /**
     * Valid `priority` values — used by `countByPriority()` so every bucket
     * is present in the result even when a project has zero tasks of that
     * priority.
     */
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $table            = 'tasks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'project_id', 'column_id', 'title', 'description', 'priority',
        'assignee_id', 'due_date', 'position', 'created_by',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Typed wrapper over `find()` — mirrors `UserModel::findUser()`.
     */
    public function findTask(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * All tasks in a project (board + list view), with the assignee's name
     * attached, ordered by column then position for a stable board layout.
     *
     * @return list<object>
     */
    public function forProject(int $projectId): array
    {
        return $this->select('tasks.*, users.name AS assignee_name')
            ->join('users', 'users.id = tasks.assignee_id', 'left')
            ->where('tasks.project_id', $projectId)
            ->orderBy('tasks.column_id', 'ASC')
            ->orderBy('tasks.position', 'ASC')
            ->findAll();
    }

    /**
     * A task scoped to its parent project — the IDOR guard for
     * `/projects/{projectId}/tasks/{id}`-style routes.
     */
    public function findInProject(int $id, int $projectId): ?object
    {
        return $this->where('id', $id)->where('project_id', $projectId)->first();
    }

    /**
     * Open (non-soft-deleted) tasks assigned to a user across all projects —
     * backs the "My Tasks" list, due-date-soonest first (nulls last), then
     * newest-created first.
     *
     * @return list<object>
     */
    public function forAssignee(int $userId): array
    {
        return $this->select('tasks.*, projects.name AS project_name')
            ->join('projects', 'projects.id = tasks.project_id')
            ->where('tasks.assignee_id', $userId)
            ->orderBy('tasks.due_date IS NULL', 'ASC', false)
            ->orderBy('tasks.due_date', 'ASC')
            ->orderBy('tasks.created_at', 'DESC')
            ->findAll();
    }

    /**
     * Moves a task to a (possibly different) column/position — the one
     * place drag-and-drop reorders write `column_id`/`position`.
     */
    public function reorder(int $taskId, int $columnId, int $position): void
    {
        $this->update($taskId, ['column_id' => $columnId, 'position' => $position]);
    }

    /**
     * Count of open tasks per priority for a project — every priority in
     * `PRIORITIES` is present in the result (0 when unused), so callers
     * building a fixed-order chart never have to check `isset()`.
     *
     * @return array<string, int>
     */
    public function countByPriority(int $projectId): array
    {
        $rows = $this->select('priority, COUNT(*) AS total')
            ->where('project_id', $projectId)
            ->groupBy('priority')
            ->findAll();

        $counts = array_fill_keys(self::PRIORITIES, 0);

        foreach ($rows as $row) {
            if (is_object($row)) {
                $counts[$row->priority] = (int) $row->total;
            }
        }

        return $counts;
    }
}
