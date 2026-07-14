<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class ApiHistoryModel extends Model
{
    /**
     * Sortable columns for the history viewer; anything else falls back to `created_at`.
     */
    public const SORT_ALLOWLIST = ['id', 'service_name', 'service_id', 'command', 'domain', 'status', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term.
     */
    private const KEYWORD_FIELDS = ['service_name', 'command', 'domain', 'url', 'params', 'response'];

    /**
     * Chunk size for soft-deleting a filtered/aged set of rows.
     */
    private const DELETE_CHUNK = 500;

    protected $table            = 'api_history';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'env',
        'service_name',
        'service_id',
        'user_id',
        'url',
        'command',
        'domain',
        'params',
        'response',
        'metadata',
        'status',
    ];
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $deletedField   = 'deleted_at';
    protected $skipValidation = false;

    /**
     * Filtered, sorted, paginated read. `$filters['user_id']`, when present,
     * scopes to one user (the client controller always sets it; the admin
     * controller omits it for "all users" or sets it after resolving its
     * `user` filter). Soft-deleted rows are excluded automatically by
     * `useSoftDeletes` — never hand-add `deleted_at IS NULL` here.
     *
     * @param array{user_id?: int, keyword?: string, service_name?: string, service_id?: int|string, env?: string, status?: int|string} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function searchHistory(array $filters, string $sort = 'created_at', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->applyHistoryFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'created_at';
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        // `id` is a secondary sort key in the same direction so ties (e.g. two
        // rows created within the same second) always resolve the same way —
        // required for stable pagination, not just cosmetic.
        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{user_id?: int, keyword?: string, service_name?: string, service_id?: int|string, env?: string, status?: int|string} $filters
     */
    public function countHistory(array $filters): int
    {
        $this->applyHistoryFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * Fetch a row by `id` AND `user_id` (non-deleted). Returns null both when
     * the id doesn't exist and when it belongs to another user — the two
     * cases are indistinguishable to the caller (no existence leak, no IDOR).
     */
    public function findOwned(int $id, int $userId): ?array
    {
        return $this->where('user_id', $userId)->find($id);
    }

    /**
     * Admin read by `id` only (still excludes soft-deleted rows).
     */
    public function findByIdAdmin(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Soft-delete a single row, scoped to its owner. Returns false (no
     * mutation) if the row is missing or owned by someone else.
     */
    public function deleteOwned(int $id, int $userId): bool
    {
        if ($this->findOwned($id, $userId) === null) {
            return false;
        }

        return (bool) $this->delete($id);
    }

    /**
     * Soft-delete every row matching `$filters` (chunked ~500 at a time so a
     * large match set doesn't build one huge IN() delete). Returns the count
     * soft-deleted. `$filters['user_id']` scopes to one user; omit for all
     * users (admin "delete all matching current filters").
     *
     * @param array{user_id?: int, keyword?: string, service_name?: string, service_id?: int|string, env?: string, status?: int|string} $filters
     */
    public function deleteFiltered(array $filters): int
    {
        return $this->chunkedSoftDelete(function () use ($filters): BaseBuilder {
            $builder = $this->db->table($this->table)->where($this->deletedField, null);
            $this->applyHistoryFilters($builder, $filters);

            return $builder;
        });
    }

    /**
     * Soft-delete rows older than `$days`. User-scoped when `$userId` is
     * given, otherwise all users (not currently exposed on the admin side —
     * see kanban plan §7).
     */
    public function deleteByAge(int $days, ?int $userId = null): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->chunkedSoftDelete(function () use ($cutoff, $userId): BaseBuilder {
            $builder = $this->db->table($this->table)
                ->where($this->deletedField, null)
                ->where('created_at <', $cutoff);

            if ($userId !== null) {
                $builder->where('user_id', $userId);
            }

            return $builder;
        });
    }

    /**
     * Repeatedly select up to `DELETE_CHUNK` matching ids from a
     * caller-built (non-deleted-only) builder and soft-delete them, until no
     * rows remain. `$builderFactory` returns a fresh builder each iteration
     * — reusing one builder object across `get()` calls doesn't compose
     * correctly here since soft-deleted rows drop out of the next select.
     *
     * @param callable(): BaseBuilder $builderFactory
     */
    private function chunkedSoftDelete(callable $builderFactory): int
    {
        $total = 0;

        while (true) {
            $builder = $builderFactory();
            $ids     = array_column($builder->select('id')->limit(self::DELETE_CHUNK)->get()->getResultArray(), 'id');

            if ($ids === []) {
                break;
            }

            $this->delete($ids);
            $total += count($ids);
        }

        return $total;
    }

    /**
     * The one place `keyword`/`service_name`/`service_id`/`env`/`status`/
     * `user_id` get translated into WHERE clauses — reused by every
     * list/count/delete-filtered/delete-by-age path so filter semantics
     * can't drift between callers (ponytail: fix once, no per-caller copy).
     *
     * `keyword` is space-split into terms; each term must match at least one
     * of `KEYWORD_FIELDS` (`LIKE '%term%'`), terms are AND-ed together.
     *
     * @param array{user_id?: int, keyword?: string, service_name?: string, service_id?: int|string, env?: string, status?: int|string} $filters
     */
    private function applyHistoryFilters(BaseBuilder $builder, array $filters): void
    {
        if (isset($filters['user_id'])) {
            $builder->where('user_id', (int) $filters['user_id']);
        }

        $serviceName = trim((string) ($filters['service_name'] ?? ''));
        if ($serviceName !== '') {
            $builder->where('service_name', $serviceName);
        }

        $serviceId = trim((string) ($filters['service_id'] ?? ''));
        if ($serviceId !== '' && ctype_digit($serviceId)) {
            $builder->where('service_id', (int) $serviceId);
        }

        $env = trim((string) ($filters['env'] ?? ''));
        if ($env !== '') {
            $builder->where('env', $env);
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['0', '1'], true)) {
            $builder->where('status', (int) $status);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        foreach (preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $builder->groupStart();

            foreach (self::KEYWORD_FIELDS as $i => $field) {
                $i === 0 ? $builder->like($field, $term) : $builder->orLike($field, $term);
            }
            $builder->groupEnd();
        }
    }
}
