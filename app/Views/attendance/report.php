<?php
/**
 * The employee's own monthly summary. Same numbers their manager sees, which is
 * the point: transparency prevents most disputes before they start.
 *
 * There is no employee selector here — the controller always scopes to the
 * session user, so there is nothing to tamper with in the query string.
 *
 * Hallmark · component: summary-strip + month-grid · genre: modern-minimal
 * states: default · hover · focus · active (read-only page)
 *
 * @var string                $month   YYYY-MM
 * @var array<string, object> $days    AttendanceCalendar evaluations by date
 * @var array<string, object> $rows    attendance_days rows by date
 * @var object                $summary month rollup
 */
$this->extend('layouts/app');

helper('attendance');

$weekday = static fn (string $date): string => date('D', strtotime($date));

$stats = [
    ['Worked', attendance_format_duration($summary->worked_seconds)],
    ['Expected', attendance_format_duration($summary->expected_seconds)],
    ['Overtime', attendance_format_duration($summary->overtime_seconds)],
    ['Late', $summary->late . ' day(s), ' . $summary->late_minutes . ' min'],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-4xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">My month</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Your attendance for <?= esc($month) ?>. Hours are net of the lunch break; times in Vietnam time.
            </p>
        </div>
        <a href="/attendance" class="btn-secondary whitespace-nowrap">Back to check-in</a>
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

    <?php if ($summary->missing_checkout > 0) : ?>
        <div class="mt-4 rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-4 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
            <?= esc((string) $summary->missing_checkout) ?> day(s) this month have no check-out recorded, so their hours are not counted.
            Ask your manager to correct them.
        </div>
    <?php endif ?>

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
                    </tr>
                <?php endforeach ?>
                <?php if ($days === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">Nothing recorded for this month yet.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>
<?php $this->endSection() ?>
