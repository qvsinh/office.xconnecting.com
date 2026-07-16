<?php
/**
 * Admin email templates (`email_templates` table, keyed by
 * `(name, lang_code)`): search + language filter + paginate, create/edit via
 * a shared Alpine modal, delete via the shared confirm modal. Mirrors
 * `admin/translations/index.php`.
 *
 * `content` is trusted admin-authored HTML — never interpolated into this
 * page's DOM. The "Preview" panel in the create/edit modal renders it only
 * inside a sandboxed `<iframe srcdoc>` (no `allow-scripts`), bound via
 * Alpine's `:srcdoc` attribute binding (not `x-html`), so it can never touch
 * the surrounding admin chrome.
 *
 * @var list<object>                            $items   searchAdmin() rows (id/name/lang_code/subject/content/active)
 * @var int                                     $total
 * @var int                                     $page
 * @var int                                     $limit
 * @var string                                  $sort
 * @var string                                  $order
 * @var array{keyword:string, lang_code:string} $filters
 * @var list<string>                            $langCodes
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
<div class="mx-auto max-w-7xl" x-data="emailTemplatesAdmin()">

    <datalist id="lang-code-suggestions">
        <?php foreach ($langCodes as $code) : ?>
            <option value="<?= esc((string) $code, 'attr') ?>"></option>
        <?php endforeach ?>
    </datalist>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Email Templates</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage the HTML subject/body templates the platform sends, per language.</p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary">
            + Add template
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="form-label">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Name or subject" class="form-input">
        </div>
        <div>
            <label for="lang_code" class="form-label">Language</label>
            <select id="lang_code" name="lang_code" class="form-select">
                <option value="">All languages</option>
                <?php foreach ($langCodes as $code) : ?>
                    <option value="<?= esc((string) $code, 'attr') ?>" <?= ($filters['lang_code'] ?? '') === $code ? 'selected' : '' ?>><?= esc((string) $code) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="limit" class="form-label">Per page</label>
            <select id="limit" name="limit" class="form-select">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
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
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('lang_code'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Lang<?= esc($sortMark('lang_code')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('subject'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Subject<?= esc($sortMark('subject')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('active'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Active<?= esc($sortMark('active')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc($item->name ?? '') ?></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                <?= esc($item->lang_code ?? '') ?>
                            </span>
                        </td>
                        <td class="max-w-sm truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->subject ?? ''), 'attr') ?>"><?= esc((string) ($item->subject ?? '—')) ?></td>
                        <td class="px-4 py-3">
                            <?php if ((int) ($item->active ?? 0) === 1) : ?>
                                <span class="rounded-full bg-[#EDF3EC] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#346538] dark:bg-green-900/30 dark:text-green-300">Active</span>
                            <?php else : ?>
                                <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">Inactive</span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                    @click="confirmDelete(<?= (int) $item->id ?>, <?= esc(json_encode($item->name ?? ''), 'attr') ?>, <?= esc(json_encode($item->lang_code ?? ''), 'attr') ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No email templates match your search.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> template<?= $total === 1 ? '' : 's' ?> total</p>
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
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4 py-8"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="modal-panel relative max-h-full max-w-2xl overflow-y-auto"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-template-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="createOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="create-template-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Add email template</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="create_name" class="form-label">Name</label>
                        <input id="create_name" type="text" x-model="createForm.name" required maxlength="255" class="form-input">
                    </div>
                    <div>
                        <label for="create_lang_code" class="form-label">Language code</label>
                        <input id="create_lang_code" type="text" x-model="createForm.lang_code" required maxlength="5" list="lang-code-suggestions" class="form-input">
                    </div>
                </div>
                <p class="text-xs text-[#787774] dark:text-gray-400">Name + language together must stay unique.</p>
                <div>
                    <label for="create_subject" class="form-label">Subject</label>
                    <input id="create_subject" type="text" x-model="createForm.subject" required maxlength="255" class="form-input">
                </div>
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <label for="create_content" class="form-label mb-0">Content (HTML)</label>
                        <button type="button" class="text-xs font-medium text-[#111111] hover:underline dark:text-gray-100" @click="createPreview = !createPreview">
                            <span x-text="createPreview ? 'Hide preview' : 'Preview'"></span>
                        </button>
                    </div>
                    <textarea id="create_content" x-model="createForm.content" rows="10" required class="form-textarea font-mono text-xs"></textarea>
                    <!-- Sandboxed preview: srcdoc bound via Alpine attribute binding
                         (never x-html), no allow-scripts so the trusted-but-untrusted
                         admin HTML can render without ever executing in this page. -->
                    <iframe x-show="createPreview" x-cloak :srcdoc="createForm.content" sandbox=""
                            class="mt-2 h-64 w-full rounded-md border border-[#EAEAEA] bg-white dark:border-gray-700"
                            title="Template preview"></iframe>
                </div>
                <label class="flex items-center gap-2 text-sm dark:text-gray-200">
                    <input type="checkbox" x-model="createForm.active" class="rounded border-[#EAEAEA] dark:border-gray-700">
                    Active
                </label>
                <div class="flex justify-end gap-2 pt-2">
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
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4 py-8"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition
             class="modal-panel relative max-h-full max-w-2xl overflow-y-auto"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-template-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="editOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="edit-template-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Edit email template</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>
            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit_name" class="form-label">Name</label>
                        <input id="edit_name" type="text" x-model="editForm.name" required maxlength="255" class="form-input">
                    </div>
                    <div>
                        <label for="edit_lang_code" class="form-label">Language code</label>
                        <input id="edit_lang_code" type="text" x-model="editForm.lang_code" required maxlength="5" list="lang-code-suggestions" class="form-input">
                    </div>
                </div>
                <p class="text-xs text-[#787774] dark:text-gray-400">Name + language together must stay unique.</p>
                <div>
                    <label for="edit_subject" class="form-label">Subject</label>
                    <input id="edit_subject" type="text" x-model="editForm.subject" required maxlength="255" class="form-input">
                </div>
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <label for="edit_content" class="form-label mb-0">Content (HTML)</label>
                        <button type="button" class="text-xs font-medium text-[#111111] hover:underline dark:text-gray-100" @click="editPreview = !editPreview">
                            <span x-text="editPreview ? 'Hide preview' : 'Preview'"></span>
                        </button>
                    </div>
                    <textarea id="edit_content" x-model="editForm.content" rows="10" required class="form-textarea font-mono text-xs"></textarea>
                    <iframe x-show="editPreview" x-cloak :srcdoc="editForm.content" sandbox=""
                            class="mt-2 h-64 w-full rounded-md border border-[#EAEAEA] bg-white dark:border-gray-700"
                            title="Template preview"></iframe>
                </div>
                <label class="flex items-center gap-2 text-sm dark:text-gray-200">
                    <input type="checkbox" x-model="editForm.active" class="rounded border-[#EAEAEA] dark:border-gray-700">
                    Active
                </label>
                <div class="flex justify-end gap-2 pt-2">
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
        function emailTemplatesAdmin() {
            return {
                creating: false,
                createOpen: false,
                createPreview: false,
                createForm: { name: '', lang_code: '', subject: '', content: '', active: true },
                editing: false,
                editOpen: false,
                editPreview: false,
                editLoading: false,
                editForm: { id: null, name: '', lang_code: '', subject: '', content: '', active: true },

                openCreate() {
                    this.createForm = { name: '', lang_code: '', subject: '', content: '', active: true };
                    this.createPreview = false;
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/email-templates/create', {
                        name: this.createForm.name,
                        lang_code: this.createForm.lang_code,
                        subject: this.createForm.subject,
                        content: this.createForm.content,
                        active: this.createForm.active ? 1 : 0,
                    });
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the template.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editPreview = false;
                    this.editLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/email-templates/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Email template not found.' });
                        return;
                    }
                    this.editForm = {
                        id: json.template.id,
                        name: json.template.name,
                        lang_code: json.template.lang_code,
                        subject: json.template.subject,
                        content: json.template.content,
                        active: !!json.template.active,
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/email-templates/update', {
                        id: this.editForm.id,
                        name: this.editForm.name,
                        lang_code: this.editForm.lang_code,
                        subject: this.editForm.subject,
                        content: this.editForm.content,
                        active: this.editForm.active ? 1 : 0,
                    });
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the template.' });
                    }
                },

                confirmDelete(id, name, langCode) {
                    this.$dispatch('confirm', {
                        title: 'Delete email template',
                        body: 'This will permanently remove "' + name + ' / ' + langCode + '". This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/email-templates/delete', { id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the template.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
