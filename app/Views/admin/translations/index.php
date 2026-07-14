<?php
/**
 * Admin UI translation strings (`translation` table, keyed by
 * `(lang_code, slug)`): search + language filter + paginate, create/edit via
 * a shared Alpine modal, delete via the shared confirm modal, plus a per-
 * language JSON export link. Mirrors `admin/settings/index.php`.
 *
 * @var list<object>                             $items   searchAdmin() rows (id/lang_code/location/slug/content)
 * @var int                                       $total
 * @var int                                       $page
 * @var int                                       $limit
 * @var string                                    $sort
 * @var string                                    $order
 * @var array{keyword:string, lang_code:string}   $filters
 * @var list<string>                              $langCodes
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'keyword'   => $filters['keyword'] ?? '',
    'lang_code' => $filters['lang_code'] ?? '',
    'sort'      => $sort,
    'order'     => $order,
    'limit'     => $limit,
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
<div class="mx-auto max-w-7xl" x-data="translationsAdmin()">

    <datalist id="lang-code-suggestions">
        <?php foreach ($langCodes as $code) : ?>
            <option value="<?= esc((string) $code, 'attr') ?>"></option>
        <?php endforeach ?>
    </datalist>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Translations</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage UI translation strings per language and slug.</p>
        </div>
        <button type="button" @click="openCreate()"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
            + Add translation
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Slug or content"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
        </div>
        <div>
            <label for="lang_code" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Language</label>
            <select id="lang_code" name="lang_code"
                    class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <option value="">All languages</option>
                <?php foreach ($langCodes as $code) : ?>
                    <option value="<?= esc((string) $code, 'attr') ?>" <?= ($filters['lang_code'] ?? '') === $code ? 'selected' : '' ?>><?= esc((string) $code) ?></option>
                <?php endforeach ?>
            </select>
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

    <div class="mt-4 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="export_lang" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Export language</label>
            <select id="export_lang" x-model="exportLang"
                    class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <option value="">Choose a language…</option>
                <?php foreach ($langCodes as $code) : ?>
                    <option value="<?= esc((string) $code, 'attr') ?>"><?= esc((string) $code) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <a :href="exportHref()" :aria-disabled="!exportLang"
           @click="if (!exportLang) $event.preventDefault()"
           :class="exportLang ? '' : 'pointer-events-none opacity-50'"
           class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">
            Export JSON
        </a>
    </div>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('lang_code'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Lang<?= esc($sortMark('lang_code')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('slug'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Slug<?= esc($sortMark('slug')) ?></a></th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3">Content</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                <?= esc($item->lang_code ?? '') ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium"><?= esc($item->slug ?? '') ?></td>
                        <td class="max-w-xs truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->location ?? ''), 'attr') ?>"><?= esc((string) ($item->location ?? '—')) ?></td>
                        <td class="max-w-sm truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->content ?? ''), 'attr') ?>"><?= esc((string) ($item->content ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                    @click="confirmDelete(<?= (int) $item->id ?>, <?= esc(json_encode($item->lang_code ?? ''), 'attr') ?>, <?= esc(json_encode($item->slug ?? ''), 'attr') ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No translations match your search.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> translation<?= $total === 1 ? '' : 's' ?> total</p>
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
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-translation-title">
            <h2 id="create-translation-title" class="text-base font-semibold dark:text-gray-100">Add translation</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_lang_code" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Language code</label>
                    <input id="create_lang_code" type="text" x-model="createForm.lang_code" required maxlength="5" list="lang-code-suggestions"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="create_slug" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Slug</label>
                    <input id="create_slug" type="text" x-model="createForm.slug" required maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="create_location" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Location <span class="font-normal text-[#787774] dark:text-gray-400">(optional)</span></label>
                    <input id="create_location" type="text" x-model="createForm.location" maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="create_content" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Content</label>
                    <textarea id="create_content" x-model="createForm.content" rows="4" required
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
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-translation-title">
            <h2 id="edit-translation-title" class="text-base font-semibold dark:text-gray-100">Edit translation</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>
            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div>
                    <label for="edit_lang_code" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Language code</label>
                    <input id="edit_lang_code" type="text" x-model="editForm.lang_code" required maxlength="5" list="lang-code-suggestions"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="edit_slug" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Slug</label>
                    <input id="edit_slug" type="text" x-model="editForm.slug" required maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <p class="text-xs text-[#787774] dark:text-gray-400">Language + slug together must stay unique.</p>
                <div>
                    <label for="edit_location" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Location <span class="font-normal text-[#787774] dark:text-gray-400">(optional)</span></label>
                    <input id="edit_location" type="text" x-model="editForm.location" maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                </div>
                <div>
                    <label for="edit_content" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Content</label>
                    <textarea id="edit_content" x-model="editForm.content" rows="4" required
                              class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
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
        function translationsAdmin() {
            return {
                creating: false,
                createOpen: false,
                createForm: { lang_code: '', location: '', slug: '', content: '' },
                editing: false,
                editOpen: false,
                editLoading: false,
                editForm: { id: null, lang_code: '', location: '', slug: '', content: '' },
                exportLang: <?= json_encode($filters['lang_code'] ?? '') ?>,

                openCreate() {
                    this.createForm = { lang_code: '', location: '', slug: '', content: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/translations/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the translation.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/translations/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Translation not found.' });
                        return;
                    }
                    this.editForm = {
                        id: json.translation.id,
                        lang_code: json.translation.lang_code,
                        location: json.translation.location,
                        slug: json.translation.slug,
                        content: json.translation.content,
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/translations/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the translation.' });
                    }
                },

                confirmDelete(id, langCode, slug) {
                    this.$dispatch('confirm', {
                        title: 'Delete translation',
                        body: 'This will permanently remove "' + langCode + ' / ' + slug + '". This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/translations/delete', { id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the translation.' });
                            }
                        },
                    });
                },

                exportHref() {
                    return this.exportLang ? '/admin/translations/export?lang_code=' + encodeURIComponent(this.exportLang) : '#';
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
</content>
