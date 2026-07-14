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
            <h1 class="text-xl font-semibold tracking-tight"><?= esc($user->name) ?></h1>
            <p class="mt-1 text-sm text-[#787774]"><?= esc($user->email) ?></p>
        </div>
        <a href="/admin/users" class="text-sm text-[#787774] hover:text-[#111111]">&larr; Back to users</a>
    </div>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Company</dt>
            <dd class="mt-1 text-sm"><?= esc($user->company ?? '') !== '' ? esc($user->company) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Zone</dt>
            <dd class="mt-1">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $user->zone === 'global' ? 'bg-[#E1F3FE] text-[#1F6C9F]' : 'bg-gray-100 text-gray-700' ?>">
                    <?= $user->zone === 'global' ? 'Admin' : 'Client' ?>
                </span>
            </dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Environment</dt>
            <dd class="mt-1">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $user->env === 'LIVE' ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FBF3DB] text-[#956400]' ?>">
                    <?= esc($user->env) ?>
                </span>
            </dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Status</dt>
            <dd class="mt-1">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= (int) $user->status === 1 ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FDEBEC] text-[#9F2F2D]' ?>">
                    <?= (int) $user->status === 1 ? 'Active' : 'Inactive' ?>
                </span>
            </dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Language</dt>
            <dd class="mt-1 text-sm"><?= esc($user->language ?? '') !== '' ? esc($user->language) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Timezone</dt>
            <dd class="mt-1 text-sm"><?= esc($user->timezone ?? '') !== '' ? esc($user->timezone) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 sm:col-span-2">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Last login</dt>
            <dd class="mt-1 text-sm"><?= esc($user->login_at ?? '') !== '' ? esc($user->login_at) : '—' ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 sm:col-span-2">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Admin note</dt>
            <dd class="mt-1 whitespace-pre-line text-sm"><?= esc($user->note ?? '') !== '' ? esc($user->note) : '—' ?></dd>
            <a href="/admin/users/note/<?= (int) $user->id ?>" class="mt-2 inline-block text-sm font-medium text-[#111111] hover:underline">Edit note</a>
        </div>
    </dl>

    <div class="mt-6 flex flex-wrap gap-3" x-data="{ actAsName: <?= esc(json_encode($user->name), 'attr') ?> }">
        <a href="/admin/api-access-keys?keyword=<?= esc(urlencode($user->email), 'attr') ?>"
           class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50">
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
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333]">
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
