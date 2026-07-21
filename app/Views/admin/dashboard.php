<?php
/**
 * Admin dashboard — company-wide overview: stat cards, task-priority
 * breakdown (CSS bars, no chart library), and recent/most-active lists.
 *
 * @var object                                                                                     $user             session('userWorking')
 * @var array{users:int, groups:int, workspaces:int, projects:int, tasks_open:int, tasks_done:int} $stats
 * @var array{low:int, normal:int, high:int, urgent:int}                                           $tasksByPriority
 * @var list<object>                                                                               $recentWorkspaces id/name/created_at, latest 5
 * @var list<object>                                                                               $recentProjects   id/name/created_at, latest 5
 * @var list<object>                                                                               $activeProjects   id/name/open_tasks, top 5 by open tasks
 */
$this->extend('layouts/app');

// Static icon markup (heroicons-outline, stroke="currentColor") — developer
// authored, not user data, no esc() needed. One card config array keeps the
// six near-identical card blocks from being copy-pasted six times.
$statCards = [
    [
        'label' => 'Users',
        'value' => (int) ($stats['users'] ?? 0),
        'href'  => '/admin/users',
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 0114 0" /></svg>',
    ],
    [
        'label' => 'Groups',
        'value' => (int) ($stats['groups'] ?? 0),
        'href'  => '/admin/groups',
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-3-3.87M7 20H2v-1a4 4 0 013-3.87m5.5-2.13a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6z" /></svg>',
    ],
    [
        'label' => 'Workspaces',
        'value' => (int) ($stats['workspaces'] ?? 0),
        'href'  => '/workspaces',
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h6a2 2 0 012 2v1H3V7z" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7z" /></svg>',
    ],
    [
        'label' => 'Projects',
        'value' => (int) ($stats['projects'] ?? 0),
        'href'  => null,
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6" /></svg>',
    ],
    [
        'label' => 'Open tasks',
        'value' => (int) ($stats['tasks_open'] ?? 0),
        'href'  => null,
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 0a2 2 0 002 2h2a2 2 0 002-2m-6 0H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 12h6m-6 4h6" /></svg>',
    ],
    [
        'label' => 'Done tasks',
        'value' => (int) ($stats['tasks_done'] ?? 0),
        'href'  => null,
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    ],
];

// Priority bars: same colour language as the kanban board pills
// (low=gray, normal=blue, high=amber, urgent=red) — see projects/board.php
// priorityClasses(). Width % = count / max(count) * 100, clamped so a lone
// non-zero priority still renders a visible bar.
$priorityOrder  = ['low', 'normal', 'high', 'urgent'];
$priorityColors = [
    'low'    => 'bg-gray-400',
    'normal' => 'bg-blue-500',
    'high'   => 'bg-amber-500',
    'urgent' => 'bg-red-500',
];
$priorityCounts = [];

