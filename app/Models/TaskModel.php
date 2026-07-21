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
     * Valid `priority` values — used by `countByPriority()`/`countByPriorityAll()`
     * so every bucket is present in the result even when there are zero tasks
     * of that priority.
     */
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * The `task_columns.name` that marks a task as completed — the one place
     * "done" is defined, shared by every open/done query below (dashboard
     * stats, `forAssignee()`'s `column_name`, `mostActive()`-style counts).
     */
    private const DONE_COLUMN = 'Done';

    /**
     * `SELECT` list shared by `forProject()`/`findWithAssignee()` so both
     * stay in lockstep with the `assignee_name` join.
     */
    private const SELECT_WITH_ASSIGNEE = 'tasks.*, users.name AS assignee_name';

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
     * A single task row with the assignee's display name attached — backs
     * `Board::createTask()`/`updateTask()`'s JSON response.
     */
    public function findWithAssignee(int $id): ?object
    {
        return $this->select(self::SELECT_WITH_ASSIGNEE)
            ->join('users', 'users.id = tasks.assignee_id', 'left')
            ->where('tasks.id', $id)
            ->first();
    }

    /**
     * All tasks in a project (board + list view), with the assignee's name
     * attached, ordered by column then position for a stable board layout.
     *
     * @return list<object>
     */
    public function forProject(int $projectId): array
    {
        return $this->select(self::SELECT_WITH_ASSIGNEE)
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
     * (Non-soft-deleted) tasks assigned to a user across all projects — backs
     * the "My Tasks" list, due-date-soonest first (nulls last), then
     * newest-created first. `column_name` (its current kanban column, e.g.
     * `Done`) lets callers derive open/done state without a second query —
     * see the personal dashboard's open-tasks stat.
     *
     * @return list<object>
     */
    public function forAssignee(int $userId): array
    {
        return $this->select('tasks.*, projects.name AS project_name, task_columns.name AS column_name')
            ->join('projects', 'projects.id = tasks.project_id')
            ->join('task_columns', 'task_columns.id = tasks.column_id', 'left')
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
     * Count of tasks per priority for a project — every priority in
     * `PRIORITIES` is present in the result (0 when unused), so callers
     * building a fixed-order chart never have to check `isset()`.
     *
     * @return array<string, int>
     */
    public function countByPriority(int $projectId): array
    {
        return $this->countByPriorityFiltered($projectId);
    }

    /**
     * Same as `countByPriority()` but across every (non-soft-deleted) task —
     * backs the company dashboard's "tasks by priority" bar.
     *
     * @return array<string, int>
     */
    public function countByPriorityAll(): array
    {
        return $this->countByPriorityFiltered(null);
    }

    /**
     * Open (not in a `Done` column) tasks in a project — backs
     * `ProjectModel::mostActive()`-style widgets scoped to a single project.
     */
    public function countOpenForProject(int $projectId): int
    {
        return $this->applyOpenFilter($projectId)->countAllResults();
    }

    /**
     * Open (not in a `Done` column) tasks across every project — backs the
     * company dashboard's "open tasks" stat card.
     */
    public function countOpenAll(): int
    {
        return $this->applyOpenFilter()->countAllResults();
    }

    /**
     * Tasks sitting in a `Done` column across every project — backs the
     * company dashboard's "tasks completed" stat card.
     */
    public function countDoneAll(): int
    {
        return $this->joinTaskColumns()->where('task_columns.name', self::DONE_COLUMN)->countAllResults();
    }

    /**
     * Open tasks assigned to a user, due within the next `$days` days
     * (inclusive of today) — backs the personal dashboard's "due soon" list.
     *
     * @return list<object>
     */
    public function dueSoonForUser(int $userId, int $days = 7): array
    {
        return $this->select(self::SELECT_WITH_ASSIGNEE)
            ->join('users', 'users.id = tasks.assignee_id', 'left')
            ->applyDueSoonFilter($userId, $days)
            ->orderBy('tasks.due_date', 'ASC')
            ->findAll();
    }

    /**
     * Count version of `dueSoonForUser()` — backs the personal dashboard's
     * "due soon" stat card without fetching every row.
     */
    public function countDueSoonForUser(int $userId, int $days = 7): int
    {
        return $this->applyDueSoonFilter($userId, $days)->countAllResults();
    }

    /**
     * @return array<string, int>
     */
    private function countByPriorityFiltered(?int $projectId): array
    {
        $this->select('priority, COUNT(*) AS total');
        if ($projectId !== null) {
            $this->where('project_id', $projectId);
        }
        $rows = $this->groupBy('priority')->findAll();

        $counts = array_fill_keys(self::PRIORITIES, 0);

        foreach ($rows as $row) {
            if (is_object($row)) {
                $counts[$row->priority] = (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * LEFT JOINs `task_columns` so a task's column name is available to
     * filter/group on — shared by every open/done query below.
     */
    private function joinTaskColumns(): self
    {
        return $this->join('task_columns', 'task_columns.id = tasks.column_id', 'left');
    }

    /**
     * Restricts the current query to "open" tasks — no column assigned yet,
     * or assigned to a column that isn't named `Done` — optionally scoped to
     * one project. The one place "open" is defined for count queries.
     */
    private function applyOpenFilter(?int $projectId = null): self
    {
        $this->joinTaskColumns()
            ->groupStart()
            ->where('task_columns.name !=', self::DONE_COLUMN)
            ->orWhere('task_columns.name', null)
            ->groupEnd();

        if ($projectId !== null) {
            $this->where('tasks.project_id', $projectId);
        }

        return $this;
    }

    /**
     * Shared by `dueSoonForUser()`/`countDueSoonForUser()` so the list and
     * its count can never drift apart.
     */
    private function applyDueSoonFilter(int $userId, int $days): self
    {
        $today = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+' . $days . ' days'));

        return $this->applyOpenFilter()
            ->where('tasks.assignee_id', $userId)
            ->where('tasks.due_date >=', $today)
            ->where('tasks.due_date <=', $until);
    }
}
