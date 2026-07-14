<?php
/**
 * Admin site settings (`site_variable` key/value config): search + paginate,
 * create/edit via a shared Alpine modal, delete via the shared confirm modal.
 * `type` is not user-editable (DB default `'string'`) — shown read-only.
 * Mirrors `admin/servers/index.php` exactly.
 *
 * @var list<object>                       $items   searchAdmin() rows (id/name/value/type/description/created_at)
 * @var int                                $total
 * @var int                                $page
 * @var int                                $limit
 * @var string                             $sort
 * @var string                             $order
 * @var array{keyword:string, type:string} $filters
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'keyword' => $filters['keyword'] ?? '',
    'sort'    => $sort,
    'order'   => $order,
    'limit'   => $limit,
];

$buildQuery = static fn (array $extra): string => '?' . http_build_query(
    array_filter($queryBase + $extra, static fn ($v): bool => $v !== '' && $v !== null),
);

$pageUrl = static fn (int $target): string => $buildQuery(['page' => $target]);
$sortUrl = static fn (string $col): string => $buildQuery([
    'sort'  => $col,
    'order' => ($sort === $col && strtolower($order) === 'asc') ? 'desc' : 'asc',
]);
$sortMark = static function (string $col) use ($sort, $order): string {
    if ($sort !== $col) {
        return '';
    }

    return strtolower($order) === 'asc' ? ' ▲' : ' ▼';
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="settingsAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Settings</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage site variables / config key-values.</p>
        </div>
        <button type="button" @click="openCreate()"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
            + Add setting
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Name, value or description"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
        </div>
        <div>
            <label for="limit" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Per page</label>
            <select id="limit" name="limit" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
        <input type="hidden" name="order" value="<?= esc($order, 'attr') ?>">
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">Search</button>
        <button type="button" @click="location.href='?limit=<?= esc((string) $limit, 'js') ?>'"
                class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium text-[#787774] hover:bg-gray-50 hover:text-[#111111] dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100">Clear</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('name'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Name<?= esc($sortMark('name')) ?></a></th>
                    <th class="px-4 py-3">Value</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('type'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Type<?= esc($sortMark('type')) ?></a></th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('created_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Created<?= esc($sortMark('created_at')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc($item->name ?? '') ?></td>
                        <td class="max-w-xs truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->value ?? ''), 'attr') ?>"><?= esc((string) ($item->value ?? '—')) ?></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                <?= esc($item->type ?? 'string') ?>
                            </span>
                        </td>
                        <td class="max-w-xs truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->description ?? ''), 'attr') ?>"><?= esc((string) ($item->description ?? '—')) ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc($item->created_at ?? '—') ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                    @click="confirmDelete(<?= (int) $item->id ?>, <?= esc(json_encode($item->name ?? ''), 'attr') ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No settings match your search.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> setting<?= $total === 1 ? '' : 's' ?> total</p>
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
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-setting-title">
            <h2 id="create-setting-title" class="text-base font-semibold dark:text-gray-100">Add setting</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_name" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Name</label>
                    <input id="create_name" type="text" x-model="createForm.name" required maxlength="100"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="create_value" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Value</label>
                    <textarea id="create_value" x-model="createForm.value" rows="3" maxlength="255"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
                </div>
                <div>
                    <label for="create_description" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Description</label>
                    <textarea id="create_description" x-model="createForm.description" rows="2"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
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

    <!-- Edit modal -->
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-setting-title">
            <h2 id="edit-setting-title" class="text-base font-semibold dark:text-gray-100">Edit setting</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>
            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div>
                    <label for="edit_name" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Name</label>
                    <input id="edit_name" type="text" x-model="editForm.name" required maxlength="100"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="edit_value" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Value</label>
                    <textarea id="edit_value" x-model="editForm.value" rows="3" maxlength="255"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
                </div>
                <div>
                    <label for="edit_description" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Description</label>
                    <textarea id="edit_description" x-model="editForm.description" rows="2"
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
                </div>
                <p class="text-xs text-[#787774] dark:text-gray-400">Type: <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 font-medium uppercase tracking-wide dark:bg-gray-700" x-text="editType"></span> (not editable)</p>
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
        function settingsAdmin() {
            return {
                creating: false,
                createOpen: false,
                createForm: { name: '', value: '', description: '' },
                editing: false,
                editOpen: false,
                editLoading: false,
                editType: 'string',
                editForm: { id: null, name: '', value: '', description: '' },

                openCreate() {
                    this.createForm = { name: '', value: '', description: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/settings/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the setting.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/settings/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Setting not found.' });
                        return;
                    }
                    this.editType = json.setting.type;
                    this.editForm = {
                        id: json.setting.id,
                        name: json.setting.name,
                        value: json.setting.value,
                        description: json.setting.description,
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/settings/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the setting.' });
                    }
                },

                confirmDelete(id, name) {
                    this.$dispatch('confirm', {
                        title: 'Delete setting',
                        body: 'This will remove "' + name + '". This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/settings/delete', { id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the setting.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
