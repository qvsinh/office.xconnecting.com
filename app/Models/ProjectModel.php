<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
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
        $this->select('projects.*');
        $this->applyForUserFilter($this->builder(), $userId);

        return $this->orderBy('projects.created_at', 'DESC')->findAll();
    }

    /**
     * Count version of `forUser()` — backs the personal dashboard's "my
     * projects" stat card without fetching every row.
     */
    public function countForUser(int $userId): int
    {
        // Explicit single-column select: countAllResults() wraps a grouped
        // query as `SELECT COUNT(*) FROM (...) CI_count_all_results`, and
        // the implicit `SELECT *` would otherwise expose both
        // `projects.id` and `project_members.id`/`workspace_members.id`
        // under the same alias.
        $this->select('projects.id');
        $this->applyForUserFilter($this->builder(), $userId);

        return $this->countAllResults();
    }

    /**
     * The most recently created projects — backs the company dashboard's
     * "recent projects" list. `id DESC` breaks ties for rows created in the
     * same second, so insertion order stays deterministic.
     *
     * @return list<object>
     */
    public function recent(int $limit = 5): array
    {
        return $this->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->findAll($limit);
    }

    /**
     * Total (non-soft-deleted) project count — backs the company
     * dashboard's "projects" stat card.
     */
    public function countAll(): int
    {
        return $this->countAllResults();
    }

    /**
     * Projects ordered by their number of open (not-`Done`) tasks, most
     * active first — backs the company dashboard's "most active projects"
     * list. Uses a correlated subquery (not a `LEFT JOIN tasks ...
     * GROUP BY`) so a project whose only tasks are all `Done` still gets an
     * `open_tasks` row of `0` instead of disappearing from the result —
     * `GROUP BY` after a `WHERE`-filtered `LEFT JOIN` would drop it.
     *
     * @return list<object{id: int, name: string, open_tasks: int}>
     */
    public function mostActive(int $limit = 5): array
    {
        $doneColumn = $this->db->escape('Done');

        return $this->select(
            'projects.id, projects.name, '
            . '(SELECT COUNT(*) FROM tasks t LEFT JOIN task_columns tc ON tc.id = t.column_id '
            . 'WHERE t.project_id = projects.id AND t.deleted_at IS NULL '
            . 'AND (tc.name != ' . $doneColumn . ' OR tc.name IS NULL)) AS open_tasks',
        )
            ->orderBy('open_tasks', 'DESC')
            ->findAll($limit);
    }

    /**
     * The one place the "direct project member or parent-workspace member"
     * test is defined — shared by `forUser()`/`countForUser()` so they can
     * never drift apart.
     */
    private function applyForUserFilter(BaseBuilder $builder, int $userId): void
    {
        $builder->join('project_members', 'project_members.project_id = projects.id', 'left')
            ->join('workspace_members', 'workspace_members.workspace_id = projects.workspace_id', 'left')
            ->groupStart()
            ->where('project_members.user_id', $userId)
            ->orWhere('workspace_members.user_id', $userId)
            ->groupEnd()
            ->groupBy('projects.id');
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
    public function createProject(int $workspaceId, string $name, int $ownerId, ?string $description = null, ?string $color = null): int
    {
        return (int) $this->insert([
            'workspace_id' => $workspaceId,
            'name'         => $name,
            'slug'         => $this->uniqueSlug($workspaceId, $name),
            'owner_id'     => $ownerId,
            'description'  => $description,
            'color'        => $color,
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
