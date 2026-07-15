<?php
/**
 * API connection self-test — lets a user prove one of their API access keys
 * authenticates against the REAL public proxy endpoint (base URL + Bearer +
 * env), end-to-end. The probe always runs the `History` command (handled
 * locally by the proxy), so it never reaches the upstream registrar — it
 * only proves key + endpoint + auth work. The access-key string itself is
 * never present in `$keys` (see `ApiAccessKeyModel::listForConnectionTest()`)
 * and must never be rendered here.
 *
 * @var list<object> $keys ApiAccessKeyModel::listForConnectionTest() rows — id, service_id,
 *                   user_status, is_enable, description, last_access, and (admin zone
 *                   only) user_name/user_email. No access_key.
 * @var string       $zone 'admin'|'client'
 */
helper('service_config');

$this->extend('layouts/app');
$pageTitle = 'Connection test';

$isAdmin = $zone === 'admin';
$runUrl  = ($isAdmin ? '/admin' : '') . '/api-test/run';

$serviceLabels = array_column(list_api_services(), 'label', 'id');

$keysForJs = array_map(static fn (object $k): array => [
    'id'            => (int) $k->id,
    'service_label' => $serviceLabels[(int) $k->service_id] ?? ('Service #' . $k->service_id),
    'provider'      => provider_of_service((int) $k->service_id) ?? '—',
    'env'           => (int) $k->user_status === 1 ? 'LIVE' : 'TESTING',
    'is_enable'     => (int) $k->is_enable,
    'user_name'     => $k->user_name ?? null,
    'user_email'    => $k->user_email ?? null,
], $keys);
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="apiConnectionTest(<?= esc(json_encode($keysForJs), 'attr') ?>, <?= esc(json_encode($runUrl), 'attr') ?>, <?= $isAdmin ? 'true' : 'false' ?>)">

    <div>
        <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Connection test</h1>
        <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
            Verify that one of <?= $isAdmin ? 'any user\'s' : 'your own' ?> API access keys authenticates
            against the real public API endpoint — base URL, Bearer token, and environment segment,
            end-to-end. The probe uses the <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-700 dark:text-gray-200">History</code>
            command, which is handled locally and never reaches the upstream registrar — it only
            proves your key + endpoint + auth work. The key string itself is never shown.
        </p>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Key selector -->
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">API access key</h2>

            <div class="mt-4">
                <label for="key-search" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Filter keys</label>
                <input type="text" id="key-search" x-model="keySearch" placeholder="Service, env, user…"
                       class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
            </div>

            <div class="mt-4">
                <label for="key-select" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Select a key</label>
                <select id="key-select" x-model="form.key_id"
                        class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                    <option value="">Select a key…</option>
                    <template x-for="k in filteredKeys()" :key="k.id">
                        <option :value="k.id" x-text="keyLabel(k)"></option>
                    </template>
                </select>
                <p class="mt-1 text-xs text-[#787774] dark:text-gray-400" x-show="filteredKeys().length === 0" x-cloak>
                    No keys match your filter<?= $isAdmin ? '.' : ' — create one from "API access keys" first.' ?>
                </p>
                <template x-if="selectedKey()">
                    <p class="mt-1.5 text-xs text-[#787774] dark:text-gray-400">
                        <span x-text="selectedKey().service_label"></span> ·
                        <span class="font-medium" x-text="selectedKey().env"></span>
                        <template x-if="!selectedKey().is_enable">
                            <span class="ml-1 rounded-full bg-[#FDEBEC] px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300">Disabled</span>
                        </template>
                    </p>
                </template>
            </div>

            <button type="button" @click="run()" :disabled="running || !form.key_id"
                    class="mt-5 w-full rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                <span x-show="!running">Test connection</span>
                <span x-show="running" x-cloak>Testing…</span>
            </button>
        </div>

        <!-- Result -->
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Result</h2>
                <template x-if="result">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide"
                          :class="result.ok ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300'"
                          x-text="result.ok ? 'Reachable & authenticated' : 'Failed'"></span>
                </template>
            </div>

            <template x-if="!result">
                <p class="mt-3 text-sm text-[#787774] dark:text-gray-400">Select a key and run the test.</p>
            </template>

            <template x-if="result">
                <div class="mt-3 space-y-3">
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Base URL</dt>
                            <dd class="truncate font-mono text-[#111111] dark:text-gray-100" x-text="result.base_url"></dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Endpoint</dt>
                            <dd class="truncate font-mono text-[#111111] dark:text-gray-100" x-text="result.endpoint"></dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">HTTP status</dt>
                            <dd class="font-mono text-[#111111] dark:text-gray-100" x-text="result.http_status"></dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Latency</dt>
                            <dd class="font-mono text-[#111111] dark:text-gray-100"><span x-text="result.latency_ms"></span> ms</dd>
                        </div>
                    </dl>

                    <template x-if="result.error">
                        <p class="rounded-md bg-[#FDEBEC] px-3 py-2 text-xs text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300" x-text="result.error"></p>
                    </template>

                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Raw response</p>
                        <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed text-[#111111] dark:bg-gray-900 dark:text-gray-100" x-text="resultText"></pre>
                    </div>

                    <p class="text-xs italic text-[#787774] dark:text-gray-400">
                        This uses the History command — it verifies your key + endpoint, not upstream registrar connectivity.
                    </p>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
    function apiConnectionTest(initialKeys, runUrl, isAdmin) {
        return {
            keys: initialKeys,
            isAdmin,
            keySearch: '',
            form: { key_id: '' },
            running: false,
            result: null,
            resultText: '',

            filteredKeys() {
                const q = this.keySearch.trim().toLowerCase();
                if (q === '') return this.keys;
                return this.keys.filter(k => this.keyLabel(k).toLowerCase().includes(q));
            },

            keyLabel(k) {
                let label = k.service_label + ' — ' + k.env;
                if (this.isAdmin) label += ' — ' + (k.user_name || k.user_email || 'unknown user');
                if (!k.is_enable) label += ' [disabled]';
                return label;
            },

            selectedKey() {
                return this.keys.find(k => String(k.id) === String(this.form.key_id)) || null;
            },

            async run() {
                if (!this.form.key_id) {
                    this.$dispatch('toast', { type: 'error', text: 'Select an API access key.' });
                    return;
                }

                this.running = true;
                this.result = null;
                this.resultText = '';

                const json = await postForm(runUrl, { key_id: this.form.key_id });
                this.running = false;
                if (!json) return;

                if (json.status !== 'success') {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Connection test failed.' });
                    return;
                }

                this.result = json.result;
                this.resultText = JSON.stringify(json.result.response, null, 2);
                this.$dispatch('toast', {
                    type: this.result.ok ? 'success' : 'error',
                    text: this.result.ok ? 'Reachable and authenticated.' : (this.result.error || 'Key did not authenticate — see result.'),
                });
            },
        };
    }
</script>
<?php $this->endSection() ?>
