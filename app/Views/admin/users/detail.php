<?php
/**
 * Admin user detail: read-only profile + a link to the admin note editor +
 * act-as. API-key management for this user lives at /admin/api-access-keys
 * (link only — not rebuilt here, see kanban plan §2).
 *
 * @var object $user full users row (id/name/email/company/zone/env/status/
 *             language/timezone/login_at/note/...) — only the fields
 *             named below are ever rendered; never dump the object
 *             (it also carries password/google2fa_secret).
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-3xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100"><?= esc($user->name) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= esc($user->email) ?></p>
        </div>
        <a href="/admin/users" class="text-sm text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100">&larr; Back to users</a>
    </div>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Company</dt>
            <dd class="mt-1 text-sm dark:text-gray-100"><?= esc($user->company ?? '') !== '' ? esc($user->company) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Zone</dt>
            <dd class="mt-1">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $user->zone === 'global' ? 'bg-[#E1F3FE] text-[#1F6C9F] dark:bg-blue-900/40 dark:text-blue-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' ?>">
                    <?= $user->zone === 'global' ? 'Admin' : 'Client' ?>
                </span>
            </dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Environment</dt>
            <dd class="mt-1">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $user->env === 'LIVE' ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300' ?>">
                    <?= esc($user->env) ?>
                </span>
            </dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Status</dt>
            <dd class="mt-1">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= (int) $user->status === 1 ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300' ?>">
                    <?= (int) $user->status === 1 ? 'Active' : 'Inactive' ?>
                </span>
            </dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Language</dt>
            <dd class="mt-1 text-sm dark:text-gray-100"><?= esc($user->language ?? '') !== '' ? esc($user->language) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Timezone</dt>
            <dd class="mt-1 text-sm dark:text-gray-100"><?= esc($user->timezone ?? '') !== '' ? esc($user->timezone) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 sm:col-span-2 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Last login</dt>
            <dd class="mt-1 text-sm dark:text-gray-100"><?= esc($user->login_at ?? '') !== '' ? esc($user->login_at) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 sm:col-span-2 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Admin note</dt>
            <dd class="mt-1 whitespace-pre-line text-sm dark:text-gray-100"><?= esc($user->note ?? '') !== '' ? esc($user->note) : '—' ?></dd>
            <a href="/admin/users/note/<?= (int) $user->id ?>" class="mt-2 inline-block text-sm font-medium text-[#111111] hover:underline dark:text-gray-100">Edit note</a>
        </div>
    </dl>

    <div class="mt-6 flex flex-wrap gap-3" x-data="{ actAsName: <?= esc(json_encode($user->name), 'attr') ?> }">
        <a href="/admin/api-access-keys?keyword=<?= esc(urlencode($user->email), 'attr') ?>"
           class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">
            Manage API access keys
        </a>
        <button type="button"
                @click="$dispatch('confirm', {
                    title: 'Act as this user',
                    body: 'You will see the app as ' + actAsName + '. Use \'Return to admin\' in the banner to come back.',
                    variant: 'default',
                    confirmLabel: 'Act as',
                    onConfirm: () => document.getElementById('act-as-form').submit(),
                })"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
            Act as this user
        </button>
    </div>

    <!-- Plain POST navigation (not fetch) — Admin\Users::actAs() redirects
         to /dashboard, which fetch() can't turn into a real page navigation. -->
    <form id="act-as-form" method="post" action="/admin/users/act-as/<?= (int) $user->id ?>" class="hidden">
        <input type="hidden" name="<?= esc(csrf_token(), 'attr') ?>" value="<?= esc(csrf_hash(), 'attr') ?>">
    </form>
</div>
<?php $this->endSection() ?>
