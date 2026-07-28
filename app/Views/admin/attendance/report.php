<?php
/**
 * Monthly attendance report: one summary row per employee, company totals, CSV
 * export, and the payroll close action.
 *
 * Hallmark · component: filter-table + totals-footer + lock-action · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 *
 * @var string       $month          YYYY-MM
 * @var list<object> $rows           {user, summary}
 * @var object       $totals         company totals across $rows
 * @var list<object> $groups
 * @var list<object> $employees
 * @var int          $groupId
 * @var int          $userId
 * @var string       $lockedThrough  YYYY-MM or ''
 * @var bool         $monthLocked    is $month already closed
 * @var bool         $exceptionsOnly show only employees with something to look at
 */
$this->extend('layouts/app');

helper('attendance');

$exportQuery = http_build_query(array_filter([
    'month'    => $month,
    'group_id' => $groupId ?: null,
    'user_id'  => $userId ?: null,
]));

$hasException = static fn (object $row): bool => $row->summary->late > 0
    || $row->summary->absent > 0
    || $row->summary->missing_checkout > 0
    || $row->summary->early_leave > 0;

$visible = $exceptionsOnly ? array_values(array_filter($rows, $hasException)) : $rows;
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="attendanceReport()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Monthly report</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Attendance figures for <?= esc($month) ?>. Hours are net of the lunch break.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="/admin/attendance/report/export?<?= esc($exportQuery, 'attr') ?>" class="btn-secondary whitespace-nowrap">Export CSV</a>
            <a href="/admin/attendance/report/export?detail=1&amp;<?= esc($exportQuery, 'attr') ?>" class="btn-secondary whitespace-nowrap">Export day-by-day</a>
            <?php if ($monthLocked) : ?>
                <button type="button" class="btn-secondary whitespace-nowrap" @click="confirmUnlock()">Re-open month</button>
            <?php else : ?>
                <button type="button" class="btn-primary whitespace-nowrap" @click="confirmLock()">Close month</button>
            <?php endif ?>
        </div>
    </div>

    <?php if ($lockedThrough !== '') : ?>
        <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
            Payroll is closed through <strong><?= esc($lockedThrough) ?></strong>.
            <?= $monthLocked ? 'This month is closed — corrections are refused.' : 'This month is still open.' ?>
        </p>
    <?php endif ?>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="month" class="form-label">Month</label>
            <input type="month" id="month" name="month" value="<?= esc($month, 'attr') ?>" class="form-input">
        </div>
        <div class="min-w-[180px]">
            <label for="group_id" class="form-label">Group</label>
            <select id="group_id" name="group_id" class="form-select">
                <option value="">All groups</option>
                <?php foreach ($groups as $group) : ?>
                    <option value="<?= esc((string) $group->id, 'attr') ?>" <?= $groupId === (int) $group->id ? 'selected' : '' ?>><?= esc($group->name) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="min-w-[180px]">
            <label for="user_id" class="form-label">Employee</label>
            <select id="user_id" name="user_id" class="form-select">
                <option value="">All employees</option>
                <?php foreach ($employees as $employee) : ?>
                    <option value="<?= esc((string) $employee->id, 'attr') ?>" <?= $userId === (int) $employee->id ? 'selected' : '' ?>>
                        <?= esc((string) ($employee->name ?? $employee->email)) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm">
            <input type="checkbox" name="exceptions" value="1" <?= $exceptionsOnly ? 'checked' : '' ?>
                   class="h-4 w-4 rounded border-[#EAEAEA] dark:border-gray-600">
            Only employees with exceptions
        </label>
        <button type="submit" class="btn-secondary">Apply</button>
        <a href="/admin/attendance/report" class="btn-secondary">Clear</a>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Employee</th>
                    <th scope="col" class="px-4 py-3 text-right">Workdays</th>
                    <th scope="col" class="px-4 py-3 text-right">Present</th>
                    <th scope="col" class="px-4 py-3 text-right">Late</th>
                    <th scope="col" class="px-4 py-3 text-right">Late min</th>
                    <th scope="col" class="px-4 py-3 text-right">Early leave</th>
                    <th scope="col" class="px-4 py-3 text-right">Absent</th>
                    <th scope="col" class="px-4 py-3 text-right">Leave</th>
                    <th scope="col" class="px-4 py-3 text-right">No check-out</th>
                    <th scope="col" class="px-4 py-3 text-right">Worked</th>
                    <th scope="col" class="px-4 py-3 text-right">Expected</th>
                    <th scope="col" class="px-4 py-3 text-right">Overtime</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($visible as $row) : ?>
                    <?php $s = $row->summary; ?>
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <a href="/admin/attendance/report/user/<?= (int) $row->user->id ?>?month=<?= esc($month, 'attr') ?>"
                               class="hover:underline"><?= esc((string) ($row->user->name ?? $row->user->email)) ?></a>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $s->workdays) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $s->present) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums <?= $s->late > 0 ? 'font-medium text-[#7A5B14] dark:text-amber-200' : '' ?>"><?= esc((string) $s->late) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $s->late_minutes) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $s->early_leave) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums <?= $s->absent > 0 ? 'font-medium text-[#9F2F2D] dark:text-red-300' : '' ?>"><?= esc((string) $s->absent) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $s->on_leave) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums <?= $s->missing_checkout > 0 ? 'font-medium text-[#7A5B14] dark:text-amber-200' : '' ?>"><?= esc((string) $s->missing_checkout) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc(attendance_format_duration($s->worked_seconds)) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums text-[#787774] dark:text-gray-400"><?= esc(attendance_format_duration($s->expected_seconds)) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc(attendance_format_duration($s->overtime_seconds)) ?></td>
                    </tr>
                <?php endforeach ?>
                <?php if ($visible === []) : ?>
                    <tr>
                        <td colspan="12" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            <?= $rows === [] ? 'No employees match these filters.' : 'No exceptions this month — everyone was on time and present.' ?>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
            <?php if ($visible !== []) : ?>
                <tfoot class="border-t-2 border-[#EAEAEA] dark:border-gray-600">
                    <tr class="font-medium">
                        <td class="px-4 py-3">Company total (<?= esc((string) count($visible)) ?>)</td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->workdays) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->present) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->late) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->late_minutes) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->early_leave) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->absent) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->on_leave) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc((string) $totals->missing_checkout) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc(attendance_format_duration($totals->worked_seconds)) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc(attendance_format_duration($totals->expected_seconds)) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= esc(attendance_format_duration($totals->overtime_seconds)) ?></td>
                    </tr>
                </tfoot>
            <?php endif ?>
        </table>
    </div>

    <?php if ($exceptionsOnly && $rows !== [] && count($visible) < count($rows)) : ?>
        <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
            Showing <?= esc((string) count($visible)) ?> of <?= esc((string) count($rows)) ?> employees — the totals row covers only those shown.
        </p>
    <?php endif ?>

    <script>
        function attendanceReport() {
            return {
                month: <?= json_encode($month, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,

                confirmLock() {
                    this.$dispatch('confirm', {
                        title: 'Close ' + this.month + ' for payroll',
                        body: 'Attendance corrections for ' + this.month + ' and earlier will be refused. Export the CSV first — it becomes the payroll record.',
                        confirmLabel: 'Close month',
                        onConfirm: () => this.send('/admin/attendance/report/lock'),
                    });
                },

                confirmUnlock() {
                    this.$dispatch('confirm', {
                        title: 'Re-open ' + this.month,
                        body: 'Corrections will be accepted again. Re-export the month afterwards so the payroll record matches.',
                        variant: 'danger',
                        confirmLabel: 'Re-open',
                        onConfirm: () => this.send('/admin/attendance/report/unlock'),
                    });
                },

                async send(url) {
                    const json = await postForm(url, { month: this.month });
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not change the payroll lock.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
