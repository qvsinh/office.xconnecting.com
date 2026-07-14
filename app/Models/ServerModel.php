<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `servers` table (see kanban/processing/20260714-admin-servers.md).
 * No credentials on this table — see the plan's §7 decision.
 */
class ServerModel extends Model
{
    /**
     * Sortable columns for the admin server list (`searchAdmin()`); anything
     * else falls back to `status`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'ip', 'status', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'ip'];

    protected $table            = 'servers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'ip', 'status'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `UserModel::findUser()`.
     */
    public function findServer(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Admin list search (`Admin\Servers::index()`): keyword LIKE across
     * name/ip, optional `status` filter. `id` is always a secondary sort key
     * in the same direction so ties resolve deterministically across pages —
     * mirrors `UserModel::searchAdmin()`. Soft-deleted rows are excluded
     * automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string, status?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'status', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'status';
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string, status?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * The one place `keyword`/`status` get translated into WHERE clauses for
     * the admin list — shared by `searchAdmin()`/`countAdmin()` so the
     * paginated rows and the total can't drift apart.
     *
     * @param array{keyword?: string, status?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['0', '1'], true)) {
            $builder->where('status', (int) $status);
        }

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
}
