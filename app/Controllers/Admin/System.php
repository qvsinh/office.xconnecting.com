<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;
use App\Models\NotificationModel;
use App\Models\UserModel;
use CodeIgniter\CodeIgniter;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Handlers\DatabaseHandler;
use ReflectionClass;
use Throwable;

/**
 * Read-only System landing: environment/info + record counts (`index()`)
 * and database migration status (`migrations()`). Gated by
 * `authFilter` + `adminFilter` (see Config/Routes.php). `runMigrations()` and
 * `rollbackMigration()` are the only writers (CSRF-guarded POSTs, audit-logged);
 * migration `down()` methods are tests-only guarded, so a live rollback never
 * drops data (see rollbackMigration()).
 *
 * **Never render a secret.** `index()` builds an explicit whitelist of
 * display values only — never iterate `getenv()`/`$_ENV` or a config
 * object's properties, which could leak a credential by accident.
 */
class System extends BaseController
{
    /**
     * Allowlisted `ini_get()` directives for the PHP Configuration panel — no path/log directives.
     */
    private const PHP_CONFIG_ALLOWLIST = [
        'memory_limit',
        'max_execution_time',
        'max_input_time',
        'upload_max_filesize',
        'post_max_size',
        'max_input_vars',
        'default_socket_timeout',
        'date.timezone',
        'display_errors',
        'error_reporting',
        'opcache.enable',
        'opcache.memory_consumption',
        'session.gc_maxlifetime',
    ];

