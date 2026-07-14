<?php
/**
 * Read-only system info: version/environment, database, record counts,
 * writable-dir status. No forms, no secrets — the controller already
 * whitelists every value (see Admin\System::buildInfoData()).
 *
 * @var string                                                                                                                                                         $ci_version
 * @var string                                                                                                                                                         $php_version
 * @var string                                                                                                                                                         $environment
 * @var string                                                                                                                                                         $db_driver
 * @var string                                                                                                                                                         $db_database
 * @var array<string, int>                                                                                                                                             $counts    users/api_access_keys/api_history/notifications
 * @var array<string, bool>                                                                                                                                            $writable  writable/logs/cache/session
 * @var array{opcache: array<string, mixed>, php_config: array<string, string>, server: array<string, string>, extensions: list<array{name: string, version: string}>} $runtime
 */
$this->extend('layouts/app');

// ponytail: byte formatting is server-side only (the values are rendered
// once, not fetched via Alpine), so a plain closure beats a JS helper.
$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $value = $bytes / 1024;

    foreach (['KB', 'MB', 'GB'] as $unit) {
        if ($value < 1024 || $unit === 'GB') {
            return number_format($value, 1) . ' ' . $unit;
        }
        $value /= 1024;
    }

    return number_format($value, 1) . ' GB';
};

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
<div class="mx-auto max-w-5xl" x-data="systemInfo()">
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

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800 sm:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">OPcache</h2>
                <?php if ($runtime['opcache']['available'] ?? false) : ?>
                    <button type="button" @click="confirmResetOpcache()" :disabled="resettingOpcache"
                            class="rounded-md border border-[#9F2F2D] px-3 py-1.5 text-xs font-medium text-[#9F2F2D] hover:bg-[#FDEBEC] disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/40">
                        Reset OPcache
                    </button>
                <?php endif ?>
            </div>

            <?php if (! ($runtime['opcache']['available'] ?? false)) : ?>
                <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">OPcache is not enabled on this server.</p>
            <?php else : $oc = $runtime['opcache']; ?>
                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Status</dt>
                        <dd class="mt-1">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide
                                <?= $oc['enabled'] ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-400' : 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/30 dark:text-red-400' ?>">
                                <?= $oc['enabled'] ? 'Enabled' : 'Disabled' ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Hit rate</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc(number_format((float) $oc['hit_rate'], 2)) ?>%</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Hits / misses</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc((string) $oc['hits']) ?> / <?= esc((string) $oc['misses']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Restarts (OOM / manual)</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc((string) $oc['oom_restarts']) ?> / <?= esc((string) $oc['manual_restarts']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Cached scripts</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc((string) $oc['num_cached_scripts']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Cached keys</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc((string) $oc['num_cached_keys']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Memory used</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc($formatBytes($oc['memory_used'])) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Memory free</dt>
                        <dd class="mt-1 font-mono font-medium"><?= esc($formatBytes($oc['memory_free'])) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#787774] dark:text-gray-400">Memory wasted</dt>
                        <dd class="mt-1 font-mono font-medium">
                            <?= esc($formatBytes($oc['memory_wasted'])) ?> (<?= esc(number_format((float) $oc['memory_wasted_pct'], 1)) ?>%)
                        </dd>
                    </div>
                </dl>
            <?php endif ?>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">PHP configuration</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <?php foreach ($runtime['php_config'] as $directive => $value) : ?>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="font-mono text-xs text-[#787774] dark:text-gray-400"><?= esc($directive) ?></dt>
                        <dd class="truncate font-mono font-medium"><?= esc((string) $value) ?></dd>
                    </div>
                <?php endforeach ?>
            </dl>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Server information</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <?php foreach ($runtime['server'] as $key => $value) : ?>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[#787774] dark:text-gray-400"><?= esc(str_replace('_', ' ', $key)) ?></dt>
                        <dd class="truncate font-mono font-medium"><?= esc((string) $value) ?></dd>
                    </div>
                <?php endforeach ?>
            </dl>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800 sm:col-span-2">
            <button type="button" @click="extensionsOpen = !extensionsOpen" class="flex w-full items-center justify-between text-left">
                <h2 class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    PHP extensions (<?= esc((string) count($runtime['extensions'])) ?>)
                </h2>
                <span class="text-sm font-medium text-[#111111] dark:text-gray-100" x-text="extensionsOpen ? 'Hide' : 'Show'"></span>
            </button>
            <div x-show="extensionsOpen" x-cloak x-transition class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($runtime['extensions'] as $ext) : ?>
                    <span class="rounded-md border border-[#EAEAEA] bg-[#F9F9F8] px-2 py-1 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        <?= esc($ext['name']) ?><?= $ext['version'] !== '' ? ' ' . esc($ext['version']) : '' ?>
                    </span>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>

<script>
    function systemInfo() {
        return {
            resettingOpcache: false,
            extensionsOpen: false,

            confirmResetOpcache() {
                this.$dispatch('confirm', {
                    title: 'Reset OPcache',
                    body: 'This clears all compiled/cached PHP opcodes. The next request on each worker will recompile from source.',
                    variant: 'danger',
                    confirmLabel: 'Reset',
                    onConfirm: () => this.doResetOpcache(),
                });
            },
            async doResetOpcache() {
                this.resettingOpcache = true;
                const json = await postForm('/admin/system/opcache/reset', {});
                this.resettingOpcache = false;
                if (json) {
                    this.$dispatch('toast', { type: json.status === 'success' ? 'success' : 'error', text: json.message || 'Unexpected response.' });
                }
            },
        };
    }
</script>
<?php $this->endSection() ?>
