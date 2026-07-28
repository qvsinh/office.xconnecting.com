<?php
/**
 * Today's presence board — the screen a manager opens daily. Summary tiles that
 * filter the table below, then one row per active employee.
 *
 * Hallmark · component: stat-tiles + roster-table · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 * (no fabricated values — an employee with no row shows an em dash, and reads
 * "Not in yet" rather than "Absent" until the start time has passed)
 *
 * @var string             $date       Vietnam calendar date
 * @var list<object>       $board      {user, day, row, schedule} per active employee
 * @var array<string, int> $counts status => headcount
 * @var string             $statusOnly `?status=` tile filter, or ''
 */

use App\Libraries\AttendanceCalendar;

$this->extend('layouts/app');

helper('attendance');

// Tiles in the order a manager triages: problems first, then the routine.
$tiles = [
    AttendanceCalendar::STATUS_LATE             => ['Late', 'text-[#7A5B14] dark:text-amber-200'],
    AttendanceCalendar::STATUS_ABSENT           => ['Absent', 'text-[#9F2F2D] dark:text-red-300'],
    AttendanceCalendar::STATUS_MISSING_CHECKOUT => ['No check-out', 'text-[#7A5B14] dark:text-amber-200'],
    AttendanceCalendar::STATUS_PRESENT          => ['Present', 'text-[#2F6B39] dark:text-green-300'],
    AttendanceCalendar::STATUS_NOT_YET          => ['Not in yet', 'text-[#787774] dark:text-gray-400'],
    AttendanceCalendar::STATUS_ON_LEAVE         => ['On leave', 'text-[#2C5282] dark:text-blue-300'],
];

$visible = $statusOnly === ''
    ? $board
    : array_values(array_filter($board, static fn (object $entry): bool => $entry->day->status === $statusOnly));
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Today</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Who is in, who is late, who is missing — <?= esc($date) ?> (Vietnam time).
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/attendance/today" class="btn-secondary whitespace-nowrap">Refresh</a>
            <a href="/admin/attendance/report" class="btn-primary whitespace-nowrap">Monthly report</a>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <?php foreach ($tiles as $status => [$label, $tone]) : ?>
            <?php $active = $statusOnly === $status; ?>
            <a href="<?= esc($active ? '/admin/attendance/today' : '/admin/attendance/today?status=' . $status, 'attr') ?>"
               class="rounded-lg border bg-white p-4 transition hover:border-[#111111] dark:bg-gray-800 dark:hover:border-gray-400 <?= $active
                    ? 'border-[#111111] dark:border-gray-300'
                    : 'border-[#EAEAEA] dark:border-gray-700' ?>"
               <?= $active ? 'aria-current="true"' : '' ?>>
                <p class="text-2xl font-semibold tabular-nums <?= esc($tone, 'attr') ?>"><?= esc((string) ($counts[$status] ?? 0)) ?></p>
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc($label) ?></p>
            </a>
        <?php endforeach ?>
    </div>

    <?php if ($statusOnly !== '') : ?>
        <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
            Filtered to <strong><?= esc($statusOnly) ?></strong> —
            <a href="/admin/attendance/today" class="font-medium underline">show everyone</a>.
        </p>
    <?php endif ?>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Employee</th>
                    <th scope="col" class="px-4 py-3">Expected</th>
                    <th scope="col" class="px-4 py-3">In</th>
                    <th scope="col" class="px-4 py-3">Out</th>
                    <th scope="col" class="px-4 py-3">Worked</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($visible as $entry) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc((string) ($entry->user->name ?? $entry->user->email)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-[#787774] dark:text-gray-400">
                            <?= $entry->schedule === null ? '—' : esc(substr((string) $entry->schedule->start_time, 0, 5) . '–' . substr((string) $entry->schedule->end_time, 0, 5)) ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_local_time($entry->row->checkin_at ?? null)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_local_time($entry->row->checkout_at ?? null)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_format_duration($entry->day->worked_seconds)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= view('attendance/_status_pill', ['day' => $entry->day]) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) ($entry->row->checkin_ip ?? '—')) ?></td>
                    </tr>
                <?php endforeach ?>
                <?php if ($visible === []) : ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            <?= $board === [] ? 'No active employees on the roster.' : 'Nobody in this state right now.' ?>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>
<?php $this->endSection() ?>
