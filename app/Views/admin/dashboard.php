<?php
/**
 * Admin dashboard — overview widgets.
 *
 * @var object $user            session('userWorking')
 * @var int    $tokensTotal     total API access keys across all users
 * @var int    $tokensLive      API access keys on live-env users
 * @var int    $tokensTesting   API access keys on testing-env users
 * @var int    $usersTotal      total users
 * @var int    $usersLive       users on live env
 * @var int    $usersTesting    users on testing env
 * @var int    $apiSuccess      successful API calls (all time)
 * @var int    $apiFailed       failed API calls (all time)
 * @var int    $apiCalls24h     API calls logged in the last 24 hours
 * @var int    $servicesTotal   configured services
 * @var int    $servicesLive    services in use on live-env users
 * @var int    $servicesTesting services in use on testing-env users
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Welcome back, <?= esc($user->name ?? '') ?></h1>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Admin console overview.</p>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="<?= esc('/admin/api-access-keys', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">API keys</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $tokensTotal) ?></p>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span class="rounded-full bg-[#EDF3EC] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#346538] dark:bg-green-900/40 dark:text-green-300">Live: <?= esc((string) $tokensLive) ?></span>
                <span class="rounded-full bg-[#FBF3DB] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300">Testing: <?= esc((string) $tokensTesting) ?></span>
            </div>
        </a>
        <a href="<?= esc('/admin/users', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Users</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $usersTotal) ?></p>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span class="rounded-full bg-[#EDF3EC] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#346538] dark:bg-green-900/40 dark:text-green-300">Live: <?= esc((string) $usersLive) ?></span>
                <span class="rounded-full bg-[#FBF3DB] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300">Testing: <?= esc((string) $usersTesting) ?></span>
            </div>
        </a>
        <a href="<?= esc('/admin/api-history', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">API calls</p>
            <div class="mt-2 flex items-baseline gap-3">
                <span class="text-2xl font-semibold text-[#346538] dark:text-green-300"><?= esc((string) $apiSuccess) ?></span>
                <span class="text-sm text-[#787774] dark:text-gray-400">/</span>
                <span class="text-2xl font-semibold text-[#9F2F2D] dark:text-red-400"><?= esc((string) $apiFailed) ?></span>
            </div>
            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Success / Failed</p>
            <p class="mt-2 text-xs text-[#787774] dark:text-gray-400">Last 24h: <?= esc((string) $apiCalls24h) ?></p>
        </a>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Services</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $servicesTotal) ?></p>
            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Configured</p>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span class="rounded-full bg-[#EDF3EC] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#346538] dark:bg-green-900/40 dark:text-green-300">Live: <?= esc((string) $servicesLive) ?></span>
                <span class="rounded-full bg-[#FBF3DB] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300">Testing: <?= esc((string) $servicesTesting) ?></span>
            </div>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
