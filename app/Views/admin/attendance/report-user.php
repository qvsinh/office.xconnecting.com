<?php
/**
 * One employee's month, day by day — the sheet you hand someone who disputes a
 * figure, so it carries the recorded times, the correction note and who made it.
 *
 * Hallmark · component: month-grid + summary-strip · genre: modern-minimal
 * states: default · hover · focus · active (read-only page: no mutations here)
 *
 * @var string                $month    YYYY-MM
 * @var object                $employee
 * @var array<string, object> $days     AttendanceCalendar evaluations by date
 * @var array<string, object> $rows     attendance_days rows by date
 * @var object                $summary  month rollup
 */
$this->extend('layouts/app');

helper('attendance');

$weekday = static fn (string $date): string => date('D', strtotime($date));

$stats = [
    ['Workdays', (string) $summary->workdays],
    ['Present', (string) $summary->present],
    ['Late', $summary->late . ' (' . $summary->late_minutes . ' min)'],
    ['Absent', (string) $summary->absent],
    ['On leave', (string) $summary->on_leave],
    ['Worked', attendance_format_duration($summary->worked_seconds)],
    ['Expected', attendance_format_duration($summary->expected_seconds)],
    ['Overtime', attendance_format_duration($summary->overtime_seconds)],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight"><?= esc((string) ($employee->name ?? $employee->email)) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Day-by-day attendance for <?= esc($month) ?> · <?= esc((string) $employee->email) ?>
            </p>
        </div>
        <a href="/admin/attendance/report?month=<?= esc($month, 'attr') ?>" class="btn-secondary whitespace-nowrap">Back to report</a>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="month" class="form-label">Month</label>
            <input type="month" id="month" name="month" value="<?= esc($month, 'attr') ?>" class="form-input">
        </div>
        <button type="submit" class="btn-secondary">Show</button>
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <?php foreach ($stats as [$label, $value]) : ?>
            <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc($label) ?></dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums"><?= esc($value) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Date</th>
                    <th scope="col" class="px-4 py-3">Day</th>
                    <th scope="col" class="px-4 py-3">In</th>
                    <th scope="col" class="px-4 py-3">Out</th>
                    <th scope="col" class="px-4 py-3">Worked</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Note</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($days as $date => $day) : ?>
                    <?php $row = $rows[$date] ?? null; ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums"><?= esc((string) $date) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-[#787774] dark:text-gray-400"><?= esc($weekday((string) $date)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_local_time($row->checkin_at ?? null)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_local_time($row->checkout_at ?? null)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_format_duration($day->worked_seconds)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= view('attendance/_status_pill', ['day' => $day]) ?></td>
                        <td class="px-4 py-3 text-[#787774] dark:text-gray-400">
                            <?= esc((string) ($row->note ?? '—')) ?>
                            <?php if (($row->edited_at ?? null) !== null) : ?>
                                <span class="ml-1 whitespace-nowrap rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300">corrected</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($days === []) : ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">Nothing recorded for this month.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-xs text-[#787774] dark:text-gray-400">
        A "corrected" row was adjusted by a manager; the employee's originally recorded times are kept for audit.
    </p>
</div>
<?php $this->endSection() ?>
