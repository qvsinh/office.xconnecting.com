<?php
/**
 * Admin user groups (`user_groups`): search + paginate, create/edit via a
 * shared Alpine modal, delete via the shared confirm modal. Mirrors
 * `admin/settings/index.php` exactly.
 *
 * @var list<object>          $items   rows (id/name/slug/description/member_count/created_at)
 * @var int                   $total
 * @var int                   $page
 * @var int                   $limit
 * @var string                $sort
 * @var string                $order
 * @var array{keyword:string} $filters
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
<div class="mx-auto max-w-7xl" x-data="groupsAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Groups</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage user groups (departments/teams) and their membership.</p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary">
            + Add group
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="form-label">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Name, slug or description" class="form-input">
        </div>
        <input type="hidden" name="limit" value="<?= esc((string) $limit, 'attr') ?>">
        <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
        <input type="hidden" name="order" value="<?= esc($order, 'attr') ?>">
        <button type="submit" class="btn-secondary">Search</button>
        <button type="button" @click="location.href='?limit=<?= esc((string) $limit, 'js') ?>'" class="btn-secondary">Clear</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('name'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Name<?= esc($sortMark('name')) ?></a></th>
                    <th class="px-4 py-3">Slug</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3 text-right">Members</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('created_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Created<?= esc($sortMark('created_at')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc($item->name ?? '') ?></td>
                        <td class="px-4 py-3 text-[#787774] dark:text-gray-400"><?= esc($item->slug ?? '') ?></td>
                        <td class="max-w-xs truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->description ?? ''), 'attr') ?>"><?= esc((string) ($item->description ?? '—')) ?></td>
                        <td class="px-4 py-3 text-right"><?= esc((string) ($item->member_count ?? 0)) ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc($item->created_at ?? '—') ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="/admin/groups/members/<?= (int) $item->id ?>" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100">Members</a>
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                    @click="confirmDelete(<?= (int) $item->id ?>, <?= esc(json_encode($item->name ?? ''), 'attr') ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No groups match your search.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <?= view('_pagination', [
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages,
        'limit'      => $limit,
        'pageUrl'    => $pageUrl,
        'noun'       => 'group',
    ]) ?>

    <!-- Create modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-group-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="createOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="create-group-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Add group</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_name" class="form-label">Name</label>
                    <input id="create_name" type="text" x-model="createForm.name" required maxlength="150" class="form-input">
                </div>
                <div>
                    <label for="create_description" class="form-label">Description</label>
                    <textarea id="create_description" x-model="createForm.description" rows="2" class="form-textarea"></textarea>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="createOpen = false">Cancel</button>
                    <button type="submit" :disabled="creating" class="btn-primary">
                        <span x-show="!creating">Create</span>
                        <span x-show="creating" x-cloak>Creating…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit modal -->
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-group-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="editOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="edit-group-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Edit group</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>
            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div>
                    <label for="edit_name" class="form-label">Name</label>
                    <input id="edit_name" type="text" x-model="editForm.name" required maxlength="150" class="form-input">
                </div>
                <div>
                    <label for="edit_description" class="form-label">Description</label>
                    <textarea id="edit_description" x-model="editForm.description" rows="2" class="form-textarea"></textarea>
                </div>
                <p class="text-xs text-[#787774] dark:text-gray-400">Slug: <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 font-medium dark:bg-gray-700" x-text="editSlug"></span> (auto-generated, not editable)</p>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="editOpen = false">Cancel</button>
                    <button type="submit" :disabled="editing" class="btn-primary">
                        <span x-show="!editing">Save</span>
                        <span x-show="editing" x-cloak>Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function groupsAdmin() {
            return {
                creating: false,
                createOpen: false,
                createForm: { name: '', description: '' },
                editing: false,
                editOpen: false,
                editLoading: false,
                editSlug: '',
                editForm: { id: null, name: '', description: '' },

                openCreate() {
                    this.createForm = { name: '', description: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/groups/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the group.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/groups/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Group not found.' });
                        return;
                    }
                    this.editSlug = json.group.slug;
                    this.editForm = {
                        id: json.group.id,
                        name: json.group.name,
                        description: json.group.description,
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/groups/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the group.' });
                    }
                },

                confirmDelete(id, name) {
                    this.$dispatch('confirm', {
                        title: 'Delete group',
                        body: 'This will remove "' + name + '" and its membership. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/groups/delete', { id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the group.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
