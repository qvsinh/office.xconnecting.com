<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
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
     * `member_count`/`project_count` are computed via independent
     * subqueries, so they stay accurate regardless of that join; `role` is
     * the caller's own membership role (correlated on `$userId`, not the
     * joined row, which can belong to any member) — null when the row is
     * only visible via the `visibility='company'` clause and not yet
     * joined. Backs the card grid's role badge + project count (see
     * `kanban/processing/20260721-03-workspaces.md` §UI notes).
     *
     * @return list<object>
     */
    public function forUser(int $userId): array
    {
        $escapedUserId = $this->db->escape($userId);

        $this->select(
            'workspaces.*, '
            . '(SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id = workspaces.id) AS member_count, '
            . '(SELECT COUNT(*) FROM projects p WHERE p.workspace_id = workspaces.id AND p.deleted_at IS NULL) AS project_count, '
            . '(SELECT wm2.role FROM workspace_members wm2 WHERE wm2.workspace_id = workspaces.id AND wm2.user_id = ' . $escapedUserId . ') AS role',
        );
        $this->applyForUserFilter($this->builder(), $userId);

        return $this->orderBy('workspaces.created_at', 'DESC')->findAll();
    }

    /**
     * Count version of `forUser()` — backs the personal dashboard's "my
     * workspaces" stat card without fetching every row.
     */
    public function countForUser(int $userId): int
    {
        // Explicit single-column select: countAllResults() wraps a grouped
        // query as `SELECT COUNT(*) FROM (...) CI_count_all_results`, and
        // the implicit `SELECT *` would otherwise expose both
        // `workspaces.id` and `workspace_members.id` under the same alias.
        $this->select('workspaces.id');
        $this->applyForUserFilter($this->builder(), $userId);

        return $this->countAllResults();
    }

    /**
     * The most recently created workspaces — backs the company dashboard's
     * "recent workspaces" list. `id DESC` breaks ties for rows created in
     * the same second, so insertion order stays deterministic.
     *
     * @return list<object>
     */
    public function recent(int $limit = 5): array
    {
        return $this->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->findAll($limit);
    }

    /**
     * Total (non-soft-deleted) workspace count — backs the company
     * dashboard's "workspaces" stat card.
     */
    public function countAll(): int
    {
        return $this->countAllResults();
    }

    /**
     * The one place the "owns, is a member of, or company-wide" membership
     * test is defined — shared by `forUser()`/`countForUser()` so they can
     * never drift apart.
     */
    private function applyForUserFilter(BaseBuilder $builder, int $userId): void
    {
        $builder->join('workspace_members', 'workspace_members.workspace_id = workspaces.id', 'left')
            ->groupStart()
            ->where('workspace_members.user_id', $userId)
            ->orWhere('workspaces.owner_id', $userId)
            ->orWhere('workspaces.visibility', 'company')
            ->groupEnd()
            ->groupBy('workspaces.id');
    }

    /**
     * `visibility='company'` workspaces the user can discover and join —
     * excludes ones they already own or belong to. Backs the "discoverable"
     * section of `Workspaces::index()` (`forUser()` already folds every
     * company workspace in regardless of membership, so this is the
     * complementary "not yet joined" slice used for the Join CTA).
     *
     * @return list<object>
     */
    public function discoverableFor(int $userId): array
    {
        return $this->select('workspaces.*, (SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id = workspaces.id) AS member_count')
            ->where('visibility', 'company')
            ->where('owner_id !=', $userId)
            ->whereNotIn('id', static fn (BaseBuilder $builder) => $builder->select('workspace_id')->from('workspace_members')->where('user_id', $userId))
            ->orderBy('created_at', 'DESC')
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
