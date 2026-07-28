<?php
/**
 * Admin dashboard — today's attendance at a glance, plus the counts that still
 * exist after the workspaces/projects/tasks module was removed.
 *
 * The status tiles are the same numbers `/admin/attendance/today` shows (both go
 * through `AttendanceCalendar`), and each one links straight into that board
 * filtered to the tile — a count you can't drill into is just decoration.
 *
 * Hallmark · component: stat-tiles + quick-links · genre: modern-minimal
 * states: default · hover · focus · active (read-only page)
 *
 * @var string                                                            $date   Vietnam calendar date
 * @var array<string, int>                                                $counts status => headcount for today
 * @var array{employees:int, groups:int, users:int, pending_requests:int} $stats
 */

use App\Libraries\AttendanceCalendar;

$this->extend('layouts/app');

// Problems first, then the routine — the order a manager triages in.
$tiles = [
    AttendanceCalendar::STATUS_LATE             => ['Late', 'text-[#7A5B14] dark:text-amber-200'],
    AttendanceCalendar::STATUS_ABSENT           => ['Absent', 'text-[#9F2F2D] dark:text-red-300'],
    AttendanceCalendar::STATUS_MISSING_CHECKOUT => ['No check-out', 'text-[#7A5B14] dark:text-amber-200'],
    AttendanceCalendar::STATUS_PRESENT          => ['Present', 'text-[#2F6B39] dark:text-green-300'],
    AttendanceCalendar::STATUS_NOT_YET          => ['Not in yet', 'text-[#787774] dark:text-gray-400'],
    AttendanceCalendar::STATUS_ON_LEAVE         => ['On leave', 'text-[#2C5282] dark:text-blue-300'],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Dashboard</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Company attendance for <?= esc($date) ?> (Vietnam time).
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/attendance/today" class="btn-secondary whitespace-nowrap">Today board</a>
            <a href="/admin/attendance/report" class="btn-primary whitespace-nowrap">Monthly report</a>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <?php foreach ($tiles as $status => [$label, $tone]) : ?>
            <a href="<?= esc('/admin/attendance/today?status=' . $status, 'attr') ?>"
               class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
                <p class="text-2xl font-semibold tabular-nums <?= esc($tone, 'attr') ?>"><?= esc((string) ($counts[$status] ?? 0)) ?></p>
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc($label) ?></p>
            </a>
        <?php endforeach ?>
    </div>

    <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Employees on the roster</dt>
            <dd class="mt-1 text-lg font-semibold tabular-nums"><?= esc((string) $stats['employees']) ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Active accounts</dt>
            <dd class="mt-1 text-lg font-semibold tabular-nums"><?= esc((string) $stats['users']) ?></dd>
        </div>
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Groups</dt>
            <dd class="mt-1 text-lg font-semibold tabular-nums"><?= esc((string) $stats['groups']) ?></dd>
        </div>
        <a href="/admin/requests?status=pending"
           class="rounded-lg border bg-white p-4 transition hover:border-[#111111] dark:bg-gray-800 dark:hover:border-gray-400 <?= $stats['pending_requests'] > 0
                ? 'border-[#E5C07B] dark:border-amber-700/60'
                : 'border-[#EAEAEA] dark:border-gray-700' ?>">
            <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Requests awaiting a decision</dt>
            <dd class="mt-1 text-lg font-semibold tabular-nums <?= $stats['pending_requests'] > 0 ? 'text-[#7A5B14] dark:text-amber-200' : '' ?>">
                <?= esc((string) $stats['pending_requests']) ?>
            </dd>
        </a>
    </dl>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <a href="/admin/attendance" class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="font-medium">All employees</p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Search, filter and correct attendance records.</p>
        </a>
        <a href="/admin/attendance/schedules" class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="font-medium">Work schedules</p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Office hours, lunch break and working days.</p>
        </a>
        <a href="/admin/attendance/holidays" class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="font-medium">Holidays</p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Without these, a public holiday counts as absence.</p>
        </a>
    </div>
</div>
<?php $this->endSection() ?>
