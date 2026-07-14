<?php
/**
 * Shared developer-console UI (key selector, command + params builder, Run
 * button, JSON response viewer) reused by all 4 console pages
 * (admin|client × cnr|jprs) — only the key list, run endpoint, and provider
 * label differ, so those come in as variables rather than 4 near-identical
 * views. Included (not extended) by each entry view, which itself extends
 * `layouts/app`.
 *
 * The access key string itself is never rendered here — only `id`,
 * `service_id`/label, `env`, `is_enable`, and (admin zone) the owning user's
 * name/email. Only `key_id` is ever sent to the server.
 *
 * @var list<object> $keys     ApiAccessKeyModel::listForConsole() rows
 * @var string       $provider 'cnr'|'jprs'
 * @var string       $zone     'admin'|'client'
 */
helper('service_config');

$isAdmin       = $zone === 'admin';
$runUrl        = ($isAdmin ? '/admin' : '') . '/console/' . $provider . '/run';
$serviceLabels = array_column(list_api_services(), 'label', 'id');

$keysForJs = array_map(static fn (object $k): array => [
    'id'            => (int) $k->id,
    'service_id'    => (int) $k->service_id,
    'service_label' => $serviceLabels[(int) $k->service_id] ?? ('Service #' . $k->service_id),
    'env'           => (int) $k->user_status === 1 ? 'LIVE' : 'TESTING',
    'is_enable'     => (int) $k->is_enable,
    'user_id'       => (int) $k->user_id,
    'user_name'     => $k->user_name ?? null,
    'user_email'    => $k->user_email ?? null,
], $keys);
?>
<div x-data="apiConsole(<?= esc(json_encode($keysForJs), 'attr') ?>, <?= esc(json_encode($runUrl), 'attr') ?>, <?= $isAdmin ? 'true' : 'false' ?>)">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100"><?= esc(strtoupper($provider)) ?> console</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Compose and run a <?= esc(strtoupper($provider)) ?> registrar command against one of
                <?= $isAdmin ? 'any user\'s' : 'your own enabled' ?> API access keys. The key string
                itself is never sent to your browser — only its id.
            </p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
        <!-- Request builder -->
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Request</h2>

            <div class="mt-4">
                <label for="key-search" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Filter keys</label>
                <input type="text" id="key-search" x-model="keySearch" placeholder="Service, env, user…"
                       class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
            </div>

            <div class="mt-4">
                <label for="key-select" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">API access key</label>
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

            <div class="mt-4">
                <label for="command" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Command</label>
                <input type="text" id="command" x-model="form.command" placeholder="e.g. StatusDomain, History"
                       class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 font-mono text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <p class="mt-1 text-xs text-[#787774] dark:text-gray-400"><code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-700 dark:text-gray-200">History</code> returns this key's own request log (params: keyword, domain, sort_by, sort, limit, offset).</p>
            </div>

            <div class="mt-4">
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Parameters</span>
                    <button type="button" class="text-xs font-medium text-[#111111] hover:underline dark:text-gray-100" @click="addRow()">+ Add parameter</button>
                </div>
                <div class="space-y-2">
                    <template x-for="(row, idx) in paramRows" :key="idx">
                        <div class="flex items-center gap-2">
                            <input type="text" x-model="row.key" placeholder="key"
                                   class="block w-2/5 rounded-md border border-[#EAEAEA] px-2.5 py-1.5 font-mono text-xs focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                            <input type="text" x-model="row.value" placeholder="value"
                                   class="block flex-1 rounded-md border border-[#EAEAEA] px-2.5 py-1.5 font-mono text-xs focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                            <button type="button" class="text-[#787774] hover:text-[#9F2F2D] dark:text-gray-400 dark:hover:text-red-400" @click="removeRow(idx)" aria-label="Remove parameter">&times;</button>
                        </div>
                    </template>
                </div>
            </div>

            <button type="button" @click="run()" :disabled="running"
                    class="mt-5 w-full rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                <span x-show="!running">Run</span>
                <span x-show="running" x-cloak>Running…</span>
            </button>
        </div>

        <!-- Response viewer -->
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800 lg:col-span-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Response</h2>
                <template x-if="response">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide"
                          :class="responseOk ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300'"
                          x-text="responseOk ? 'Success' : 'Error'"></span>
                </template>
            </div>
            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400" x-show="response" x-text="response ? response.api_service_id : ''"></p>
            <pre class="mt-3 max-h-[32rem] overflow-auto whitespace-pre-wrap break-all rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed text-[#111111] dark:bg-gray-900 dark:text-gray-100"
                 x-text="responseText || 'Run a command to see the response here.'"></pre>
        </div>
    </div>

    <script>
        function apiConsole(initialKeys, runUrl, isAdmin) {
            return {
                keys: initialKeys,
                isAdmin,
                keySearch: '',
                form: { key_id: '', command: '' },
                paramRows: [{ key: '', value: '' }],
                running: false,
                response: null,
                responseText: '',
                responseOk: null,

                filteredKeys() {
                    const q = this.keySearch.trim().toLowerCase();
                    if (q === '') return this.keys;
                    return this.keys.filter(k => this.keyLabel(k).toLowerCase().includes(q));
                },

                keyLabel(k) {
                    let label = k.service_label + ' — ' + k.env;
                    if (this.isAdmin) label += ' — ' + (k.user_name || k.user_email || ('User #' + k.user_id));
                    if (!k.is_enable) label += ' [disabled]';
                    return label;
                },

                selectedKey() {
                    return this.keys.find(k => String(k.id) === String(this.form.key_id)) || null;
                },

                addRow() {
                    this.paramRows.push({ key: '', value: '' });
                },
                removeRow(idx) {
                    this.paramRows.splice(idx, 1);
                    if (this.paramRows.length === 0) this.addRow();
                },

                async run() {
                    if (!this.form.key_id) {
                        this.$dispatch('toast', { type: 'error', text: 'Select an API access key.' });
                        return;
                    }
                    const command = this.form.command.trim();
                    if (command === '') {
                        this.$dispatch('toast', { type: 'error', text: 'Enter a command.' });
                        return;
                    }

                    const fields = { key_id: this.form.key_id, command };
                    this.paramRows.forEach(row => {
                        const key = row.key.trim();
                        if (key !== '') fields['params[' + key + ']'] = row.value;
                    });

                    this.running = true;
                    this.response = null;
                    this.responseText = '';
                    this.responseOk = null;

                    const json = await postForm(runUrl, fields);
                    this.running = false;
                    if (!json) return;

                    this.response = json;
                    this.responseText = JSON.stringify(json, null, 2);
                    this.responseOk = json.status === 'success';
                    this.$dispatch('toast', {
                        type: this.responseOk ? 'success' : 'error',
                        text: this.responseOk ? 'Command executed.' : (json.message || 'Command failed.'),
                    });
                },
            };
        }
    </script>
</div>
