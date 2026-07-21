<?php
/**
 * Getting-started walkthrough: the shortest path from a fresh account to a
 * working board. Static content, links to the real feature routes.
 */
$this->extend('layouts/app');

$docsNav = [
    ['label' => 'Overview', 'href' => '/docs'],
    ['label' => 'Getting started', 'href' => '/docs/getting-started'],
    ['label' => 'REST API', 'href' => '/docs/api'],
];

$steps = [
    [
        'title' => 'Create a workspace',
        'body'  => 'A workspace groups the people and projects for a team or client. Go to '
            . '<a href="/workspaces" class="font-medium text-[#111111] hover:underline dark:text-gray-100">Workspaces</a>'
            . ' and click "+ Create workspace".',
    ],
    [
        'title' => 'Invite members or add a group',
        'body'  => 'Open the workspace and add teammates one by one, or attach an existing admin-managed group so everyone in it gets access at once.',
    ],
    [
        'title' => 'Create a project',
        'body'  => 'Inside the workspace, create a project. Each project gets its own Kanban board and file storage.',
    ],
    [
        'title' => 'Add tasks on the board',
        'body'  => 'Open the project board, add columns if you need more than the defaults, and create tasks with a priority, assignee, and due date.',
    ],
    [
        'title' => 'Upload files',
        'body'  => 'Use the Files tab on a project to share documents with everyone who has access to it.',
    ],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl lg:flex lg:gap-8">

    <nav class="mb-6 flex gap-2 overflow-x-auto pb-1 lg:hidden" aria-label="Docs">
        <?php foreach ($docsNav as $item) : ?>
            <a href="<?= esc($item['href'], 'attr') ?>"
               class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium <?= url_is($item['href'])
                    ? 'bg-[#111111] text-white dark:bg-gray-100 dark:text-gray-900'
                    : 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-800 dark:text-gray-300' ?>">
                <?= esc($item['label']) ?>
            </a>
        <?php endforeach ?>
    </nav>

    <nav class="hidden lg:block lg:w-56 lg:shrink-0" aria-label="Docs">
        <div class="sticky top-6 space-y-1">
            <?php foreach ($docsNav as $item) : ?>
                <a href="<?= esc($item['href'], 'attr') ?>"
                   class="block rounded-md px-3 py-2 text-sm font-medium <?= url_is($item['href'])
                        ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                        : 'text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                    <?= esc($item['label']) ?>
                </a>
            <?php endforeach ?>
        </div>
    </nav>

    <div class="min-w-0 max-w-3xl flex-1">
        <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Getting started</h1>
        <p class="mt-1 text-sm leading-6 text-[#787774] dark:text-gray-400">
            The shortest path from a new account to a working board.
        </p>

        <ol class="mt-6 space-y-6">
            <?php foreach ($steps as $i => $step) : ?>
                <li class="flex gap-4">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#111111] text-sm font-semibold text-white dark:bg-gray-100 dark:text-gray-900">
                        <?= (int) ($i + 1) ?>
                    </span>
                    <div class="min-w-0 pt-0.5">
                        <h2 class="text-base font-semibold dark:text-gray-100"><?= esc($step['title']) ?></h2>
                        <!-- $step['body'] is developer-authored markup (a fixed set of trusted
                             strings above), not user input — safe to render raw for the inline link. -->
                        <p class="mt-1 text-sm leading-6 text-[#787774] dark:text-gray-400"><?= $step['body'] ?></p>
                    </div>
                </li>
            <?php endforeach ?>
        </ol>

        <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-[#F7F6F3] p-6 dark:border-gray-700 dark:bg-gray-800/60">
            <h2 class="text-base font-semibold dark:text-gray-100">Next: connect an AI agent</h2>
            <p class="mt-1 text-sm leading-6 text-[#787774] dark:text-gray-400">
                Once you have a project with tasks, you can let an AI agent read and manage them
                through the REST API.
            </p>
            <a href="/docs/api" class="mt-3 inline-block text-sm font-medium text-[#111111] hover:underline dark:text-gray-100">Read the REST API guide →</a>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
