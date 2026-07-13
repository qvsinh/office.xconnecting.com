<?php
/**
 * Client dashboard shell (placeholder cards only). Real widgets are a later
 * card — see kanban/processing/20260713-web-auth-and-shell.md §2 backlog.
 *
 * @var object $user session('userWorking')
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <h1 class="text-xl font-semibold tracking-tight">Welcome back, <?= esc($user->name ?? '') ?></h1>
    <p class="mt-1 text-sm text-[#787774]">Client console overview.</p>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774]">Your API keys</p>
            <p class="mt-2 text-2xl font-semibold">—</p>
            <p class="mt-1 text-xs text-[#787774]">Coming soon</p>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774]">API calls (24h)</p>
            <p class="mt-2 text-2xl font-semibold">—</p>
            <p class="mt-1 text-xs text-[#787774]">Coming soon</p>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6">
            <p class="text-xs font-medium uppercase tracking-wide text-[#787774]">Environment</p>
            <p class="mt-2 text-2xl font-semibold"><?= esc($user->env ?? '') ?></p>
            <p class="mt-1 text-xs text-[#787774]">Current account zone</p>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
