<?php
/**
 * Client dashboard — overview widgets.
 *
 * @var object $user           session('userWorking')
 * @var int    $myKeys         this user's API access keys count
 * @var int    $maxKeys        this user's key limit
 * @var int    $myApiCalls24h  this user's API calls in the last 24 hours
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Welcome back, <?= esc($user->name ?? '') ?></h1>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Client console overview.</p>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="<?= esc('/my-api-access-keys', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Your API keys</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $myKeys) ?> / <?= esc((string) $maxKeys) ?></p>
            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Keys used / limit</p>
        </a>
        <a href="<?= esc('/api-history', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">API calls (24h)</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $myApiCalls24h) ?></p>
            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Last 24 hours</p>
        </a>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Environment</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc($user->env ?? '') ?></p>
            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Current account zone</p>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
