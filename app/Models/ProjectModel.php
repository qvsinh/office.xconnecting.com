<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `projects` table — sits inside a `workspaces` row (see
 * `kanban/processing/20260721-00-master-social-hub.md`).
 */
class ProjectModel extends Model
{
    protected $table            = 'projects';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['workspace_id', 'name', 'slug', 'description', 'status', 'color', 'owner_id'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` — mirrors `UserModel::findUser()`.
     */
    public function findProject(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * @return list<object>
     */
    public function forWorkspace(int $workspaceId): array
    {
        return $this->where('workspace_id', $workspaceId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    /**
     * Projects where the user is a direct project member OR a member of the
     * parent workspace. `GROUP BY projects.id` dedupes a project matched by
     * both joins.
     *
     * @return list<object>
     */
    public function forUser(int $userId): array
    {
        return $this->select('projects.*')
            ->join('project_members', 'project_members.project_id = projects.id', 'left')
            ->join('workspace_members', 'workspace_members.workspace_id = projects.workspace_id', 'left')
            ->groupStart()
            ->where('project_members.user_id', $userId)
            ->orWhere('workspace_members.user_id', $userId)
            ->groupEnd()
            ->groupBy('projects.id')
            ->orderBy('projects.created_at', 'DESC')
            ->findAll();
    }

    /**
     * A project scoped to its parent workspace — the IDOR guard for
     * `/workspaces/{workspaceId}/projects/{id}`-style routes: returns null
     * if the project exists but belongs to a different workspace.
     */
    public function findInWorkspace(int $id, int $workspaceId): ?object
    {
        return $this->where('id', $id)->where('workspace_id', $workspaceId)->first();
    }

    /**
     * Creates a project, deriving a unique `slug` (per workspace) from
     * `$name`.
     *
     * @return int inserted id
     */
    public function createProject(int $workspaceId, string $name, int $ownerId, ?string $description = null): int
    {
        return (int) $this->insert([
            'workspace_id' => $workspaceId,
            'name'         => $name,
            'slug'         => $this->uniqueSlug($workspaceId, $name),
            'owner_id'     => $ownerId,
            'description'  => $description,
        ]);
    }

    /**
     * Appends `-2`, `-3`, ... to the base slug until it's not already taken
     * within the given workspace (slugs are unique per workspace, not
     * globally).
     */
    private function uniqueSlug(int $workspaceId, string $name): string
    {
        helper('url');

        $base = url_title($name, '-', true);
        $slug = $base;
        $i    = 2;

        while ($this->where('workspace_id', $workspaceId)->where('slug', $slug)->first() !== null) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
