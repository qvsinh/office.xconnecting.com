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
    /**
     * `SELECT` list shared by `forProject()`/`findWithUploader()` so both
     * stay in lockstep with the `uploader_name` join.
     */
    private const SELECT_WITH_UPLOADER = 'project_files.*, users.name AS uploader_name';

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
     * Files for a project, newest first, with the uploader's display name
     * attached — backs `Projects::show()` and `ProjectFiles::index()`.
     *
     * @return list<object>
     */
    public function forProject(int $projectId): array
    {
        return $this->select(self::SELECT_WITH_UPLOADER)
            ->join('users', 'users.id = project_files.uploaded_by', 'left')
            ->where('project_files.project_id', $projectId)
            ->orderBy('project_files.created_at', 'DESC')
            ->findAll();
    }

    /**
     * Typed wrapper over `find()` — mirrors `UserModel::findUser()`.
     */
    public function findFile(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * A single file row with the uploader's display name attached — backs
     * `ProjectFiles::upload()`'s response.
     */
    public function findWithUploader(int $id): ?object
    {
        return $this->select(self::SELECT_WITH_UPLOADER)
            ->join('users', 'users.id = project_files.uploaded_by', 'left')
            ->where('project_files.id', $id)
            ->first();
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