    public function index(): string
    {
        return view('admin/system/info', $this->buildInfoData() + [
            'runtime' => $this->buildRuntimeDetails(),
            'session' => $this->buildSessionSummary(),
        ]);
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
     * Runtime-introspection whitelist for the System info page — OPcache
     * summary, PHP config, server info, loaded extensions. Same discipline as
     * `buildInfoData()`: every value is hand-picked, nothing ever comes from
     * iterating `$_ENV`/`$_SERVER`/`ini_get_all()`/`phpinfo()`.
     *
     * @return array{opcache: array<string, mixed>, php_config: array<string, mixed>, server: array<string, mixed>, extensions: list<array{name: string, version: string}>}
     */
    public function buildRuntimeDetails(): array
    {
        return [
            'opcache'    => $this->buildOpcacheSummary(),
            'php_config' => $this->buildPhpConfigSummary(),
            'server'     => $this->buildServerSummary(),
            'extensions' => $this->buildExtensionsList(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOpcacheSummary(): array
    {
        if (! function_exists('opcache_get_status')) {
            return ['available' => false];
        }

        // ponytail: pass `false` — the per-script array leaks absolute deploy paths and is never needed here.
        $status = opcache_get_status(false);

        if ($status === false) {
            return ['available' => false];
        }

        $memory = $status['memory_usage'] ?? [];
        $stats  = $status['opcache_statistics'] ?? [];

        return [
            'available'          => true,
            'enabled'            => (bool) ($status['opcache_enabled'] ?? false),
            'memory_used'        => (int) ($memory['used_memory'] ?? 0),
            'memory_free'        => (int) ($memory['free_memory'] ?? 0),
            'memory_wasted'      => (int) ($memory['wasted_memory'] ?? 0),
            'memory_wasted_pct'  => (float) ($memory['current_wasted_percentage'] ?? 0.0),
            'hit_rate'           => (float) ($stats['opcache_hit_rate'] ?? 0.0),
            'hits'               => (int) ($stats['hits'] ?? 0),
            'misses'             => (int) ($stats['misses'] ?? 0),
            'num_cached_scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
            'num_cached_keys'    => (int) ($stats['num_cached_keys'] ?? 0),
            'oom_restarts'       => (int) ($stats['oom_restarts'] ?? 0),
            'manual_restarts'    => (int) ($stats['manual_restarts'] ?? 0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildPhpConfigSummary(): array
    {
        $config = [];

        foreach (self::PHP_CONFIG_ALLOWLIST as $directive) {
            $value              = ini_get($directive);
            $config[$directive] = $value === false ? '' : $value;
        }

        return $config;
    }

    /**
     * @return array<string, string>
     */
    private function buildServerSummary(): array
    {
        return [
            'php_version'     => PHP_VERSION,
            'php_sapi'        => PHP_SAPI,
            'os'              => php_uname('s'),
            'os_release'      => php_uname('r'),
            'architecture'    => php_uname('m'),
            'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            'server_time'     => date('Y-m-d H:i:s'),
            'server_timezone' => date_default_timezone_get(),
        ];
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function buildExtensionsList(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return array_map(
            static fn (string $ext): array => ['name' => $ext, 'version' => (string) (phpversion($ext) ?: '')],
            $extensions,
        );
    }

    /**
     * Admin-only, CSRF-guarded reset of the OPcache in-memory cache
     * (`POST system/opcache/reset`). No-op with a clear message when OPcache
     * isn't loaded — this must never fatal on a CLI/test SAPI.
     */
    public function resetOpcache(): ResponseInterface
    {
        if (! function_exists('opcache_reset')) {
            return $this->jsonFailed('OPcache is not enabled.');
        }

        if (! opcache_reset()) {
            return $this->jsonFailed('OPcache reset failed.', 500);
        }

        return $this->jsonSuccess('OPcache reset.');
    }

    /**
     * Admin-only, CSRF-guarded `POST system/cache/clear` — flushes the CI4
     * cache store (equivalent to `php spark cache:clear`). Audit-logged.
     */
    public function clearCache(): ResponseInterface
    {
        try {
            if (! cache()->clean()) {
                return $this->jsonFailed('Cache clear failed.', 500);
            }
        } catch (Throwable $e) {
            log_message('error', 'Admin cache clear failed: ' . $e->getMessage());

            return $this->jsonFailed('Cache clear failed: ' . $e->getMessage(), 500);
        }

        log_message('info', 'Admin #{adminId} cleared the cache.', [
            'adminId' => session('userAuthenticated')->id ?? null,
        ]);

        return $this->jsonSuccess('Cache cleared.');
    }

    /**
     * Session-store summary for the System info page: the active handler and,
     * when sessions live in the database, the store table + its current row
     * count (active sessions). File/Redis/etc. handlers report the driver only.
     *
     * @return array{driver: string, is_database: bool, table: ?string, rows: ?int}
     */
    public function buildSessionSummary(): array
    {
        $config = config('Session');
        $driver = $config->driver;

        $summary = [
            'driver'      => (new ReflectionClass($driver))->getShortName(),
            'is_database' => $driver === DatabaseHandler::class,
            'table'       => null,
            'rows'        => null,
        ];

        if ($summary['is_database']) {
            $table            = ((string) $config->savePath) !== '' ? (string) $config->savePath : 'ci_sessions';
            $db               = db_connect();
            $summary['table'] = $table;
            $summary['rows']  = $db->tableExists($table) ? $db->table($table)->countAllResults() : null;
        }

        return $summary;
    }

    /**
     * Admin-only, CSRF-guarded `POST system/sessions/reset` — clears every row
     * from the database session store (only when the DB session handler is
     * active). This logs EVERYONE out, including the acting admin; the UI
     * warns accordingly. Audit-logged.
     */
    public function resetSessions(): ResponseInterface
    {
        $config = config('Session');

        if ($config->driver !== DatabaseHandler::class) {
            return $this->jsonFailed('Sessions are not stored in the database.');
        }

        $table = ((string) $config->savePath) !== '' ? (string) $config->savePath : 'ci_sessions';
        $db    = db_connect();

        if (! $db->tableExists($table)) {
            return $this->jsonFailed('Session table not found.', 404);
        }

        try {
            $db->table($table)->truncate();
        } catch (Throwable $e) {
            log_message('error', 'Session reset failed: ' . $e->getMessage());

            return $this->jsonFailed('Session reset failed.', 500);
        }

        log_message('info', 'Admin #{adminId} reset all database sessions.', [
            'adminId' => session('userAuthenticated')->id ?? null,
        ]);

        return $this->jsonSuccess('All sessions have been reset.');
    }

    /**
     * Admin-only, CSRF-guarded `POST system/migrations/run` — applies all
     * pending migrations (equivalent to `php spark migrate`). Audit-logged.
     */
    public function runMigrations(): ResponseInterface
    {
        try {
            service('migrations')->latest();
        } catch (Throwable $e) {
            log_message('error', 'Admin migration run failed: ' . $e->getMessage());

            return $this->jsonFailed('Migration failed: ' . $e->getMessage(), 500);
        }

        log_message('info', 'Admin #{adminId} ran database migrations.', [
            'adminId' => session('userAuthenticated')->id ?? null,
        ]);

        return $this->jsonSuccess('Migrations are up to date.');
    }

    /**
     * Admin-only, CSRF-guarded `POST system/migrations/rollback` — regresses
     * the most recent migration batch (`php spark migrate:rollback`).
     * Audit-logged.
     *
     * Safety: every app migration's `down()` is guarded to run only on the
     * dedicated tests DB (see the CreateXxx migrations), so on dev/live this
     * un-records the last batch WITHOUT dropping tables or data — re-running
     * "Run" re-applies it idempotently. The UI warns accordingly.
     */
    public function rollbackMigration(): ResponseInterface
    {
        try {
            service('migrations')->regress(-1);
        } catch (Throwable $e) {
            log_message('error', 'Admin migration rollback failed: ' . $e->getMessage());

            return $this->jsonFailed('Rollback failed: ' . $e->getMessage(), 500);
        }

        log_message('info', 'Admin #{adminId} rolled back the last migration batch.', [
            'adminId' => session('userAuthenticated')->id ?? null,
        ]);

        return $this->jsonSuccess('Rolled back the last migration batch.');
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
}
