<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;
use App\Models\NotificationModel;
use App\Models\UserModel;
use CodeIgniter\CodeIgniter;

/**
 * Read-only System landing: environment/info + record counts (`index()`)
 * and database migration status (`migrations()`). Gated by
 * `authFilter` + `adminFilter` (see Config/Routes.php). Neither method
 * writes anything — destructive migration run/rollback stays on the CLI
 * (kanban plan §7).
 *
 * **Never render a secret.** `index()` builds an explicit whitelist of
 * display values only — never iterate `getenv()`/`$_ENV` or a config
 * object's properties, which could leak a credential by accident.
 */
class System extends BaseController
{
    public function index(): string
    {
        return view('admin/system/info', $this->buildInfoData());
    }

    /**
     * The system-info whitelist itself — split out from `index()` so it can
     * be unit-tested (no-secrets-leak) without needing the view that renders
     * it. Touches no `$this->request`/`$this->response` state, only global
     * CI4 accessors, so it's safe to call on a bare instance in tests.
     *
     * **Never add a key here from `getenv()`/`$_ENV`/a config object's raw
     * properties** — every value is picked explicitly, so a credential can't
     * leak in by iterating something broader.
     *
     * @return array<string, mixed>
     */
    public function buildInfoData(): array
    {
        $db = db_connect();

        return [
            'ci_version'  => CodeIgniter::CI_VERSION,
            'php_version' => PHP_VERSION,
            'environment' => ENVIRONMENT,
            'db_driver'   => $db->DBDriver,
            'db_database' => $db->database,
            'counts'      => [
                'users'           => model(UserModel::class)->countAllResults(),
                'api_access_keys' => model(ApiAccessKeyModel::class)->countAllResults(),
                'api_history'     => model(ApiHistoryModel::class)->countAllResults(),
                'notifications'   => model(NotificationModel::class)->countAllResults(),
            ],
            'writable' => [
                'writable' => is_writable(WRITEPATH),
                'logs'     => is_writable(WRITEPATH . 'logs/'),
                'cache'    => is_writable(WRITEPATH . 'cache/'),
                'session'  => is_writable(WRITEPATH . 'session/'),
            ],
        ];
    }

    /**
     * Read-only migration status: every defined migration (`findMigrations()`)
     * merged with the applied history (`getHistory()`, backed by the
     * framework `migrations` table) — both keyed by `getObjectUid()` so a
     * migration and its history row match the same way the runner itself
     * matches them. No run/rollback here by design.
     */
    public function migrations(): string
    {
        $rows = $this->buildMigrationRows();

        $keyword = trim((string) $this->request->getGet('keyword'));
        if ($keyword !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains(strtolower($row['class']), strtolower($keyword)),
            ));
        }

        $sortBy  = (string) $this->request->getGet('sort_by');
        $sortBy  = in_array($sortBy, ['version', 'batch', 'status', 'class'], true) ? $sortBy : 'version';
        $sortDir = strtolower((string) $this->request->getGet('sort')) === 'asc' ? 1 : -1;

        usort($rows, static fn (array $a, array $b): int => $sortDir * ($a[$sortBy] <=> $b[$sortBy]));

        $page  = max(1, (int) $this->request->getGet('page'));
        $limit = $this->resolveLimit();
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $limit, $limit);

        return view('admin/system/migrations', [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'keyword' => $keyword,
            'sortBy'  => $sortBy,
            'sort'    => $sortDir === 1 ? 'asc' : 'desc',
        ]);
    }

    /**
     * Every defined migration (`findMigrations()`) merged with the applied
     * history (`getHistory()`, backed by the framework `migrations` table),
     * both keyed by `getObjectUid()` — the same key the runner itself uses
     * to skip already-applied migrations in `latest()`, so this can never
     * disagree with what the runner would actually do. Split out from
     * `migrations()` so the merge/status logic is unit-testable without a
     * request or the view that renders it.
     *
     * @return list<array{class: string, version: string, namespace: string, batch: int|null, applied_at: int|null, status: string}>
     */
    public function buildMigrationRows(): array
    {
        $runner  = service('migrations');
        $applied = [];

        foreach ($runner->getHistory() as $row) {
            $applied[$runner->getObjectUid($row)] = $row;
        }

        $rows = [];

        foreach ($runner->findMigrations() as $uid => $migration) {
            $history = $applied[$uid] ?? null;

            $rows[] = [
                'class'      => $migration->class,
                'version'    => $migration->version,
                'namespace'  => $migration->namespace,
                'batch'      => $history->batch ?? null,
                'applied_at' => $history->time ?? null,
                'status'     => $history !== null ? 'applied' : 'pending',
            ];
        }

        return $rows;
    }

    private function resolveLimit(): int
    {
        $limitGet = (int) $this->request->getGet('limit');

        if ($limitGet > 0) {
            return min($limitGet, 100); // clamp: unbounded limit would let one request pull the whole list
        }

        $default = (int) getenv('app.rpp_default');

        return $default > 0 ? min($default, 100) : 25;
    }
}
