<?php
/**
 * Personal dashboard — the signed-in user's own workspaces/projects/tasks.
 *
 * @var object                                                            $user         session('userWorking')
 * @var array{workspaces:int, projects:int, open_tasks:int, due_soon:int} $stats
 * @var list<object>                                                      $myTasks      title/project_id/project_name/priority/due_date/is_overdue
 * @var list<object>                                                      $myWorkspaces id/name/role/member_count/project_count
 */
$this->extend('layouts/app');

// Same priority-pill colour language as the kanban board
// (projects/board.php priorityClasses()) — low=gray, normal=blue,
// high=amber, urgent=red.
$priorityBadge = static fn (string $priority): string => match ($priority) {
    'low'    => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    'high'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'urgent' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    default  => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300', // normal
};

$roleBadge = static fn (string $role): string => match ($role) {
    'owner'  => 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/30 dark:text-yellow-300',
    'admin'  => 'bg-[#E1F3FE] text-[#1F6C9F] dark:bg-blue-900/30 dark:text-blue-300',
    'member' => 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-300',
    default  => 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300',
};

$statCards = [
    [
        'label' => 'My workspaces',
        'value' => (int) ($stats['workspaces'] ?? 0),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h6a2 2 0 012 2v1H3V7z" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7z" /></svg>',
    ],
    [
        'label' => 'My projects',
        'value' => (int) ($stats['projects'] ?? 0),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6" /></svg>',
    ],
    [
        'label' => 'My open tasks',
        'value' => (int) ($stats['open_tasks'] ?? 0),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 0a2 2 0 002 2h2a2 2 0 002-2m-6 0H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 12h6m-6 4h6" /></svg>',
    ],
    [
        'label' => 'Due soon',
        'value' => (int) ($stats['due_soon'] ?? 0),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    ],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl">
    <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Welcome back, <?= esc($user->name ?? '') ?></h1>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Your workspaces, projects and tasks.</p>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <?php foreach ($statCards as $card) : ?>
            <div class="flex items-center gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 sm:p-5 dark:border-gray-700 dark:bg-gray-800">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                    <?= $card['icon'] ?>
                </span>
                <span class="min-w-0">
                    <span class="block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc($card['label']) ?></span>
                    <span class="mt-1 block text-2xl font-semibold text-gray-800 dark:text-white/90"><?= esc((string) $card['value']) ?></span>
                </span>
            </div>
        <?php endforeach ?>
    </div>

    <!-- My tasks -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <h2 class="border-b border-[#EAEAEA] px-5 py-3 text-base font-semibold text-gray-800 dark:border-gray-700 dark:text-white/90">My tasks</h2>
        <?php if ($myTasks === []) : ?>
            <p class="px-5 py-6 text-center text-sm text-[#787774] dark:text-gray-400">No tasks assigned to you.</p>
        <?php else : ?>
            <ul>
                <?php foreach ($myTasks as $task) :
                    $overdue = (bool) ($task->is_overdue ?? false);
                    ?>
                    <li class="border-b border-[#EAEAEA] last:border-b-0 dark:border-gray-700">
                        <a href="/projects/<?= (int) ($task->project_id ?? 0) ?>"
                           class="flex flex-col gap-2 px-5 py-3 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-gray-700/40">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-gray-800 dark:text-white/90"><?= esc($task->title ?? '') ?></span>
                                <span class="block truncate text-xs text-[#787774] dark:text-gray-400"><?= esc($task->project_name ?? '—') ?></span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize <?= $priorityBadge((string) ($task->priority ?? 'normal')) ?>">
                                    <?= esc((string) ($task->priority ?? 'normal')) ?>
                                </span>
                                <?php if (! empty($task->due_date)) : ?>
                                    <span class="text-xs <?= $overdue ? 'font-medium text-red-600 dark:text-red-400' : 'text-[#787774] dark:text-gray-400' ?>">
                                        <?= esc((string) $task->due_date) ?>
                                    </span>
                                <?php endif ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>

    <!-- My workspaces -->
    <?php if ($myWorkspaces === []) : ?>
        <div class="mt-6 rounded-lg border border-dashed border-[#EAEAEA] bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm font-medium text-gray-800 dark:text-white/90">You're not in any workspace yet.</p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Create one to start organizing projects and tasks with your team.</p>
            <a href="/workspaces" class="btn-primary mt-4 inline-block">Create your first workspace</a>
        </div>
    <?php else : ?>
        <div class="mt-6">
            <h2 class="text-base font-semibold tracking-tight text-gray-800 dark:text-white/90">My workspaces</h2>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($myWorkspaces as $ws) :
                    $memberCount  = (int) ($ws->member_count ?? 0);
                    $projectCount = (int) ($ws->project_count ?? 0);
                    ?>
                    <div class="flex flex-col rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-gray-800 dark:text-white/90"><?= esc($ws->name ?? '') ?></h3>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $roleBadge((string) ($ws->role ?? '')) ?>">
                                <?= esc((string) ($ws->role ?? '—')) ?>
                            </span>
                        </div>
                        <div class="mt-4 flex items-center gap-4 text-xs text-[#787774] dark:text-gray-400">
                            <span><?= esc((string) $memberCount) ?> member<?= $memberCount === 1 ? '' : 's' ?></span>
                            <span><?= esc((string) $projectCount) ?> project<?= $projectCount === 1 ? '' : 's' ?></span>
                        </div>
                        <a href="/workspaces/<?= (int) $ws->id ?>" class="btn-secondary mt-4 w-full text-center">Open</a>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    <?php endif ?>
</div>
<?php $this->endSection() ?>
