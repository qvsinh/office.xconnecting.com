<?php
/**
 * Read-only system info: version/environment, database, record counts,
 * writable-dir status. No forms, no secrets — the controller already
 * whitelists every value (see Admin\System::buildInfoData()).
 *
 * @var string              $ci_version
 * @var string              $php_version
 * @var string              $environment
 * @var string              $db_driver
 * @var string              $db_database
 * @var array<string, int>  $counts    users/api_access_keys/api_history/notifications
 * @var array<string, bool> $writable  writable/logs/cache/session
 */
$this->extend('layouts/app');

$countLabels = [
    'users'           => 'Users',
    'api_access_keys' => 'API access keys',
    'api_history'     => 'API history rows',
    'notifications'   => 'Notifications',
];

$writableLabels = [
    'writable' => 'writable/',
    'logs'     => 'writable/logs/',
    'cache'    => 'writable/cache/',
    'session'  => 'writable/session/',
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">System</h1>
        <p class="mt-1 text-sm text-[#787774]">Environment, database, and record counts. Read-only.</p>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6">
            <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774]">Version &amp; environment</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-[#787774]">CodeIgniter</dt>
                    <dd class="font-mono font-medium"><?= esc($ci_version) ?></dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-[#787774]">PHP</dt>
                    <dd class="font-mono font-medium"><?= esc($php_version) ?></dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-[#787774]">Environment</dt>
                    <dd>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide
                            <?= $environment === 'production' ? 'bg-[#FDEBEC] text-[#9F2F2D]' : 'bg-[#EDF3EC] text-[#346538]' ?>">
                            <?= esc($environment) ?>
                        </span>
                    </dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6">
            <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774]">Database</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-[#787774]">Driver</dt>
                    <dd class="font-mono font-medium"><?= esc($db_driver) ?></dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-[#787774]">Database</dt>
                    <dd class="font-mono font-medium"><?= esc($db_database) ?></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 sm:col-span-2">
            <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774]">Record counts</h2>
            <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <?php foreach ($countLabels as $key => $label) : ?>
                    <div class="rounded-md border border-[#EAEAEA] bg-[#F9F9F8] p-4">
                        <p class="text-2xl font-semibold tracking-tight"><?= esc((string) ($counts[$key] ?? 0)) ?></p>
                        <p class="mt-1 text-xs text-[#787774]"><?= esc($label) ?></p>
                    </div>
                <?php endforeach ?>
            </div>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 sm:col-span-2">
            <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774]">Writable directories</h2>
            <ul class="mt-4 divide-y divide-[#EAEAEA]">
                <?php foreach ($writableLabels as $key => $label) : ?>
                    <?php $ok = (bool) ($writable[$key] ?? false); ?>
                    <li class="flex items-center justify-between py-2.5 text-sm">
                        <span class="font-mono"><?= esc($label) ?></span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide
                            <?= $ok ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FDEBEC] text-[#9F2F2D]' ?>">
                            <?= $ok ? 'Writable' : 'Not writable' ?>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
</div>
<?php $this->endSection() ?>
