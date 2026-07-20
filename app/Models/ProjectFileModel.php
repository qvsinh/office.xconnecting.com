<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `project_files` table — metadata for files uploaded to a project
 * (see `kanban/processing/20260721-00-master-social-hub.md`). Actual bytes
 * live under `writable/uploads/projects/{project_id}/{stored_name}`; this
 * table is the index.
 */
class ProjectFileModel extends Model
{
    protected $table            = 'project_files';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['project_id', 'uploaded_by', 'original_name', 'stored_name', 'mime', 'size'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';
    protected $deletedField     = 'deleted_at';

    /**
     * @return list<object>
     */
    public function forProject(int $projectId): array
    {
        return $this->where('project_id', $projectId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    /**
     * A file scoped to its parent project — the IDOR guard for
     * `/projects/{projectId}/files/{id}`-style routes.
     */
    public function findInProject(int $id, int $projectId): ?object
    {
        return $this->where('id', $id)->where('project_id', $projectId)->first();
    }
}
