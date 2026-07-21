<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `user_groups` table — a named, admin-managed set of users that
 * can be bulk-added to a workspace/project (see
 * `kanban/processing/20260721-00-master-social-hub.md`). Table is named
 * `user_groups`, not `groups` (reserved word in MySQL 8).
 */
class GroupModel extends Model
{
    /**
     * Sortable columns for the admin groups list (`searchAdmin()`); anything
     * else falls back to `name`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'slug', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'slug', 'description'];

    protected $table            = 'user_groups';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'slug', 'description'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `UserModel::findUser()`.
     */
    public function findGroup(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Creates a group, deriving a unique `slug` from `$name`
     * (`url_title($name, '-', true)`, de-duplicated with a numeric suffix).
     * The one place `user_groups` rows are inserted so every row always gets
     * a slug.
     *
     * @return int inserted id
     */
    public function createGroup(string $name, ?string $description = null): int
    {
        return (int) $this->insert([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'description' => $description,
        ]);
    }

    /**
     * Admin list search (`Admin\Groups::index()`): keyword LIKE across
     * name/slug/description, with a `member_count` subquery attached so it
     * stays accurate regardless of joins. `id` is always a secondary sort
     * key so ties resolve deterministically across pages — mirrors
     * `SiteVariableModel::searchAdmin()`. Soft-deleted rows are excluded
     * automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'name', string $order = 'asc', int $limit = 25, int $offset = 0): array
    {
        $this->select('user_groups.*, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = user_groups.id) AS member_count');
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'name';
        $sortOrder  = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * Total (non-soft-deleted) group count — backs the company dashboard's
     * "groups" stat card.
     */
    public function countAll(): int
    {
        return $this->countAllResults();
    }

    /**
     * The one place `keyword` gets translated into a WHERE clause for the
     * admin list — shared by `searchAdmin()`/`countAdmin()` so the paginated
     * rows and the total can't drift apart.
     *
     * @param array{keyword?: string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $builder->groupStart();

        foreach (self::KEYWORD_FIELDS as $i => $field) {
            $i === 0 ? $builder->like($field, $keyword) : $builder->orLike($field, $keyword);
        }
        $builder->groupEnd();
    }

    /**
     * @return list<object>
     */
    public function groupsForUser(int $userId): array
    {
        return $this->select('user_groups.*')
            ->join('group_members', 'group_members.group_id = user_groups.id')
            ->where('group_members.user_id', $userId)
            ->orderBy('user_groups.name', 'ASC')
            ->findAll();
    }

    /**
     * Appends `-2`, `-3`, ... to the base slug until it's not already taken
     * — the one place slug uniqueness is enforced for `user_groups`.
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
