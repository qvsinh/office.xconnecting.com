<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `task_columns` table — kanban board columns belonging to a
 * project (see `kanban/processing/20260721-00-master-social-hub.md`). Only
 * `created_at` on this table.
 */
class TaskColumnModel extends Model
{
    /**
     * Column names + positions seeded for every new project by
     * `seedDefaults()`.
     */
    private const DEFAULT_COLUMNS = ['To Do', 'In Progress', 'Done'];

    protected $table            = 'task_columns';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['project_id', 'name', 'position'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';

    /**
     * Typed wrapper over `find()` — mirrors `TaskModel::findTask()`. Not
     * named `findColumn()`: that name collides with `BaseModel::findColumn()`
     * (fetches a single DB column across rows).
     */
    public function findOne(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * @return list<object>
     */
    public function forProject(int $projectId): array
    {
        return $this->where('project_id', $projectId)
            ->orderBy('position', 'ASC')
            ->findAll();
    }

    /**
     * Seeds the default To Do(0)/In Progress(1)/Done(2) columns for a newly
     * created project. The one place `task_columns` rows are batch-created.
     */
    public function seedDefaults(int $projectId): void
    {
        $rows = [];

        foreach (self::DEFAULT_COLUMNS as $position => $name) {
            $rows[] = ['project_id' => $projectId, 'name' => $name, 'position' => $position, 'created_at' => date('Y-m-d H:i:s')];
        }

        $this->insertBatch($rows);
    }

    /**
     * Whether a column belongs to a project — the IDOR guard for `column_id`
     * inputs accepted from `Board`'s create/update/move task requests, so a
     * column from another project can never be attached to a task.
     */
    public function belongsToProject(int $columnId, int $projectId): bool
    {
        return $this->where('id', $columnId)->where('project_id', $projectId)->first() !== null;
    }

    /**
     * The lowest-position column in a project, or null if it has none —
     * backs `Board::createTask()`'s default column and `deleteColumn()`'s
     * task-relocation target.
     */
    public function firstColumn(int $projectId): ?object
    {
        return $this->where('project_id', $projectId)->orderBy('position', 'ASC')->first();
    }

    /**
     * Persist a new column order: sets `position` = array index for each id.
     * Every update is scoped to `project_id`, so a foreign column id in the
     * list can never be repositioned (IDOR guard) — it simply matches no row.
     *
     * @param list<int> $orderedIds column ids in the desired left-to-right order
     */
    public function applyOrder(int $projectId, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            $this->builder()
                ->where('id', (int) $id)
                ->where('project_id', $projectId)
                ->update(['position' => $position]);
        }
    }
}
