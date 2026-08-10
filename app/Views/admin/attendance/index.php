<?php
/**
 * Company attendance review + correction: filter form, paginated table, and a
 * shared Alpine modal for correcting or manually adding a day. Mirrors
 * `admin/settings/index.php`'s structure so the two screens stay recognisable.
 *
 * Hallmark · component: filter-table + correction-modal · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 * (no fabricated values — an open day renders an em dash, never an assumed time)
 *
 * Times are edited in Vietnam local `HH:MM`; the controller converts to/from the
 * stored UTC values.
 *
 * @var list<object>                                                                  $items         searchAdmin() rows (+ user_name/editor_name)
 * @var int                                                                           $total
 * @var int                                                                           $page
 * @var int                                                                           $limit
 * @var string                                                                        $sort
 * @var string                                                                        $order
 * @var array{user_id:int, date_from:string, date_to:string, missing_checkout:string} $filters
 * @var list<object>                                                                  $employees     active roster for the filter + create selects
 * @var bool                                                                          $ipEnforced
 * @var string                                                                        $lockedThrough YYYY-MM, or '' when nothing is locked
 * @var string                                                                        $requestIp     this request's address as the server sees it
 * @var array<int, object>                                                            $evaluations   AttendanceCalendar results keyed by row id
 */
$this->extend('layouts/app');

