<?php
/**
 * Admin scheduled-task (cron) management: search + handler/enabled filters +
 * paginate, create/edit via a shared Alpine modal, delete via the shared
 * confirm modal, enable/disable toggle (optimistic row flip), and a manual
 * "Run now" action (confirm modal → toast shows the outcome). Mirrors
 * `admin/translations/index.php`.
 *
 * @var list<object>                                          $items       searchAdmin() rows
 * @var int                                                   $total
 * @var int                                                   $page
 * @var int                                                   $limit
 * @var string                                                $sort
 * @var string                                                $order
 * @var array{keyword:string, handler:string, enabled:string} $filters
 * @var list<string>                                          $handlerKeys allowlisted handler registry keys
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'keyword' => $filters['keyword'] ?? '',
    'handler' => $filters['handler'] ?? '',
    'enabled' => $filters['enabled'] ?? '',
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

$statusBadgeClass = static function (?string $status): string {
    return match ($status) {
        'success' => 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300',
        'error'   => 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300',
        'running' => 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300',
        default   => 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300',
    };
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="scheduledTasksAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Scheduled Tasks</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage recurring maintenance jobs run by <code class="rounded bg-[#F7F6F3] px-1 py-0.5 text-xs dark:bg-gray-700">php spark schedule:run</code>.</p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary">
            + Add task
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="form-label">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Name, handler or description" class="form-input">
        </div>
        <div>
            <label for="handler" class="form-label">Handler</label>
            <select id="handler" name="handler" class="form-select">
                <option value="">All handlers</option>
                <?php foreach ($handlerKeys as $key) : ?>
                    <option value="<?= esc($key, 'attr') ?>" <?= ($filters['handler'] ?? '') === $key ? 'selected' : '' ?>><?= esc($key) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="enabled" class="form-label">Status</label>
            <select id="enabled" name="enabled" class="form-select">
                <option value="">All</option>
                <option value="1" <?= ($filters['enabled'] ?? '') === '1' ? 'selected' : '' ?>>Enabled</option>
                <option value="0" <?= ($filters['enabled'] ?? '') === '0' ? 'selected' : '' ?>>Disabled</option>
            </select>
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
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('handler'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Handler<?= esc($sortMark('handler')) ?></a></th>
                    <th class="px-4 py-3">Interval</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('enabled'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Enabled<?= esc($sortMark('enabled')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('last_run_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Last run<?= esc($sortMark('last_run_at')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('next_run_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Next run<?= esc($sortMark('next_run_at')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <?php
                    $rowState = esc(json_encode([
                        'id'         => (int) $item->id,
                        'name'       => (string) ($item->name ?? ''),
                        'enabled'    => (bool) $item->enabled,
                        'lastStatus' => $item->last_status,
                        'lastResult' => $item->last_result,
                    ]), 'attr');
                    ?>
                    <tr x-data="taskRow(<?= $rowState ?>)">
                        <td class="px-4 py-3 font-medium"><?= esc($item->name ?? '') ?></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                <?= esc($item->handler ?? '') ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-[#787774] dark:text-gray-400">every <?= esc((string) $item->interval_minutes) ?> min</td>
                        <td class="px-4 py-3">
                            <button type="button" role="switch" :aria-checked="enabled.toString()" :disabled="toggling"
                                    @click="toggle()"
                                    :class="enabled ? 'bg-[#346538]' : 'bg-gray-300 dark:bg-gray-600'"
                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-60">
                                <span :class="enabled ? 'translate-x-4' : 'translate-x-1'" class="inline-block h-3 w-3 transform rounded-full bg-white transition-transform"></span>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-xs text-[#787774] dark:text-gray-400">
                                <?php if (($item->last_run_at ?? null) !== null && $item->last_run_at !== '') : ?>
                                <span data-timeago="<?= esc((string) $item->last_run_at, 'attr') ?>"><?= esc((string) $item->last_run_at) ?></span>
                            <?php else : ?>&mdash;<?php endif ?>
                            </div>
                            <span x-show="lastStatus" x-cloak :class="statusBadgeClass()" class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide" x-text="lastStatus"></span>
                        </td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) ($item->next_run_at ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline disabled:opacity-60 dark:text-gray-100" :disabled="running" @click="confirmRun()">Run now</button>
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400" @click="confirmDelete()">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No scheduled tasks match your search.</td>
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
        'noun'       => 'task',
    ]) ?>

    <!-- Create modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-task-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="createOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="create-task-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Add scheduled task</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_name" class="form-label">Name</label>
                    <input id="create_name" type="text" x-model="createForm.name" required maxlength="255" class="form-input">
                </div>
                <div>
                    <label for="create_handler" class="form-label">Handler</label>
                    <select id="create_handler" x-model="createForm.handler" required class="form-select">
                        <option value="">Choose a handler…</option>
                        <?php foreach ($handlerKeys as $key) : ?>
                            <option value="<?= esc($key, 'attr') ?>"><?= esc($key) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="create_interval" class="form-label">Interval (minutes)</label>
                    <input id="create_interval" type="number" min="1" x-model="createForm.interval_minutes" required class="form-input">
                </div>
                <div>
                    <label for="create_params" class="form-label">Params <span class="font-normal text-[#787774] dark:text-gray-400">(optional)</span></label>
                    <textarea id="create_params" x-model="createForm.params" rows="3" placeholder='{"days": 30}' class="form-textarea font-mono text-sm"></textarea>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">JSON, e.g. {"days": 30}</p>
                </div>
                <div>
                    <label for="create_description" class="form-label">Description <span class="font-normal text-[#787774] dark:text-gray-400">(optional)</span></label>
                    <textarea id="create_description" x-model="createForm.description" rows="2" class="form-textarea"></textarea>
                </div>
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
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-task-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="editOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="edit-task-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Edit scheduled task</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>
            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div>
                    <label for="edit_name" class="form-label">Name</label>
                    <input id="edit_name" type="text" x-model="editForm.name" required maxlength="255" class="form-input">
                </div>
                <div>
                    <label for="edit_handler" class="form-label">Handler</label>
                    <select id="edit_handler" x-model="editForm.handler" required class="form-select">
                        <option value="">Choose a handler…</option>
                        <?php foreach ($handlerKeys as $key) : ?>
                            <option value="<?= esc($key, 'attr') ?>"><?= esc($key) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="edit_interval" class="form-label">Interval (minutes)</label>
                    <input id="edit_interval" type="number" min="1" x-model="editForm.interval_minutes" required class="form-input">
                </div>
                <div>
                    <label for="edit_params" class="form-label">Params <span class="font-normal text-[#787774] dark:text-gray-400">(optional)</span></label>
                    <textarea id="edit_params" x-model="editForm.params" rows="3" placeholder='{"days": 30}' class="form-textarea font-mono text-sm"></textarea>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">JSON, e.g. {"days": 30}</p>
                </div>
                <div>
                    <label for="edit_description" class="form-label">Description <span class="font-normal text-[#787774] dark:text-gray-400">(optional)</span></label>
                    <textarea id="edit_description" x-model="editForm.description" rows="2" class="form-textarea"></textarea>
                </div>
                <div class="rounded-md border border-[#EAEAEA] bg-[#F7F6F3] p-3 text-xs text-[#787774] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                    <p>Last run: <span x-text="editForm.last_run_at || '—'"></span> · Next run: <span x-text="editForm.next_run_at || '—'"></span></p>
                    <p class="mt-1">Last status: <span x-text="editForm.last_status || '—'"></span></p>
                    <p x-show="editForm.last_result" x-cloak class="mt-1 break-words" x-text="editForm.last_result"></p>
                </div>
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
        function scheduledTasksAdmin() {
            return {
                creating: false,
                createOpen: false,
                createForm: { name: '', handler: '', interval_minutes: 60, params: '', description: '' },
                editing: false,
                editOpen: false,
                editLoading: false,
                editForm: { id: null, name: '', handler: '', interval_minutes: 60, params: '', description: '', last_run_at: '', next_run_at: '', last_status: '', last_result: '' },

                openCreate() {
                    this.createForm = { name: '', handler: '', interval_minutes: 60, params: '', description: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/scheduled-tasks/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the task.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/scheduled-tasks/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Scheduled task not found.' });
                        return;
                    }
                    this.editForm = {
                        id: json.task.id,
                        name: json.task.name,
                        handler: json.task.handler,
                        interval_minutes: json.task.interval_minutes,
                        params: json.task.params || '',
                        description: json.task.description || '',
                        last_run_at: json.task.last_run_at,
                        next_run_at: json.task.next_run_at,
                        last_status: json.task.last_status,
                        last_result: json.task.last_result,
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/scheduled-tasks/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the task.' });
                    }
                },
            };
        }

        function taskRow(row) {
            return {
                id: row.id,
                name: row.name,
                enabled: row.enabled,
                lastStatus: row.lastStatus,
                lastResult: row.lastResult,
                toggling: false,
                running: false,

                statusBadgeClass() {
                    return {
                        success: 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300',
                        error: 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300',
                        running: 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300',
                    }[this.lastStatus] || 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300';
                },

                async toggle() {
                    this.toggling = true;
                    const next = this.enabled ? 0 : 1;
                    const json = await postForm('/admin/scheduled-tasks/toggle', { id: this.id, enabled: next });
                    this.toggling = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.enabled = json.enabled === 1;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the task.' });
                    }
                },

                confirmRun() {
                    this.$dispatch('confirm', {
                        title: 'Run task now',
                        body: 'This runs "' + this.name + '" immediately, outside its normal schedule.',
                        variant: 'default',
                        confirmLabel: 'Run now',
                        onConfirm: async () => {
                            this.running = true;
                            const json = await postForm('/admin/scheduled-tasks/run', { id: this.id });
                            this.running = false;
                            if (!json) return;
                            if (json.status === 'success') {
                                this.lastStatus = json.last_status;
                                this.lastResult = json.last_result;
                                this.$dispatch('toast', {
                                    type: json.last_status === 'error' ? 'error' : 'success',
                                    text: json.last_status === 'error' ? ('Run failed: ' + json.last_result) : json.message,
                                });
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not run the task.' });
                            }
                        },
                    });
                },

                confirmDelete() {
                    this.$dispatch('confirm', {
                        title: 'Delete scheduled task',
                        body: 'This will permanently remove "' + this.name + '". This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/scheduled-tasks/delete', { id: this.id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the task.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
