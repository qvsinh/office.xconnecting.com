<?php
/**
 * Employee leave / late-arrival requests: remaining balance, a submit modal, and
 * the employee's own history.
 *
 * Hallmark · component: balance-card + request-modal + history-table · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 * (the modal shows the workdays a range will actually cost, live, before submit —
 * no guessing, and no invented numbers)
 *
 * @var list<object>      $items         the employee's requests, newest first
 * @var array<int, float> $requestDays   workday-weighted cost per request id
 * @var int               $total
 * @var int               $page
 * @var int               $perPage
 * @var object            $balance       {year, quota, used, remaining}
 * @var object|null       $schedule      the employee's work schedule
 * @var string            $today
 * @var bool              $impersonating
 */

use App\Libraries\LeaveBalance;
use App\Models\LeaveRequestModel;

$this->extend('layouts/app');

helper('attendance');

$totalPages = max(1, (int) ceil($total / max($perPage, 1)));

$startTime = $schedule === null ? '08:30' : substr((string) $schedule->start_time, 0, 5);
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="myRequests()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight"><?= esc(lang('requests.title')) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Ask for a day off or permission to arrive late. A manager approves before it counts.
            </p>
        </div>
        <?php if ($impersonating) : ?>
            <button type="button" class="btn-primary whitespace-nowrap" disabled
                    title="Stop acting as another user to submit your own request.">Unavailable here</button>
        <?php else : ?>
            <button type="button" @click="openCreate()" class="btn-primary whitespace-nowrap">+ New request</button>
        <?php endif ?>
    </div>

    <dl class="mt-6 grid grid-cols-3 gap-4">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc(lang('requests.annual_left')) ?></dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums"><?= esc(LeaveBalance::formatDays($balance->remaining)) ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Used in <?= esc((string) $balance->year) ?></dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums"><?= esc(LeaveBalance::formatDays($balance->used)) ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Yearly quota</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums"><?= esc(LeaveBalance::formatDays($balance->quota)) ?></dd>
        </div>
    </dl>

    <p class="mt-3 text-xs text-[#787774] dark:text-gray-400">
        Only <strong>annual</strong> leave draws down the balance, and only scheduled working days count —
        a Friday-to-Monday break costs two days, and public holidays cost none.
    </p>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3"><?= esc(lang('common.dates')) ?></th>
                    <th scope="col" class="px-4 py-3"><?= esc(lang('requests.type')) ?></th>
                    <th scope="col" class="hidden px-4 py-3 text-right sm:table-cell"><?= esc(lang('common.days')) ?></th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell"><?= esc(lang('common.reason')) ?></th>
                    <th scope="col" class="px-4 py-3"><?= esc(lang('common.status')) ?></th>
                    <th scope="col" class="px-4 py-3 text-right"><?= esc(lang('common.actions')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums">
                            <?= esc(leave_request_dates($item)) ?>
                            <?php if ((string) $item->start_date < $today && $item->status === LeaveRequestModel::STATUS_PENDING) : ?>
                                <span class="ml-1 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300"><?= esc(lang('requests.backdated')) ?></span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc(leave_request_label($item)) ?></td>
                        <td class="hidden whitespace-nowrap px-4 py-3 text-right tabular-nums sm:table-cell">
                            <?= $item->type === LeaveRequestModel::TYPE_LATE ? '—' : esc(rtrim(rtrim(number_format($requestDays[(int) $item->id] ?? 0, 1, '.', ''), '0'), '.')) ?>
                        </td>
                        <td class="hidden max-w-xs truncate px-4 py-3 text-[#787774] sm:table-cell dark:text-gray-400" title="<?= esc((string) $item->reason, 'attr') ?>"><?= esc((string) $item->reason) ?></td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <?= view('requests/_status_pill', ['status' => $item->status]) ?>
                            <?php if (($item->review_note ?? null) !== null) : ?>
                                <span class="ml-1 text-xs text-[#787774] dark:text-gray-400" title="<?= esc((string) $item->review_note, 'attr') ?>">note</span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <?php if ($item->status === LeaveRequestModel::STATUS_PENDING && ! $impersonating) : ?>
                                <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                        @click="confirmCancel(<?= (int) $item->id ?>, <?= esc(json_encode(leave_request_dates($item)), 'attr') ?>)"><?= esc(lang('requests.withdraw')) ?></button>
                            <?php else : ?>
                                <span class="text-xs text-[#787774] dark:text-gray-400">—</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            No requests yet. Use "New request" when you need a day off or to come in late.
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1) : ?>
        <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
            <p><?= esc((string) $total) ?> request<?= $total === 1 ? '' : 's' ?></p>
            <div class="flex items-center gap-2">
                <?php if ($page > 1) : ?>
                    <a href="?page=<?= esc((string) ($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Prev</a>
                <?php endif ?>
                <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
                <?php if ($page < $totalPages) : ?>
                    <a href="?page=<?= esc((string) ($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Next</a>
                <?php endif ?>
            </div>
        </div>
    <?php endif ?>

    <!-- New request modal -->
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="open = false">
        <div class="fixed inset-0 bg-black/40" @click="open = false"></div>
        <div x-show="open" x-transition class="modal-panel relative max-w-lg"
             x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="request-modal-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="open = false" aria-label="<?= esc(lang('common.close'), 'attr') ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="request-modal-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90"><?= esc(lang('requests.new_request')) ?></h2>

            <form @submit.prevent="submit" class="mt-4 space-y-4">
                <fieldset>
                    <legend class="form-label">What are you asking for?</legend>
                    <div class="mt-1 flex gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" value="leave" x-model="form.type" class="h-4 w-4 border-[#EAEAEA] dark:border-gray-600">
                            A day off
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" value="late" x-model="form.type" class="h-4 w-4 border-[#EAEAEA] dark:border-gray-600">
                            To arrive late
                        </label>
                    </div>
                </fieldset>

                <template x-if="form.type === 'leave'">
                    <div class="space-y-4">
                        <div>
                            <label for="leave_kind" class="form-label"><?= esc(lang('requests.leave_type')) ?></label>
                            <select id="leave_kind" x-model="form.leave_kind" class="form-select">
                                <option value="annual"><?= esc(lang('requests.annual')) ?></option>
                                <option value="unpaid"><?= esc(lang('requests.unpaid')) ?></option>
                                <option value="sick"><?= esc(lang('requests.sick')) ?></option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="start_date" class="form-label"><?= esc(lang('common.from')) ?></label>
                                <input id="start_date" type="date" x-model="form.start_date" required class="form-input" @change="refreshDays()">
                            </div>
                            <div>
                                <label for="end_date" class="form-label"><?= esc(lang('requests.to_optional')) ?></label>
                                <input id="end_date" type="date" x-model="form.end_date" class="form-input" @change="refreshDays()">
                            </div>
                        </div>
                        <div x-show="!form.end_date || form.end_date === form.start_date">
                            <label for="day_portion" class="form-label"><?= esc(lang('requests.day_portion')) ?></label>
                            <select id="day_portion" x-model="form.day_portion" class="form-select">
                                <option value="full"><?= esc(lang('requests.whole_day')) ?></option>
                                <option value="morning"><?= esc(lang('requests.morning_only')) ?></option>
                                <option value="afternoon"><?= esc(lang('requests.afternoon_only')) ?></option>
                            </select>
                        </div>
                        <p class="text-sm text-[#787774] dark:text-gray-400">
                            Working days counted:
                            <strong class="tabular-nums text-[#111111] dark:text-gray-100" x-text="countedLabel()">—</strong>
                        </p>
                    </div>
                </template>

                <template x-if="form.type === 'late'">
                    <div class="space-y-4">
                        <div>
                            <label for="late_date" class="form-label"><?= esc(lang('common.date')) ?></label>
                            <input id="late_date" type="date" x-model="form.start_date" required class="form-input">
                        </div>
                        <div>
                            <label for="expected_time" class="form-label"><?= esc(lang('requests.expected_arrival')) ?></label>
                            <input id="expected_time" type="time" x-model="form.expected_time" class="form-input">
                            <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">
                                Must be later than your normal start (<?= esc($startTime) ?>).
                            </p>
                        </div>
                    </div>
                </template>

                <div>
                    <label for="reason" class="form-label"><?= esc(lang('common.reason')) ?></label>
                    <textarea id="reason" x-model="form.reason" rows="3" required maxlength="1000" class="form-textarea"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="open = false"><?= esc(lang('common.cancel')) ?></button>
                    <button type="submit" :disabled="saving" class="btn-primary">
                        <span x-show="!saving"><?= esc(lang('common.submit')) ?></span>
                        <span x-show="saving" x-cloak><?= esc(lang('common.submitting')) ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function myRequests() {
            return {
                open: false,
                saving: false,
                counted: null,
                form: {},

                init() {
                    this.form = this.blank();

                    // A half day only exists on a single date (the server refuses
                    // the combination). The portion select is hidden once a range
                    // is picked, so without this the user would be stuck with an
                    // invalid value they cannot see or change.
                    this.$watch('form.end_date', (end) => {
                        if (end && end !== this.form.start_date) this.form.day_portion = 'full';
                    });

                    // Switching back to "a day off" should re-count immediately
                    // rather than waiting for the next date change.
                    this.$watch('form.type', () => {
                        this.counted = null;
                        this.refreshDays();
                    });
                },

                blank() {
                    return {
                        type: 'leave', leave_kind: 'annual',
                        start_date: <?= json_encode($today, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, end_date: '',
                        day_portion: 'full', expected_time: '', reason: '',
                    };
                },

                openCreate() {
                    this.form = this.blank();
                    this.counted = null;
                    this.open = true;
                    this.refreshDays();
                },

                countedLabel() {
                    if (this.counted === null) return '—';
                    if (this.form.day_portion !== 'full' && (!this.form.end_date || this.form.end_date === this.form.start_date)) return '0.5';
                    return String(this.counted);
                },

                // The server owns the workday/holiday calendar, so ask it rather
                // than re-implementing the rule in the browser.
                async refreshDays() {
                    if (this.form.type !== 'leave' || !this.form.start_date) return;
                    const params = new URLSearchParams({ start_date: this.form.start_date, end_date: this.form.end_date || '' });
                    try {
                        const res = await fetch('/requests/preview?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        const json = await res.json();
                        this.counted = json.status === 'success' ? json.workdays : null;
                    } catch (e) {
                        this.counted = null;
                    }
                },

                async submit() {
                    this.saving = true;
                    const json = await postForm('/requests/create', this.form);
                    this.saving = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.open = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || <?= json_encode(lang('requests.submit_failed'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> });
                    }
                },

                confirmCancel(id, dates) {
                    this.$dispatch('confirm', {
                        title: 'Withdraw request',
                        body: 'This withdraws your request for ' + dates + '. Your manager will no longer see it in the queue.',
                        variant: 'danger',
                        confirmLabel: 'Withdraw',
                        onConfirm: async () => {
                            const json = await postForm('/requests/cancel/' + id, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || <?= json_encode(lang('requests.withdraw_failed'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
