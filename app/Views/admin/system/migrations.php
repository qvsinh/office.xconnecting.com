<?php
/**
 * Migration status: every defined migration merged with the applied history
 * from the framework `migrations` table (see Admin\System::buildMigrationRows()).
 * "Run migrations" / "Rollback last batch" post to Admin\System::runMigrations()
 * / rollbackMigrations() via the shared postForm()/confirm/toast components.
 *
 * @var list<array{class:string, version:string, namespace:string, batch:int|null, applied_at:int|null, status:string}> $items
 * @var int                                                                                                             $total
 * @var int                                                                                                             $page
 * @var int                                                                                                             $limit
 * @var string                                                                                                          $keyword
 * @var string                                                                                                          $sortBy
 * @var string                                                                                                          $sort
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'keyword' => $keyword,
    'limit'   => $limit,
];

$buildQuery = static fn (array $extra): string => '?' . http_build_query(
    array_filter($queryBase + $extra, static fn ($v): bool => $v !== '' && $v !== null),
);

$pageUrl = static fn (int $target): string => $buildQuery(['page' => $target, 'sort_by' => $sortBy, 'sort' => $sort]);
$sortUrl = static fn (string $col): string => $buildQuery([
    'sort_by' => $col,
    'sort'    => ($sortBy === $col && strtolower($sort) === 'asc') ? 'desc' : 'asc',
]);
$sortMark = static function (string $col) use ($sortBy, $sort): string {
    if ($sortBy !== $col) {
        return '';
    }

    return strtolower($sort) === 'asc' ? ' ▲' : ' ▼';
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl" x-data="migrationsActions()">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Database migrations</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Applied vs pending status below. Use the actions to run pending migrations or roll back the last batch.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" :disabled="running" @click="confirmRollback()"
                    class="rounded-md border border-[#9F2F2D]/40 px-4 py-2 text-sm font-medium text-[#9F2F2D] hover:bg-[#FDEBEC] disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-400/40 dark:text-red-400 dark:hover:bg-red-900/20">
                Rollback last batch
            </button>
            <button type="button" :disabled="running" @click="confirmRun()"
                    class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                <span x-show="!running">Run migrations</span>
                <span x-show="running" x-cloak>Running…</span>
            </button>
        </div>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[240px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Class</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($keyword, 'attr') ?>"
                   placeholder="Migration class name"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
        </div>
        <div>
            <label for="limit" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Per page</label>
            <select id="limit" name="limit" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">Search</button>
        <button type="button" x-data @click="location.href='?limit=<?= esc((string) $limit, 'js') ?>'"
                class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium text-[#787774] hover:bg-gray-50 hover:text-[#111111] dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100">Clear</button>
    </form>

    <div class="mt-4 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('class'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Class<?= esc($sortMark('class')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('version'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Version<?= esc($sortMark('version')) ?></a></th>
                    <th class="px-4 py-3">Namespace</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('batch'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Batch<?= esc($sortMark('batch')) ?></a></th>
                    <th class="px-4 py-3">Applied at</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('status'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Status<?= esc($sortMark('status')) ?></a></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $row) : ?>
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs dark:text-gray-100"><?= esc($row['class']) ?></td>
                        <td class="px-4 py-3 font-mono text-xs dark:text-gray-100"><?= esc($row['version']) ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc($row['namespace']) ?></td>
                        <td class="px-4 py-3 dark:text-gray-100"><?= $row['batch'] !== null ? esc((string) $row['batch']) : '—' ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400">
                            <?= $row['applied_at'] !== null ? esc(date('Y-m-d H:i:s', (int) $row['applied_at'])) : '—' ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide
                                <?= $row['status'] === 'applied' ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300' ?>">
                                <?= esc($row['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No migrations match your filters.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> migration<?= $total === 1 ? '' : 's' ?> total</p>
        <div class="flex items-center gap-2">
            <?php if ($page > 1) : ?>
                <a href="<?= esc($pageUrl($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Prev</a>
            <?php endif ?>
            <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
            <?php if ($page < $totalPages) : ?>
                <a href="<?= esc($pageUrl($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Next</a>
            <?php endif ?>
        </div>
    </div>

    <script>
        function migrationsActions() {
            return {
                running: false,

                confirmRun() {
                    this.$dispatch('confirm', {
                        title: 'Run pending migrations',
                        body: 'Run pending migrations? This applies any new schema changes to the database.',
                        variant: 'primary',
                        confirmLabel: 'Run',
                        onConfirm: () => this.runMigrations(),
                    });
                },

                confirmRollback() {
                    this.$dispatch('confirm', {
                        title: 'Rollback last batch',
                        body: "Roll back the most recent migration batch? On this app, migration down() methods are guarded to run only on the tests database, so on live this un-records the last batch WITHOUT dropping tables or data — re-run 'Run migrations' to re-apply. Proceed?",
                        variant: 'danger',
                        confirmLabel: 'Rollback',
                        onConfirm: () => this.rollback(),
                    });
                },

                async runMigrations() {
                    this.running = true;
                    const json = await postForm('/admin/system/migrations/run', {});
                    this.running = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not run migrations.' });
                    }
                },

                async rollback() {
                    this.running = true;
                    const json = await postForm('/admin/system/migrations/rollback', {});
                    this.running = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not roll back the last batch.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