helper('attendance');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'user_id'          => $filters['user_id'] ?: '',
    'date_from'        => $filters['date_from'],
    'date_to'          => $filters['date_to'],
    'missing_checkout' => $filters['missing_checkout'],
    'sort'             => $sort,
    'order'            => $order,
    'limit'            => $limit,
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
<div class="mx-auto max-w-7xl" x-data="attendanceAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Attendance</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Company check-in / check-out records. Times in Vietnam time (UTC+7).
            </p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary whitespace-nowrap">+ Add record</button>
    </div>

    <?php if (! $ipEnforced) : ?>
        <div class="mt-6 flex items-start gap-3 rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-4 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
            </svg>
            <p>
                Office-network enforcement is <strong>off</strong> — check-ins are accepted from any network and the
                recorded IP is informational only. Set <code class="rounded bg-black/5 px-1 dark:bg-white/10">attendance_ip_enforce</code>
                to <code class="rounded bg-black/5 px-1 dark:bg-white/10">1</code> in
                <a href="/admin/settings" class="font-medium underline">Settings</a> to enforce it.
            </p>
        </div>
    <?php endif ?>

    <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
        Your address as the server sees it: <code class="rounded bg-black/5 px-1 dark:bg-white/10"><?= esc($requestIp) ?></code>
        — use this to fill the office allowlist. If every employee shows the same private address, the
        real client IP is not reaching the app.
    </p>

    <?php if ($lockedThrough !== '') : ?>
        <p class="mt-2 text-sm text-[#787774] dark:text-gray-400">
            Payroll is closed through <strong><?= esc($lockedThrough) ?></strong> — records in that period and earlier cannot be changed.
        </p>
    <?php endif ?>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[200px] flex-1">
            <label for="user_id" class="form-label">Employee</label>
            <select id="user_id" name="user_id" class="form-select">
                <option value="">All employees</option>
                <?php foreach ($employees as $employee) : ?>
                    <option value="<?= esc((string) $employee->id, 'attr') ?>" <?= (int) $filters['user_id'] === (int) $employee->id ? 'selected' : '' ?>>
                        <?= esc((string) ($employee->name ?? $employee->email)) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="date_from" class="form-label">From</label>
            <input type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'], 'attr') ?>" class="form-input">
        </div>
        <div>
            <label for="date_to" class="form-label">To</label>
            <input type="date" id="date_to" name="date_to" value="<?= esc($filters['date_to'], 'attr') ?>" class="form-input">
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm">
            <input type="checkbox" name="missing_checkout" value="1" <?= $filters['missing_checkout'] === '1' ? 'checked' : '' ?>
                   class="h-4 w-4 rounded border-[#EAEAEA] dark:border-gray-600">
            Missing check-out only
        </label>
        <input type="hidden" name="limit" value="<?= esc((string) $limit, 'attr') ?>">
        <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
        <input type="hidden" name="order" value="<?= esc($order, 'attr') ?>">
        <button type="submit" class="btn-secondary">Filter</button>
        <button type="button" @click="location.href='?limit=<?= esc((string) $limit, 'js') ?>'" class="btn-secondary">Clear</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('user_name'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Employee<?= esc($sortMark('user_name')) ?></a></th>
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('work_date'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Date<?= esc($sortMark('work_date')) ?></a></th>
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('checkin_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">In<?= esc($sortMark('checkin_at')) ?></a></th>
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('checkout_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Out<?= esc($sortMark('checkout_at')) ?></a></th>
                    <th scope="col" class="px-4 py-3">Worked</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">IP</th>
                    <th scope="col" class="px-4 py-3">Note</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc((string) ($item->user_name ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc($item->work_date) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_local_time($item->checkin_at)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= esc(attendance_local_time($item->checkout_at)) ?>
                            <?php if ((int) $item->missing_checkout === 1) : ?>
                                <span class="ml-1 rounded-full border border-[#E5C07B] px-2 py-0.5 text-xs font-medium text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200">missing</span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_format_duration($evaluations[(int) $item->id]->worked_seconds ?? attendance_worked_seconds($item->checkin_at, $item->checkout_at))) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= view('attendance/_status_pill', ['day' => $evaluations[(int) $item->id] ?? null]) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) ($item->checkin_ip ?? '—')) ?></td>
                        <td class="max-w-xs truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->note ?? ''), 'attr') ?>">
                            <?= esc((string) ($item->note ?? '—')) ?>
                            <?php if ($item->edited_at !== null) : ?>
                                <span class="ml-1 whitespace-nowrap rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                    by <?= esc((string) ($item->editor_name ?? 'manager')) ?>
                                </span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="text-sm font-medium text-[#111111] hover:underline dark:text-gray-100"
                                    @click="openEdit(<?= (int) $item->id ?>)">Correct</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No attendance records match these filters.</td>
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
        'noun'       => 'record',
    ]) ?>

    <!-- Correction modal -->
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition class="modal-panel relative max-w-md"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-attendance-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="editOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="edit-attendance-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Correct attendance</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>

            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <p class="text-sm text-[#787774] dark:text-gray-400">
                    Work date: <span class="font-medium text-[#111111] dark:text-gray-100" x-text="editForm.work_date"></span>
                </p>

                <div x-show="editLocked" x-cloak role="alert"
                     class="rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-3 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                    This month is closed for payroll and cannot be changed.
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="edit_checkin" class="form-label">Check-in (HH:MM)</label>
                        <input id="edit_checkin" type="time" x-model="editForm.checkin_time" class="form-input" :disabled="editLocked">
                    </div>
                    <div>
                        <label for="edit_checkout" class="form-label">Check-out (HH:MM)</label>
                        <input id="edit_checkout" type="time" x-model="editForm.checkout_time" class="form-input" :disabled="editLocked">
                    </div>
                </div>
                <p class="text-xs text-[#787774] dark:text-gray-400">Leave a field empty to clear it. The employee's original times are kept for audit.</p>

                <div>
                    <label for="edit_note" class="form-label">Reason for the correction</label>
                    <textarea id="edit_note" x-model="editForm.note" rows="2" maxlength="255" class="form-textarea" :disabled="editLocked"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="editOpen = false">Cancel</button>
                    <button type="submit" :disabled="editing || editLocked" class="btn-primary">
                        <span x-show="!editing">Save</span>
                        <span x-show="editing" x-cloak>Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manual entry modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition class="modal-panel relative max-w-md"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-attendance-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="createOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="create-attendance-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Add attendance record</h2>

            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_user" class="form-label">Employee</label>
                    <select id="create_user" x-model="createForm.user_id" required class="form-select">
                        <option value="">Select an employee</option>
                        <?php foreach ($employees as $employee) : ?>
                            <option value="<?= esc((string) $employee->id, 'attr') ?>"><?= esc((string) ($employee->name ?? $employee->email)) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="create_date" class="form-label">Work date</label>
                    <input id="create_date" type="date" x-model="createForm.work_date" required class="form-input">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="create_checkin" class="form-label">Check-in (HH:MM)</label>
                        <input id="create_checkin" type="time" x-model="createForm.checkin_time" class="form-input">
                    </div>
                    <div>
                        <label for="create_checkout" class="form-label">Check-out (HH:MM)</label>
                        <input id="create_checkout" type="time" x-model="createForm.checkout_time" class="form-input">
                    </div>
                </div>
                <div>
                    <label for="create_note" class="form-label">Reason</label>
                    <textarea id="create_note" x-model="createForm.note" rows="2" maxlength="255" class="form-textarea"></textarea>
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

    <script>
        function attendanceAdmin() {
            return {
                editing: false,
                editOpen: false,
                editLoading: false,
                editLocked: false,
                editForm: { id: null, work_date: '', checkin_time: '', checkout_time: '', note: '' },

                creating: false,
                createOpen: false,
                createForm: { user_id: '', work_date: '', checkin_time: '', checkout_time: '', note: '' },

                openCreate() {
                    this.createForm = { user_id: '', work_date: '', checkin_time: '', checkout_time: '', note: '' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/attendance/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the record.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editLoading = true;
                    let json;
                    try {
                        json = await fetchLatest('attendance.edit', '/admin/attendance/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }

                    // A newer open superseded this response: it is not on screen any more.
                    if (json === null) return;
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Record not found.' });
                        return;
                    }
                    this.editLocked = !!json.day.locked;
                    this.editForm = {
                        id: json.day.id,
                        work_date: json.day.work_date,
                        checkin_time: json.day.checkin_at,
                        checkout_time: json.day.checkout_at,
                        note: json.day.note ?? '',
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/attendance/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the record.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
