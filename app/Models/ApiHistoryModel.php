<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use Throwable;

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
     * Listing-style commands whose call is skipped from `api_history` when a
     * `limit` param is present (pagination noise), per `record()`'s gate.
     */
    private const SKIP_LOG_LISTING_COMMANDS = [
        'QueryEventList',
        'QueryDNSZoneList',
        'QueryContactList',
        'QueryDomainList',
    ];

    /**
     * Chunk size for soft-deleting a filtered/aged set of rows.
     */
    private const DELETE_CHUNK = 500;

    /**
     * Sortable columns for `searchScoped()` — the query behind the Bearer
     * proxy's and the web console's `command=History` shortcut. Deliberately
     * narrower than `SORT_ALLOWLIST` (no `service_name`/`service_id`/
     * `domain` — those are already fixed by the scope, not sortable there).
     */
    public const SCOPED_SORT_ALLOWLIST = ['created_at', 'id', 'status', 'command'];

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
     * Persist a proxy/console call to `api_history`, gated by the key's
     * `enable_log`. Never throws into the caller — logging failures are
     * logged and swallowed. This is the single root both `CnrClient` and
     * `JprsClient` call (ponytail: was duplicated per-caller as
     * `ApiController::logApiCall()`, moved here so the gate/strip rules
     * can't drift between callers).
     *
     * Expected `$row` keys: user_id, env, service_name, service_id, url,
     * command, domain, params (array|string), response (array|string),
     * metadata, status.
     */
    public function record(array $row, bool $enableLog): void
    {
        if (! $enableLog) {
            return;
        }

        $command    = $row['command'] ?? null;
        $callParams = $row['params'] ?? null;

        if (in_array($command, self::SKIP_LOG_LISTING_COMMANDS, true)
            && is_array($callParams)
            && isset($callParams['limit'])
        ) {
            return;
        }

        if (is_array($callParams)) {
            unset($callParams['AgentLoginID'], $callParams['OperationPassword'], $callParams['AgentLoginPassword']);
        }

        $domain = $row['domain'] ?? null;
        if (empty($domain) && is_array($callParams)) {
            $domain = $callParams['dnszone'] ?? null;
        }

        $response = $row['response'] ?? null;

        $insert = [
            'user_id'      => $row['user_id'] ?? null,
            'env'          => $row['env'] ?? null,
            'service_name' => $row['service_name'] ?? null,
            'service_id'   => $row['service_id'] ?? null,
            'url'          => $row['url'] ?? null,
            'status'       => $row['status'] ?? 1,
            'command'      => $command,
            'domain'       => $domain,
            'params'       => is_string($callParams) ? $callParams : json_encode($callParams, JSON_PRETTY_PRINT),
            'response'     => is_string($response) ? $response : json_encode($response, JSON_PRETTY_PRINT),
            'metadata'     => $row['metadata'] ?? null,
        ];

        try {
            $this->insert($insert);
        } catch (Throwable $e) {
            log_message('error', 'Failed to log API history: ' . $e->getMessage());
        }
    }

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
     * Filtered/sorted/paginated read scoped to one user's single service —
     * the exact query behind the Bearer proxy's `command=History` shortcut
     * (`Api\ApiController::fetchHistory()`) and the web console's `History`
     * shortcut (`ConsoleController`), kept as one root so the two can't
     * drift apart (ponytail: was inline in `fetchHistory()` alone; extracted
     * here once the console needed the same read).
     *
     * @return list<array<string, mixed>>
     */
    public function searchScoped(
        string $serviceName,
        int $serviceId,
        int $userId,
        string $sortBy,
        string $sortDir,
        int $limit,
        int $offset,
        string $keyword = '',
        string $domain = '',
    ): array {
        $sortColumn = in_array($sortBy, self::SCOPED_SORT_ALLOWLIST, true) ? $sortBy : 'created_at';
        $sortOrder  = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $builder = $this->builder();
        $builder->select('id, user_id, env, service_name, service_id, url, status, command, domain, params, response, metadata, created_at')
            ->where('service_name', $serviceName)
            ->where('service_id', $serviceId)
            ->where('user_id', $userId) // per-user tenancy: a caller sees only their own history
            ->where('deleted_at', null); // respect soft-deletes (builder() bypasses the model scope)

        if ($domain !== '') {
            $builder->like('domain', $domain);
        }

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('response', $keyword)
                ->orLike('params', $keyword)
                ->orLike('command', $keyword)
                ->groupEnd();
        }

        return $builder->orderBy($sortColumn, $sortOrder)->get($limit, $offset)->getResultArray();
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
