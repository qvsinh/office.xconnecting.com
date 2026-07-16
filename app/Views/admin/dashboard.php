<?php
/**
 * Admin dashboard — overview widgets.
 *
 * @var object $user        session('userWorking')
 * @var int    $usersTotal  total users
 * @var int    $usersActive active users
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Welcome back, <?= esc($user->name ?? '') ?></h1>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Admin console overview.</p>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <a href="<?= esc('/admin/users', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Total users</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $usersTotal) ?></p>
        </a>
        <a href="<?= esc('/admin/users', 'attr') ?>" class="rounded-lg border border-[#EAEAEA] bg-white p-6 transition-colors hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Active users</p>
            <p class="mt-2 text-2xl font-semibold dark:text-gray-100"><?= esc((string) $usersActive) ?></p>
        </a>
    </div>
</div>
<?php $this->endSection() ?>
