<?php
/**
 * Personal dashboard — the employee's attendance at a glance, then straight into
 * the pages that let them act.
 *
 * Hallmark · component: stat-tiles + quick-links · genre: modern-minimal
 * states: default · hover · focus · active (read-only page; the actual check-in
 * lives on /attendance so there is exactly one place that records one)
 *
 * @var object|null $today           today's attendance_days row (UTC columns)
 * @var string      $todayDate       Vietnam calendar date
 * @var string      $month           YYYY-MM
 * @var object      $summary         AttendanceCalendar month rollup
 * @var object      $balance         {year, quota, used, remaining}
 * @var int         $pendingRequests
 */

use App\Libraries\LeaveBalance;

$this->extend('layouts/app');

helper('attendance');

$checkedIn  = ($today->checkin_at ?? null) !== null;
$checkedOut = ($today->checkout_at ?? null) !== null;

$tiles = [
    ['Worked this month', attendance_format_duration($summary->worked_seconds)],
    ['Expected', attendance_format_duration($summary->expected_seconds)],
    ['Late', $summary->late . ' day(s)'],
    ['Leave left', LeaveBalance::formatDays($balance->remaining)],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight"><?= esc(lang('dash.title')) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Your attendance for <?= esc($month) ?>. Times in Vietnam time (UTC+7).
            </p>
        </div>
        <p class="text-sm text-[#787774] dark:text-gray-400"><?= esc($todayDate) ?></p>
    </div>

    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <dl class="grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc(lang('attendance.checked_in')) ?></dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums"><?= esc(attendance_local_time($today->checkin_at ?? null)) ?></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc(lang('attendance.checked_out')) ?></dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums"><?= esc(attendance_local_time($today->checkout_at ?? null)) ?></dd>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc(lang('dash.today')) ?></dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">
                        <?= esc(attendance_format_duration(attendance_worked_seconds($today->checkin_at ?? null, $today->checkout_at ?? null))) ?>
                    </dd>
                </div>
            </dl>
            <a href="/attendance" class="btn-primary whitespace-nowrap text-center">
                <?= $checkedIn ? ($checkedOut ? 'View my attendance' : 'Check out') : 'Check in' ?>
            </a>
        </div>
    </div>

    <?php if ($summary->missing_checkout > 0) : ?>
        <div class="mt-4 rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-4 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
            <?= esc((string) $summary->missing_checkout) ?> day(s) this month have no check-out recorded, so their hours are not counted.
            Ask your manager to correct them.
        </div>
    <?php endif ?>

    <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <?php foreach ($tiles as [$label, $value]) : ?>
            <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400"><?= esc($label) ?></dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums"><?= esc($value) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <a href="/attendance/report" class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="font-medium"><?= esc(lang('attendance.report_title')) ?></p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= esc(lang('dash.hint_month')) ?></p>
        </a>
        <a href="/requests" class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="font-medium">
                My requests
                <?php if ($pendingRequests > 0) : ?>
                    <span class="ml-1 rounded-full border border-[#E5C07B] px-2 py-0.5 text-xs font-medium text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200">
                        <?= esc((string) $pendingRequests) ?> pending
                    </span>
                <?php endif ?>
            </p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= esc(lang('dash.hint_requests')) ?></p>
        </a>
        <a href="/account" class="rounded-lg border border-[#EAEAEA] bg-white p-4 transition hover:border-[#111111] dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-400">
            <p class="font-medium"><?= esc(lang('nav.account')) ?></p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= esc(lang('dash.hint_account')) ?></p>
        </a>
    </div>
</div>
<?php $this->endSection() ?>
