<?php
/**
 * Leave / late-arrival approval queue. Pending first, with the cost of the
 * request and the employee's remaining balance in view before the decision.
 *
 * Hallmark · component: filter-table + decision-actions · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 *
 * @var list<object>                                                      $items
 * @var array<int, float>                                                 $requestDays  workday-weighted cost per request id
 * @var array<int, object>                                                $balances     leave balance per employee id
 * @var int                                                               $total
 * @var int                                                               $pending
 * @var int                                                               $page
 * @var int                                                               $limit
 * @var array{status:string, type:string, leave_kind:string, user_id:int} $filters
 * @var list<object>                                                      $employees
 * @var string                                                            $today
 * @var string                                                            $lockedThrough
 */

use App\Libraries\LeaveBalance;
use App\Models\LeaveRequestModel;

$this->extend('layouts/app');

helper('attendance');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'status'     => $filters['status'],
    'type'       => $filters['type'],
    'leave_kind' => $filters['leave_kind'],
    'user_id'    => $filters['user_id'] ?: '',
    'limit'      => $limit,
];

$pageUrl = static fn (int $target): string => '?' . http_build_query(
    array_filter($queryBase + ['page' => $target], static fn ($v): bool => $v !== '' && $v !== null),
);

$dateRange = static function (object $request): string {
    $end = (string) ($request->end_date ?? $request->start_date);

    return $end === (string) $request->start_date ? (string) $request->start_date : $request->start_date . ' → ' . $end;
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="requestQueue()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Approve requests</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Leave and late-arrival requests. Approving one changes how that day is reported on the attendance sheet.
            </p>
        </div>
        <?php if ($pending > 0) : ?>
            <a href="?status=pending" class="inline-flex items-center gap-2 rounded-full border border-[#E5C07B] px-3 py-1.5 text-sm font-medium text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200">
                <?= esc((string) $pending) ?> awaiting a decision
            </a>
        <?php endif ?>
    </div>

    <?php if ($lockedThrough !== '') : ?>
        <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
            Payroll is closed through <strong><?= esc($lockedThrough) ?></strong> — requests inside that period cannot be approved.
        </p>
    <?php endif ?>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-select">
                <option value="">All</option>
                <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status) : ?>
                    <option value="<?= esc($status, 'attr') ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= esc(ucfirst($status)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="type" class="form-label">Type</label>
            <select id="type" name="type" class="form-select">
                <option value="">All</option>
                <option value="leave" <?= $filters['type'] === 'leave' ? 'selected' : '' ?>>Leave</option>
                <option value="late" <?= $filters['type'] === 'late' ? 'selected' : '' ?>>Late arrival</option>
            </select>
        </div>
        <div>
            <label for="leave_kind" class="form-label">Leave type</label>
            <select id="leave_kind" name="leave_kind" class="form-select">
                <option value="">All</option>
                <?php foreach (LeaveRequestModel::KINDS as $kind) : ?>
                    <option value="<?= esc($kind, 'attr') ?>" <?= $filters['leave_kind'] === $kind ? 'selected' : '' ?>><?= esc(ucfirst($kind)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="min-w-[180px]">
            <label for="user_id" class="form-label">Employee</label>
            <select id="user_id" name="user_id" class="form-select">
                <option value="">All employees</option>
                <?php foreach ($employees as $employee) : ?>
                    <option value="<?= esc((string) $employee->id, 'attr') ?>" <?= $filters['user_id'] === (int) $employee->id ? 'selected' : '' ?>>
                        <?= esc((string) ($employee->name ?? $employee->email)) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary">Filter</button>
        <a href="/admin/requests" class="btn-secondary">Clear</a>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Employee</th>
                    <th scope="col" class="px-4 py-3">Dates</th>
                    <th scope="col" class="px-4 py-3">Type</th>
                    <th scope="col" class="px-4 py-3 text-right">Days</th>
                    <th scope="col" class="px-4 py-3">Balance</th>
                    <th scope="col" class="px-4 py-3">Reason</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <?php
                    $cost      = $requestDays[(int) $item->id] ?? 0.0;
                    $balance   = $balances[(int) $item->user_id] ?? null;
                    $overdrawn = $item->leave_kind === 'annual' && $balance !== null && $cost > $balance->remaining;
                    ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc((string) ($item->user_name ?? $item->user_email ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= esc(leave_request_dates($item)) ?>
                            <?php if ((string) $item->start_date < $today && $item->status === LeaveRequestModel::STATUS_PENDING) : ?>
                                <span class="ml-1 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300">backdated</span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc(leave_request_label($item)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                            <?= $item->type === LeaveRequestModel::TYPE_LATE ? '—' : esc(rtrim(rtrim(number_format($cost, 1, '.', ''), '0'), '.')) ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums <?= $overdrawn ? 'font-medium text-[#9F2F2D] dark:text-red-300' : 'text-[#787774] dark:text-gray-400' ?>">
                            <?= $balance === null ? '—' : esc(LeaveBalance::formatDays($balance->remaining)) ?>
                            <?php if ($overdrawn) : ?>
                                <span class="block text-xs">exceeds balance</span>
                            <?php endif ?>
                        </td>
                        <td class="max-w-xs truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) $item->reason, 'attr') ?>"><?= esc((string) $item->reason) ?></td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <?= view('requests/_status_pill', ['status' => $item->status]) ?>
                            <?php if (($item->reviewer_name ?? null) !== null) : ?>
                                <span class="ml-1 text-xs text-[#787774] dark:text-gray-400">by <?= esc((string) $item->reviewer_name) ?></span>
                            <?php endif ?>
                            <?php if (($item->review_note ?? null) !== null) : ?>
                                <span class="block text-xs text-[#787774] dark:text-gray-400"><?= esc((string) $item->review_note) ?></span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <?php if ($item->status === LeaveRequestModel::STATUS_PENDING) : ?>
                                <button type="button" class="mr-3 text-sm font-medium text-[#2F6B39] hover:underline dark:text-green-300"
                                        @click="confirmApprove(<?= (int) $item->id ?>, <?= esc(json_encode(leave_request_label($item) . ' · ' . leave_request_dates($item)), 'attr') ?>, <?= $overdrawn ? 'true' : 'false' ?>)">Approve</button>
                                <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                        @click="openReject(<?= (int) $item->id ?>, <?= esc(json_encode(leave_request_dates($item)), 'attr') ?>)">Reject</button>
                            <?php else : ?>
                                <span class="text-xs text-[#787774] dark:text-gray-400">—</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No requests match these filters.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> request<?= $total === 1 ? '' : 's' ?></p>
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

    <!-- Reject modal: a rejection deserves a reason. -->
    <div x-show="rejectOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="rejectOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="rejectOpen = false"></div>
        <div x-show="rejectOpen" x-transition class="modal-panel relative max-w-md"
             x-trap.noscroll="rejectOpen" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="rejectOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="reject-modal-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Reject request</h2>
            <p class="mt-2 text-sm text-[#787774] dark:text-gray-400">
                For <span x-text="rejectLabel"></span>. The employee is notified, so a short reason helps.
            </p>

            <form @submit.prevent="submitReject" class="mt-4 space-y-4">
                <div>
                    <label for="review_note" class="form-label">Reason (optional)</label>
                    <textarea id="review_note" x-model="reviewNote" rows="3" maxlength="255" class="form-textarea"></textarea>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="rejectOpen = false">Cancel</button>
                    <button type="submit" :disabled="saving" class="btn-primary">
                        <span x-show="!saving">Reject</span>
                        <span x-show="saving" x-cloak>Rejecting…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function requestQueue() {
            return {
                saving: false,
                rejectOpen: false,
                rejectId: null,
                rejectLabel: '',
                reviewNote: '',

                confirmApprove(id, label, overdrawn) {
                    const body = overdrawn
                        ? 'This request exceeds the employee\'s remaining annual-leave balance. Approving it anyway is allowed — it will simply overdraw. Approve ' + label + '?'
                        : 'Approve ' + label + '? The attendance sheet will report those days as leave.';

                    this.$dispatch('confirm', {
                        title: 'Approve request',
                        body,
                        confirmLabel: 'Approve',
                        onConfirm: () => this.send('/admin/requests/approve/' + id, {}),
                    });
                },

                openReject(id, label) {
                    this.rejectId = id;
                    this.rejectLabel = label;
                    this.reviewNote = '';
                    this.rejectOpen = true;
                },

                async submitReject() {
                    await this.send('/admin/requests/reject/' + this.rejectId, { review_note: this.reviewNote });
                },

                async send(url, fields) {
                    this.saving = true;
                    const json = await postForm(url, fields);
                    this.saving = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.rejectOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not record the decision.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
