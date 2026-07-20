<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `workspaces` table — the top-level collaboration container users
 * create and join (see `kanban/processing/20260721-00-master-social-hub.md`).
 */
class WorkspaceModel extends Model
{
    protected $table            = 'workspaces';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'slug', 'description', 'owner_id', 'status', 'visibility'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` — mirrors `UserModel::findUser()`.
     */
    public function findWorkspace(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Workspaces the user owns, is a member of, or that are company-wide
     * (`visibility='company'`), newest first. `GROUP BY workspaces.id` keeps
     * each workspace to one row even though the `LEFT JOIN` on
     * `workspace_members` can otherwise multiply it once per member row.
     * `member_count` is computed via an independent subquery, so it stays
     * accurate regardless of that join.
     *
     * @return list<object>
     */
    public function forUser(int $userId): array
    {
        return $this->select('workspaces.*, (SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id = workspaces.id) AS member_count')
            ->join('workspace_members', 'workspace_members.workspace_id = workspaces.id', 'left')
            ->groupStart()
            ->where('workspace_members.user_id', $userId)
            ->orWhere('workspaces.owner_id', $userId)
            ->orWhere('workspaces.visibility', 'company')
            ->groupEnd()
            ->groupBy('workspaces.id')
            ->orderBy('workspaces.created_at', 'DESC')
            ->findAll();
    }

    /**
     * A single workspace with its member count and owner's display name
     * attached — backs the workspace detail page.
     */
    public function findWithMeta(int $id): ?object
    {
        return $this->select('workspaces.*, (SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id = workspaces.id) AS member_count, users.name AS owner_name')
            ->join('users', 'users.id = workspaces.owner_id', 'left')
            ->where('workspaces.id', $id)
            ->first();
    }

    /**
     * Creates a workspace, deriving a unique `slug` from `$name`.
     *
     * @return int inserted id
     */
    public function createWorkspace(string $name, int $ownerId, ?string $description = null, string $visibility = 'private'): int
    {
        return (int) $this->insert([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'owner_id'    => $ownerId,
            'description' => $description,
            'visibility'  => $visibility,
        ]);
    }

    /**
     * Appends `-2`, `-3`, ... to the base slug until it's not already taken.
     */
    private function uniqueSlug(string $name): string
    {
        helper('url');

        $base = url_title($name, '-', true);
        $slug = $base;
        $i    = 2;

        while ($this->where('slug', $slug)->first() !== null) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
