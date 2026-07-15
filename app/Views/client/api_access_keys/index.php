<?php
/**
 * Self-service API access keys: list own keys, create up to $maxKeys, toggle
 * enable/log, delete own. Every mutation is scoped server-side to the
 * session user (Client\ApiAccessKeys never reads a user_id from input).
 *
 * Note: Client\ApiAccessKeys::create() does not currently accept a
 * `description` field (only `service_id`) — the create form intentionally
 * has no description input to avoid promising a value the backend drops.
 * See "Backend dependency" in the frontend-coder report if that's wanted.
 *
 * @var list<object>                                     $items    listForUser() rows (no user_* columns)
 * @var list<array{id:int,provider:string,label:string}> $services
 * @var int                                              $maxKeys
 */
$this->extend('layouts/app');

$me            = session('userWorking');
$serviceLabels = array_column($services, 'label', 'id');

$itemsForJs = array_map(static fn (object $item): array => [
    'id'            => (int) $item->id,
    'service_id'    => (int) $item->service_id,
    'service_label' => $serviceLabels[(int) $item->service_id] ?? ('#' . $item->service_id),
    'access_key'    => $item->access_key,
    'is_enable'     => (int) $item->is_enable,
    'enable_log'    => (int) $item->enable_log,
    'description'   => $item->description,
    'allowed_ips'   => $item->allowed_ips,
    'last_access'   => $item->last_access,
    'revealed'      => false,
], $items);
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="apiKeysClient(<?= esc(json_encode($itemsForJs), 'attr') ?>, <?= (int) $maxKeys ?>)" x-init="revealFreshKey()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Your API access keys</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Bearer keys that authenticate your CNR / JPRS proxy calls. Account environment:
                <span class="font-medium text-[#111111] dark:text-gray-100"><?= esc($me->env ?? '—') ?></span>.
            </p>
        </div>
        <button type="button" @click="openCreate()" :disabled="items.length >= maxKeys"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-40 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
            + Create key
        </button>
    </div>

    <p class="mt-2 text-xs text-[#787774] dark:text-gray-400">
        <span x-text="items.length"></span> / <span x-text="maxKeys"></span> keys used
        <span x-show="items.length >= maxKeys" x-cloak> — limit reached, delete a key to create another.</span>
    </p>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3">Service / Key</th>
                    <th class="px-4 py-3">Enabled</th>
                    <th class="px-4 py-3">Log</th>
                    <th class="px-4 py-3">Last access</th>
                    <th class="px-4 py-3">IPs</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <template x-for="item in items" :key="item.id">
                    <tr>
                        <td class="px-4 py-3">
                            <span class="font-medium dark:text-gray-100" x-text="item.service_label"></span>
                            <div class="mt-1 flex items-center gap-2 font-mono text-xs text-[#787774] dark:text-gray-400">
                                <span x-text="item.revealed ? item.access_key : mask(item.access_key)"></span>
                                <button type="button" class="hover:text-[#111111] dark:hover:text-gray-100"
                                        @click="item.revealed = !item.revealed">
                                    <span x-text="item.revealed ? 'Hide' : 'Reveal'"></span>
                                </button>
                                <button type="button" class="hover:text-[#111111] dark:hover:text-gray-100"
                                        @click="copy(item.access_key)">Copy</button>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <button type="button" @click="toggleEnable(item)"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                    :class="item.is_enable ? 'bg-[#346538]' : 'bg-[#EAEAEA] dark:bg-gray-700'"
                                    :aria-pressed="item.is_enable ? 'true' : 'false'" aria-label="Toggle enabled">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                      :class="item.is_enable ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <button type="button" @click="toggleLog(item)"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                    :class="item.enable_log ? 'bg-[#1F6C9F]' : 'bg-[#EAEAEA] dark:bg-gray-700'"
                                    :aria-pressed="item.enable_log ? 'true' : 'false'" aria-label="Toggle request logging">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                      :class="item.enable_log ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400" x-text="item.last_access || '—'"></td>
                        <td class="px-4 py-3">
                            <span x-show="item.allowed_ips && item.allowed_ips.trim().length" x-cloak
                                  :title="item.allowed_ips"
                                  class="inline-flex items-center gap-1 rounded-full bg-[#E1F3FE] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#1F6C9F] dark:bg-blue-900/40 dark:text-blue-300">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                                    <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v8a2 2 0 002 2h10a2 2 0 002-2v-8a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                                </svg>
                                IP-restricted
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openIpModal(item)">IP restrictions</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400" @click="confirmDelete(item)">Delete</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="items.length === 0" x-cloak>
                    <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                        No API access keys yet. Create one to start calling the proxy.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Create modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-key-title">
            <h2 id="create-key-title" class="text-base font-semibold dark:text-gray-100">Create API access key</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_service_id" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Service</label>
                    <select id="create_service_id" x-model="createForm.service_id" required
                            class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                        <option value="">Select a service…</option>
                        <?php foreach ($services as $s) : ?>
                            <option value="<?= esc((string) $s['id'], 'attr') ?>"><?= esc($s['label']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="create_allowed_ips" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Allowed IPs</label>
                    <textarea id="create_allowed_ips" x-model="createForm.allowed_ips" rows="3"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 font-mono text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-500">One IP or CIDR per line (or comma-separated). IPv4/IPv6. Leave blank to allow all IPs.</p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700" @click="createOpen = false">Cancel</button>
                    <button type="submit" :disabled="creating"
                            class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                        <span x-show="!creating">Create</span>
                        <span x-show="creating" x-cloak>Creating…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- IP restrictions modal (edit allowed_ips for an existing key) -->
    <div x-show="ipModalOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="ipModalOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="ipModalOpen = false"></div>
        <div x-show="ipModalOpen" x-transition
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
             x-trap.noscroll="ipModalOpen" role="dialog" aria-modal="true" aria-labelledby="ip-restrictions-title">
            <h2 id="ip-restrictions-title" class="text-base font-semibold dark:text-gray-100">IP restrictions</h2>
            <form @submit.prevent="submitIpModal" class="mt-4 space-y-4">
                <div>
                    <label for="ip_modal_allowed_ips" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Allowed IPs</label>
                    <textarea id="ip_modal_allowed_ips" x-model="ipModalForm.allowed_ips" rows="4"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 font-mono text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-500">One IP or CIDR per line (or comma-separated). IPv4/IPv6. Leave blank to allow all IPs.</p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700" @click="ipModalOpen = false">Cancel</button>
                    <button type="submit" :disabled="savingIps"
                            class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                        <span x-show="!savingIps">Save</span>
                        <span x-show="savingIps" x-cloak>Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function apiKeysClient(initialItems, maxKeys) {
            return {
                items: initialItems,
                maxKeys,
                creating: false,
                createOpen: false,
                createForm: { service_id: '', allowed_ips: '' },
                savingIps: false,
                ipModalOpen: false,
                ipModalForm: { id: null, allowed_ips: '' },

                mask(key) {
                    if (!key || key.length <= 12) return key || '';
                    return key.slice(0, 6) + '••••••••' + key.slice(-4);
                },

                async copy(key) {
                    if (await window.copyText(key)) {
                        this.$dispatch('toast', { type: 'success', text: 'Access key copied to clipboard.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: 'Could not copy — copy it manually.' });
                    }
                },

                // After a create + reload, auto-reveal the just-created key
                // once so the user can copy it (the response only returns
                // the raw key, not its row id — see kanban plan note).
                revealFreshKey() {
                    const freshKey = sessionStorage.getItem('freshApiKey');
                    if (!freshKey) return;
                    sessionStorage.removeItem('freshApiKey');
                    const item = this.items.find(i => i.access_key === freshKey);
                    if (item) item.revealed = true;
                    this.$dispatch('alert', { variant: 'success', text: 'New API access key created — copy it now from the highlighted row.' });
                },

                openCreate() {
                    if (this.items.length >= this.maxKeys) return;
                    this.createForm = { service_id: '', allowed_ips: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    if (!this.createForm.service_id) {
                        this.$dispatch('toast', { type: 'error', text: 'Select a service.' });
                        return;
                    }
                    this.creating = true;
                    const json = await postForm('/my-api-access-keys/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        if (json.access_key) sessionStorage.setItem('freshApiKey', json.access_key);
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the key.' });
                    }
                },

                async toggleEnable(item) {
                    const next = item.is_enable ? 0 : 1;
                    const json = await postForm('/my-api-access-keys/toggle', { id: item.id, is_enable: next });
                    if (json && json.status === 'success') {
                        item.is_enable = next;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update.' });
                    }
                },

                async toggleLog(item) {
                    const next = item.enable_log ? 0 : 1;
                    const json = await postForm('/my-api-access-keys/toggle-log', { id: item.id, enable_log: next });
                    if (json && json.status === 'success') {
                        item.enable_log = next;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update.' });
                    }
                },

                openIpModal(item) {
                    this.ipModalForm = { id: item.id, allowed_ips: item.allowed_ips || '' };
                    this.ipModalOpen = true;
                },

                async submitIpModal() {
                    this.savingIps = true;
                    const json = await postForm('/my-api-access-keys/allowed-ips', this.ipModalForm);
                    this.savingIps = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        const target = this.items.find(i => i.id === this.ipModalForm.id);
                        if (target) target.allowed_ips = this.ipModalForm.allowed_ips;
                        this.ipModalOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update IP restrictions.' });
                    }
                },

                confirmDelete(item) {
                    this.$dispatch('confirm', {
                        title: 'Delete API access key',
                        body: 'This will permanently remove this key. Any client using it will lose proxy access immediately. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: () => this.doDelete(item),
                    });
                },

                async doDelete(item) {
                    const json = await postForm('/my-api-access-keys/remove', { id: item.id });
                    if (json && json.status === 'success') {
                        this.items = this.items.filter(i => i.id !== item.id);
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
