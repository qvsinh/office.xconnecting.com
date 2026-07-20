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
}
