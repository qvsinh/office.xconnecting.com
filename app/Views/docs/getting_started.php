<?php
/**
 * In-app guide: how attendance works, for employees and for managers.
 *
 * Rewritten when the workspaces/projects/tasks module was removed — the previous
 * version walked through creating a workspace and a Kanban board and linked to
 * `/workspaces`, which no longer exists.
 *
 * Company hours are not quoted here; the page points at Work schedules instead,
 * so it can't go stale when the schedule changes.
 */
$this->extend('layouts/app');

helper('auth');

$employeeSteps = [
    [
        'title' => 'Check in when you arrive',
        'body'  => 'Open <a href="/attendance" class="font-medium text-[#111111] hover:underline dark:text-gray-100">My Attendance</a> '
            . 'and press <strong>Check in</strong>. The time is recorded in Vietnam time (UTC+7).',
    ],
    [
        'title' => 'Check out when you leave',
        'body'  => 'Press <strong>Check out</strong> on the same page. Pressing it again replaces the time, so an accidental '
            . 'early tap is nothing to worry about — the last check-out of the day is the one that counts.',
    ],
    [
        'title' => 'Forgot to check out?',
        'body'  => 'The day shows a <strong>No check-out</strong> flag and its hours are not counted. You cannot fix it '
            . 'yourself: ask your manager to correct it, which is recorded with their name so the change stays traceable.',
    ],
    [
        'title' => 'Check your month',
        'body'  => '<a href="/attendance/report" class="font-medium text-[#111111] hover:underline dark:text-gray-100">My Month</a> '
            . 'shows every day — hours worked, lateness, status. It is the same data your manager sees.',
    ],
    [
        'title' => 'Ask for a day off, or to arrive late',
        'body'  => 'Use <a href="/requests" class="font-medium text-[#111111] hover:underline dark:text-gray-100">My Requests</a>. '
            . 'A manager approves it before it counts. Only <strong>annual</strong> leave draws down your balance, and only '
            . 'working days count — a Friday-to-Monday break costs two days, and public holidays cost none.',
    ],
];

$managerSteps = [
    [
        'title' => 'Set the working day',
        'body'  => 'Under <strong>Work schedules</strong>: arrival, departure, lunch window, and which weekdays count. '
            . 'Lateness, absence and paid hours are all measured against this, so get it right first.',
    ],
    [
        'title' => 'Enter the public holidays',
        'body'  => 'Under <strong>Holidays</strong> — paste the year in one go. Until a date is listed, a public holiday '
            . 'counts as an absence for everybody.',
    ],
    [
        'title' => 'Watch the day',
        'body'  => 'The <strong>Today</strong> board shows who is in, late, absent, or missing a check-out. Before the start '
            . 'time a missing check-in reads "Not in yet", not "Absent" — nobody is absent at 08:00.',
    ],
    [
        'title' => 'Correct mistakes',
        'body'  => 'Under <strong>All employees</strong>. Every correction records who made it and keeps the employee\'s '
            . 'original times, because this data feeds payroll.',
    ],
    [
        'title' => 'Close the month',
        'body'  => 'On the <strong>Monthly report</strong>: export the CSV, then press <strong>Close month</strong>. After '
            . 'that, corrections to that month are refused — the exported file is the payroll record.',
    ],
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-3xl">

    <div>
        <h1 class="text-xl font-semibold tracking-tight">Getting started</h1>
        <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
            How attendance works here. Times are Vietnam time (UTC+7) throughout.
        </p>
    </div>

    <h2 class="mt-8 text-base font-semibold tracking-tight">For everyone</h2>
    <ol class="mt-3 space-y-3">
        <?php foreach ($employeeSteps as $index => $step) : ?>
            <li class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <p class="font-medium">
                    <span class="mr-2 text-[#787774] dark:text-gray-400"><?= esc((string) ($index + 1)) ?>.</span>
                    <?= esc($step['title']) ?>
                </p>
                <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= $step['body'] ?></p>
            </li>
        <?php endforeach ?>
    </ol>

    <?php if (user_can('attendance.manage')) : ?>
        <h2 class="mt-10 text-base font-semibold tracking-tight">For managers</h2>
        <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
            In order — the first two decide whether every number below them is right.
        </p>
        <ol class="mt-3 space-y-3">
            <?php foreach ($managerSteps as $index => $step) : ?>
                <li class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="font-medium">
                        <span class="mr-2 text-[#787774] dark:text-gray-400"><?= esc((string) ($index + 1)) ?>.</span>
                        <?= esc($step['title']) ?>
                    </p>
                    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= $step['body'] ?></p>
                </li>
            <?php endforeach ?>
        </ol>

        <div class="mt-6 rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-4 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
            <p class="font-medium">Two things that need a server admin, not this screen</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                <li>The reminder and digest jobs only run if the OS cron calls <code>php spark schedule:run</code>.</li>
                <li>Restricting check-in to the office network stays off until <code>attendance_ip_enforce</code> is set
                    to <code>1</code> in Settings, with the office address in <code>attendance_office_ips</code>.</li>
            </ul>
        </div>
    <?php endif ?>
</div>
<?php $this->endSection() ?>