foreach ($priorityOrder as $p) {
    $priorityCounts[$p] = (int) ($tasksByPriority[$p] ?? 0);
}
$priorityMax = max(1, ...array_values($priorityCounts));
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl">
    <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Welcome back, <?= esc($user->name ?? '') ?></h1>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Company-wide overview.</p>

    <div class="mt-6 grid grid-cols-2 gap-5 lg:grid-cols-4">
        <?php foreach ($statCards as $card) :
            $inner = '<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">' . $card['icon'] . '</span>'
                . '<span class="min-w-0"><span class="block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">' . esc($card['label']) . '</span>'
                . '<span class="mt-1 block text-2xl font-semibold text-gray-800 dark:text-white/90">' . esc((string) $card['value']) . '</span></span>';
            ?>
            <?php if ($card['href'] !== null) : ?>
                <a href="<?= esc($card['href'], 'attr') ?>" class="flex items-center gap-3 rounded-lg border border-[#EAEAEA] bg-white p-5 transition-colors hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600">
                    <?= $inner ?>
                </a>
            <?php else : ?>
                <div class="flex items-center gap-3 rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <?= $inner ?>
                </div>
            <?php endif ?>
        <?php endforeach ?>
    </div>

    <!-- Tasks by priority -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Tasks by priority</h2>
        <?php if (array_sum($priorityCounts) === 0) : ?>
            <p class="mt-3 text-sm text-[#787774] dark:text-gray-400">No tasks yet.</p>
        <?php else : ?>
            <div class="mt-4 space-y-3">
                <?php foreach ($priorityOrder as $p) :
                    $count = $priorityCounts[$p];
                    $pct   = (int) round($count / $priorityMax * 100);
                    ?>
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium capitalize text-gray-700 dark:text-gray-300"><?= esc($p) ?></span>
                            <span class="text-[#787774] dark:text-gray-400"><?= esc((string) $count) ?></span>
                        </div>
                        <div class="mt-1 h-2 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                            <!--
                                ponytail: inline style is the only way to express a
                                runtime-computed percentage — Tailwind's CLI scans
                                view source text for literal class names, so a
                                PHP-interpolated w-[<?= $pct ?>%] would never be
                                generated at build time. Same precedent as the
                                dynamic project-color swatch in projects/show.php.
                                Value is server-computed from int counts, not user
                                input, and clamped 0-100 above.
                            -->
                            <div class="h-2 rounded-full <?= esc($priorityColors[$p]) ?>" style="width: <?= max(0, min(100, $pct)) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>

    <!-- Recent workspaces / recent projects -->
    <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div class="rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
            <h2 class="border-b border-[#EAEAEA] px-5 py-3 text-base font-semibold text-gray-800 dark:border-gray-700 dark:text-white/90">Recent workspaces</h2>
            <?php if ($recentWorkspaces === []) : ?>
                <p class="px-5 py-6 text-center text-sm text-[#787774] dark:text-gray-400">No workspaces yet.</p>
            <?php else : ?>
                <ul>
                    <?php foreach ($recentWorkspaces as $ws) : ?>
                        <li class="border-b border-[#EAEAEA] last:border-b-0 dark:border-gray-700">
                            <a href="/workspaces/<?= (int) $ws->id ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="truncate text-sm font-medium text-gray-800 dark:text-white/90"><?= esc($ws->name ?? '') ?></span>
                                <span class="shrink-0 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) ($ws->created_at ?? '—')) ?></span>
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
            <h2 class="border-b border-[#EAEAEA] px-5 py-3 text-base font-semibold text-gray-800 dark:border-gray-700 dark:text-white/90">Recent projects</h2>
            <?php if ($recentProjects === []) : ?>
                <p class="px-5 py-6 text-center text-sm text-[#787774] dark:text-gray-400">No projects yet.</p>
            <?php else : ?>
                <ul>
                    <?php foreach ($recentProjects as $p) : ?>
                        <li class="border-b border-[#EAEAEA] last:border-b-0 dark:border-gray-700">
                            <a href="/projects/<?= (int) $p->id ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="truncate text-sm font-medium text-gray-800 dark:text-white/90"><?= esc($p->name ?? '') ?></span>
                                <span class="shrink-0 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) ($p->created_at ?? '—')) ?></span>
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>

    <!-- Most active projects -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <h2 class="border-b border-[#EAEAEA] px-5 py-3 text-base font-semibold text-gray-800 dark:border-gray-700 dark:text-white/90">Most active projects</h2>
        <?php if ($activeProjects === []) : ?>
            <p class="px-5 py-6 text-center text-sm text-[#787774] dark:text-gray-400">No open tasks anywhere yet.</p>
        <?php else : ?>
            <ul>
                <?php foreach ($activeProjects as $p) : ?>
                    <li class="border-b border-[#EAEAEA] last:border-b-0 dark:border-gray-700">
                        <a href="/projects/<?= (int) $p->id ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <span class="truncate text-sm font-medium text-gray-800 dark:text-white/90"><?= esc($p->name ?? '') ?></span>
                            <span class="shrink-0 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                <?= esc((string) ($p->open_tasks ?? 0)) ?> open
                            </span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</div>
<?php $this->endSection() ?>
