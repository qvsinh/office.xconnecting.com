<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `scheduled_tasks` table (see
 * kanban/processing/20260714-admin-scheduled-tasks.md) — recurring
 * maintenance-job schedules. `handler` is only ever an allowlist KEY looked
 * up in `ScheduledTaskRunner::registry()`; this model is data-layer only, it
 * never dispatches or reflects a handler itself.
 */
class ScheduledTaskModel extends Model
{
    /**
     * Sortable columns for the admin scheduled-tasks list (`searchAdmin()`);
     * anything else falls back to `name`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'handler', 'next_run_at', 'last_run_at', 'enabled'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'handler', 'description'];

    /**
     * Bound length for `last_result` — a chatty/failing handler must not grow
     * `LONGTEXT` unboundedly (plan §4.7).
     */
    private const RESULT_MAX_LENGTH = 5000;

    protected $table            = 'scheduled_tasks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name', 'handler', 'params', 'interval_minutes', 'enabled',
        'last_run_at', 'next_run_at', 'last_status', 'last_result',
        'last_duration_ms', 'description',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `ServerModel::findServer()`.
     */
    public function findTask(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Admin list search (`Admin\ScheduledTasks::index()`): keyword LIKE
     * across name/handler/description, optional exact `handler` filter,
     * optional exact `enabled` filter. `id` is always a secondary sort key in
     * the same direction so ties resolve deterministically across pages —
     * mirrors `TranslationModel::searchAdmin()`.
     *
     * @param array{keyword?: string, handler?: string, enabled?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'name', string $order = 'asc', int $limit = 25, int $offset = 0): array
    {
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
     * @param array{keyword?: string, handler?: string, enabled?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * The one place `keyword`/`handler`/`enabled` get translated into WHERE
     * clauses for the admin list — shared by `searchAdmin()`/`countAdmin()`
     * so the paginated rows and the total can't drift apart.
     *
     * @param array{keyword?: string, handler?: string, enabled?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $handler = trim((string) ($filters['handler'] ?? ''));
        if ($handler !== '') {
            $builder->where('handler', $handler);
        }

        $enabled = (string) ($filters['enabled'] ?? '');
        if (in_array($enabled, ['0', '1'], true)) {
            $builder->where('enabled', (int) $enabled);
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

    /**
     * Rows due to run now: enabled, and either never run (`next_run_at` is
     * NULL) or due (`next_run_at <= now`). Feeds `schedule:run` and is the
     * only place that decides "is this due" — the runner never re-derives it.
     *
     * @return list<object>
     */
    public function dueTasks(): array
    {
        return $this->where('enabled', 1)
            ->groupStart()
            ->where('next_run_at', null)
            ->orWhere('next_run_at <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->orderBy('next_run_at', 'ASC')
            ->findAll();
    }

    /**
     * Marks a task as currently executing — set before `ScheduledTaskRunner`
     * dispatches the handler, so a crash mid-run (fatal error outside the
     * handler's own try/catch) still leaves a visible `last_status` rather
     * than a stale prior value.
     */
    public function markRunning(int $id): void
    {
        $this->update($id, [
            'last_status' => 'running',
            'last_run_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Records the outcome of a run: status, truncated result, duration, and
     * the next due time. The one place `last_result` gets bounded — never
     * truncate ad hoc in the runner too (single source of truth).
     */
    public function recordResult(int $id, string $status, string $result, int $durationMs, string $nextRunAt): void
    {
        $this->update($id, [
            'last_status'      => $status,
            'last_result'      => mb_substr($result, 0, self::RESULT_MAX_LENGTH),
            'last_duration_ms' => $durationMs,
            'next_run_at'      => $nextRunAt,
        ]);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->update($id, ['enabled' => $enabled ? 1 : 0]);
    }
}
