<?php
/**
 * Docs overview: entry point into the in-app documentation. Links to the
 * getting-started walkthrough and the REST API connection guide. Static
 * content only — no dynamic data from the controller.
 */
$this->extend('layouts/app');

$docsNav = [
    ['label' => 'Overview', 'href' => '/docs'],
    ['label' => 'Getting started', 'href' => '/docs/getting-started'],
    ['label' => 'REST API', 'href' => '/docs/api'],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl lg:flex lg:gap-8">

    <!-- Mobile: horizontal-scroll pill nav. Desktop: sticky left nav below. -->
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
        <h1 class="text-2xl font-semibold tracking-tight dark:text-gray-100">Documentation</h1>
        <p class="mt-2 text-sm leading-6 text-[#787774] dark:text-gray-400">
            Everything you need to run projects in this hub, and to connect an AI agent or script
            to your data through the REST API.
        </p>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <a href="/docs/getting-started"
               class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-[#111111] dark:text-gray-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <h2 class="mt-3 text-base font-semibold dark:text-gray-100">Getting started</h2>
                <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                    Create a workspace, invite your team, and get a project on the board.
                </p>
            </a>

            <a href="/docs/api"
               class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-[#111111] dark:text-gray-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15.75l-4.5-4.5 4.5-4.5m7.5 9l4.5-4.5-4.5-4.5" />
                </svg>
                <h2 class="mt-3 text-base font-semibold dark:text-gray-100">REST API</h2>
                <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                    Connect an AI agent or script with a personal API key: list projects, read and
                    write tasks.
                </p>
            </a>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
