<?php
/**
 * Read-only migration status: every defined migration merged with the
 * applied history from the framework `migrations` table (see
 * Admin\System::buildMigrationRows()). No run/rollback controls here by
 * design — destructive migration actions stay on the CLI (kanban plan §7).
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
<div class="mx-auto max-w-6xl">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">Database migrations</h1>
        <p class="mt-1 text-sm text-[#787774]">Applied vs pending, read-only. Run/rollback stays on the CLI (<code class="font-mono">php spark migrate</code>).</p>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4">
        <div class="min-w-[240px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Class</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($keyword, 'attr') ?>"
                   placeholder="Migration class name"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
        </div>
        <div>
            <label for="limit" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Per page</label>
            <select id="limit" name="limit" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50">Search</button>
        <a href="?limit=<?= esc((string) $limit, 'attr') ?>" class="text-sm text-[#787774] hover:text-[#111111]">Clear</a>
    </form>

    <div class="mt-4 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774]">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('class'), 'attr') ?>" class="hover:text-[#111111]">Class<?= esc($sortMark('class')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('version'), 'attr') ?>" class="hover:text-[#111111]">Version<?= esc($sortMark('version')) ?></a></th>
                    <th class="px-4 py-3">Namespace</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('batch'), 'attr') ?>" class="hover:text-[#111111]">Batch<?= esc($sortMark('batch')) ?></a></th>
                    <th class="px-4 py-3">Applied at</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('status'), 'attr') ?>" class="hover:text-[#111111]">Status<?= esc($sortMark('status')) ?></a></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA]">
                <?php foreach ($items as $row) : ?>
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><?= esc($row['class']) ?></td>
                        <td class="px-4 py-3 font-mono text-xs"><?= esc($row['version']) ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774]"><?= esc($row['namespace']) ?></td>
                        <td class="px-4 py-3"><?= $row['batch'] !== null ? esc((string) $row['batch']) : '—' ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774]">
                            <?= $row['applied_at'] !== null ? esc(date('Y-m-d H:i:s', (int) $row['applied_at'])) : '—' ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide
                                <?= $row['status'] === 'applied' ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FBF3DB] text-[#956400]' ?>">
                                <?= esc($row['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774]">No migrations match your filters.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774]">
        <p><?= esc((string) $total) ?> migration<?= $total === 1 ? '' : 's' ?> total</p>
        <div class="flex items-center gap-2">
            <?php if ($page > 1) : ?>
                <a href="<?= esc($pageUrl($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50">Prev</a>
            <?php endif ?>
            <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
            <?php if ($page < $totalPages) : ?>
                <a href="<?= esc($pageUrl($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50">Next</a>
            <?php endif ?>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
