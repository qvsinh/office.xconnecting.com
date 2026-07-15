<?php
/**
 * Admin API access keys management: search + paginate every key across all
 * users, create for any user, edit service/description/flags, toggle
 * enable/log, delete. All mutations via fetch() + the shared toast/confirm
 * components. `user_id` is intentionally absent from the edit form — see
 * Admin\ApiAccessKeys::update() for why reassignment isn't supported.
 *
 * @var list<object>                                     $items    searchAdmin() rows (+ user_name/user_email/user_env)
 * @var int                                              $total
 * @var int                                              $page
 * @var int                                              $limit
 * @var string                                           $keyword
 * @var list<object>                                     $users    id/name/email — for the create modal
 * @var list<array{id:int,provider:string,label:string}> $services
 */
$this->extend('layouts/app');

$totalPages    = max(1, (int) ceil($total / max($limit, 1)));
$serviceLabels = array_column($services, 'label', 'id');

$pageUrl = static fn (int $targetPage): string => '?' . http_build_query(['keyword' => $keyword, 'limit' => $limit, 'page' => $targetPage]);

$itemsForJs = array_map(static fn (object $item): array => [
    'id'            => (int) $item->id,
    'user_id'       => (int) $item->user_id,
    'user_name'     => $item->user_name,
    'user_email'    => $item->user_email,
    'user_env'      => $item->user_env,
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
<div class="mx-auto max-w-7xl" x-data="apiKeysAdmin(<?= esc(json_encode($itemsForJs), 'attr') ?>)">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">API access keys</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage bearer keys for every user's CNR / JPRS proxy access.</p>
        </div>
        <button type="button" @click="openCreate()"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
            + Create key
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($keyword, 'attr') ?>"
                   placeholder="Email, name, key id, user id, or service id"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
        </div>
        <div>
            <label for="limit" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Per page</label>
            <select id="limit" name="limit"
                    class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">Search</button>
        <?php if ($keyword !== '') : ?>
            <button type="button" @click="location.href='?limit=<?= esc((string) $limit, 'js') ?>'"
                    class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium text-[#787774] hover:bg-gray-50 hover:text-[#111111] dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100">Clear</button>
        <?php endif ?>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Service</th>
                    <th class="px-4 py-3">Env</th>
                    <th class="px-4 py-3">Access key</th>
                    <th class="px-4 py-3">Enabled</th>
                    <th class="px-4 py-3">Log</th>
                    <th class="px-4 py-3">Last access</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3">IPs</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <template x-for="item in items" :key="item.id">
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium dark:text-gray-100" x-text="item.user_name || ('User #' + item.user_id)"></p>
                            <p class="text-xs text-[#787774] dark:text-gray-400" x-text="item.user_email || ''"></p>
                        </td>
                        <td class="px-4 py-3 dark:text-gray-100" x-text="item.service_label"></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide"
                                  :class="item.user_env === 'LIVE' ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300'"
                                  x-text="item.user_env || '—'"></span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs dark:text-gray-100">
                            <div class="flex items-center gap-2">
                                <span x-text="item.revealed ? item.access_key : mask(item.access_key)"></span>
                                <button type="button" class="text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100"
                                        @click="item.revealed = !item.revealed">
                                    <span x-text="item.revealed ? 'Hide' : 'Reveal'"></span>
                                </button>
                                <button type="button" class="text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100"
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
                        <td class="max-w-[200px] truncate px-4 py-3 dark:text-gray-100" :title="item.description" x-text="item.description || '—'"></td>
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
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openEdit(item)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400" @click="confirmDelete(item)">Delete</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="items.length === 0" x-cloak>
                    <td colspan="10" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No API access keys match your search.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> key<?= $total === 1 ? '' : 's' ?> total</p>
        <div class="flex items-center gap-2">
            <?php if ($page > 1) : ?>
                <a href="<?= esc($pageUrl($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Prev</a>
            <?php endif ?>
            <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
            <?php if ($page < $totalPages) : ?>
                <a href="<?= esc($pageUrl($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Next</a>
            <?php endif ?>
        </div>
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
                    <label for="create_user_id" class="mb-1.5 block text-sm font-medium dark:text-gray-200">User</label>
                    <select id="create_user_id" x-model="createForm.user_id" required
                            class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                        <option value="">Select a user…</option>
                        <?php foreach ($users as $u) : ?>
                            <option value="<?= esc((string) $u->id, 'attr') ?>"><?= esc($u->name) ?> (<?= esc($u->email) ?>)</option>
                        <?php endforeach ?>
                    </select>
                </div>
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
                    <label for="create_description" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Description</label>
                    <input id="create_description" type="text" x-model="createForm.description" maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
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

    <!-- Edit modal (no user field — reassignment isn't supported, see controller note) -->
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-key-title">
            <h2 id="edit-key-title" class="text-base font-semibold dark:text-gray-100">Edit API access key</h2>
            <form @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div>
                    <label class="mb-1.5 block text-sm font-medium dark:text-gray-200">Service</label>
                    <p class="rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 text-sm text-[#787774] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400"
                       x-text="editForm.service_label"></p>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-500">Service is fixed for the life of a key (it's encoded into the token). To use a different service, create a new key.</p>
                </div>
                <div>
                    <label for="edit_description" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Description</label>
                    <input id="edit_description" type="text" x-model="editForm.description" maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="edit_allowed_ips" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Allowed IPs</label>
                    <textarea id="edit_allowed_ips" x-model="editForm.allowed_ips" rows="3"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 font-mono text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-500">One IP or CIDR per line (or comma-separated). IPv4/IPv6. Leave blank to allow all IPs.</p>
                </div>
                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 text-sm dark:text-gray-200">
                        <input type="checkbox" :checked="editForm.is_enable === 1"
                               @change="editForm.is_enable = $event.target.checked ? 1 : 0"
                               class="rounded border-[#EAEAEA] text-[#111111] focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900">
                        Enabled
                    </label>
                    <label class="flex items-center gap-2 text-sm dark:text-gray-200">
                        <input type="checkbox" :checked="editForm.enable_log === 1"
                               @change="editForm.enable_log = $event.target.checked ? 1 : 0"
                               class="rounded border-[#EAEAEA] text-[#111111] focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900">
                        Log requests
                    </label>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700" @click="editOpen = false">Cancel</button>
                    <button type="submit" :disabled="editing"
                            class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                        <span x-show="!editing">Save</span>
                        <span x-show="editing" x-cloak>Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function apiKeysAdmin(initialItems) {
            return {
                items: initialItems,
                creating: false,
                createOpen: false,
                createForm: { user_id: '', service_id: '', description: '', allowed_ips: '' },
                editing: false,
                editOpen: false,
                editForm: { id: null, service_label: '', description: '', allowed_ips: '', is_enable: 1, enable_log: 1 },

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

                openCreate() {
                    this.createForm = { user_id: '', service_id: '', description: '', allowed_ips: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    if (!this.createForm.user_id || !this.createForm.service_id) {
                        this.$dispatch('toast', { type: 'error', text: 'Select a user and a service.' });
                        return;
                    }
                    this.creating = true;
                    const json = await postForm('/admin/api-access-keys/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the key.' });
                    }
                },

                openEdit(item) {
                    // service_label is display-only: service is immutable after
                    // creation (it's encoded into the token), so it's shown, not sent.
                    this.editForm = {
                        id: item.id,
                        service_label: item.service_label,
                        description: item.description || '',
                        allowed_ips: item.allowed_ips || '',
                        is_enable: item.is_enable,
                        enable_log: item.enable_log,
                    };
                    this.editOpen = true;
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/api-access-keys/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        const target = this.items.find(i => i.id === this.editForm.id);
                        if (target) {
                            // service_id unchanged (immutable) — don't touch it.
                            target.description = this.editForm.description;
                            target.allowed_ips = this.editForm.allowed_ips;
                            target.is_enable   = this.editForm.is_enable;
                            target.enable_log  = this.editForm.enable_log;
                        }
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the key.' });
                    }
                },

                async toggleEnable(item) {
                    const next = item.is_enable ? 0 : 1;
                    const json = await postForm('/admin/api-access-keys/toggle', { id: item.id, is_enable: next });
                    if (json && json.status === 'success') {
                        item.is_enable = next;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update.' });
                    }
                },

                async toggleLog(item) {
                    const next = item.enable_log ? 0 : 1;
                    const json = await postForm('/admin/api-access-keys/toggle-log', { id: item.id, enable_log: next });
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
                        body: 'This will permanently remove the key for ' + (item.user_name || ('user #' + item.user_id)) + '. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: () => this.doDelete(item),
                    });
                },

                async doDelete(item) {
                    const json = await postForm('/admin/api-access-keys/delete', { id: item.id });
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
