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
    'last_access'   => $item->last_access,
    'revealed'      => false,
], $items);
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="apiKeysClient(<?= esc(json_encode($itemsForJs), 'attr') ?>, <?= (int) $maxKeys ?>)" x-init="revealFreshKey()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Your API access keys</h1>
            <p class="mt-1 text-sm text-[#787774]">
                Bearer keys that authenticate your CNR / JPRS proxy calls. Account environment:
                <span class="font-medium text-[#111111]"><?= esc($me->env ?? '—') ?></span>.
            </p>
        </div>
        <button type="button" @click="openCreate()" :disabled="items.length >= maxKeys"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-40">
            + Create key
        </button>
    </div>

    <p class="mt-2 text-xs text-[#787774]">
        <span x-text="items.length"></span> / <span x-text="maxKeys"></span> keys used
        <span x-show="items.length >= maxKeys" x-cloak> — limit reached, delete a key to create another.</span>
    </p>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774]">
                    <th class="px-4 py-3">Service</th>
                    <th class="px-4 py-3">Access key</th>
                    <th class="px-4 py-3">Enabled</th>
                    <th class="px-4 py-3">Log</th>
                    <th class="px-4 py-3">Last access</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA]">
                <template x-for="item in items" :key="item.id">
                    <tr>
                        <td class="px-4 py-3" x-text="item.service_label"></td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <div class="flex items-center gap-2">
                                <span x-text="item.revealed ? item.access_key : mask(item.access_key)"></span>
                                <button type="button" class="text-[#787774] hover:text-[#111111]"
                                        @click="item.revealed = !item.revealed">
                                    <span x-text="item.revealed ? 'Hide' : 'Reveal'"></span>
                                </button>
                                <button type="button" class="text-[#787774] hover:text-[#111111]"
                                        @click="copy(item.access_key)">Copy</button>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <button type="button" @click="toggleEnable(item)"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                    :class="item.is_enable ? 'bg-[#346538]' : 'bg-[#EAEAEA]'"
                                    :aria-pressed="item.is_enable ? 'true' : 'false'" aria-label="Toggle enabled">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                      :class="item.is_enable ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <button type="button" @click="toggleLog(item)"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                    :class="item.enable_log ? 'bg-[#1F6C9F]' : 'bg-[#EAEAEA]'"
                                    :aria-pressed="item.enable_log ? 'true' : 'false'" aria-label="Toggle request logging">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                      :class="item.enable_log ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-xs text-[#787774]" x-text="item.last_access || '—'"></td>
                        <td class="max-w-[220px] truncate px-4 py-3" :title="item.description" x-text="item.description || '—'"></td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline" @click="confirmDelete(item)">Delete</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="items.length === 0" x-cloak>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-[#787774]">
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
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-key-title">
            <h2 id="create-key-title" class="text-base font-semibold">Create API access key</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_service_id" class="mb-1.5 block text-sm font-medium">Service</label>
                    <select id="create_service_id" x-model="createForm.service_id" required
                            class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                        <option value="">Select a service…</option>
                        <?php foreach ($services as $s) : ?>
                            <option value="<?= esc((string) $s['id'], 'attr') ?>"><?= esc($s['label']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50" @click="createOpen = false">Cancel</button>
                    <button type="submit" :disabled="creating"
                            class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-show="!creating">Create</span>
                        <span x-show="creating" x-cloak>Creating…</span>
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
                createForm: { service_id: '' },

                mask(key) {
                    if (!key || key.length <= 12) return key || '';
                    return key.slice(0, 6) + '••••••••' + key.slice(-4);
                },

                async copy(key) {
                    try {
                        await navigator.clipboard.writeText(key);
                        this.$dispatch('toast', { type: 'success', text: 'Access key copied to clipboard.' });
                    } catch (e) {
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
                    this.createForm = { service_id: '' };
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
