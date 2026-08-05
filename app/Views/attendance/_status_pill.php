<?php
/**
 * One status pill, one colour vocabulary — shared by the employee page, the
 * management table and (card 01c) the today board and monthly report, so the
 * same day never reads two different ways on two screens.
 *
 * @var object|null $day AttendanceCalendar::evaluate() result, or null
 */

use App\Libraries\AttendanceCalendar;

if (! isset($day) || $day === null) {
    return;
}

$styles = [
    AttendanceCalendar::STATUS_PRESENT          => [lang('status.present'), 'border-[#9CBFA0] text-[#2F6B39] dark:border-green-700/60 dark:text-green-300'],
    AttendanceCalendar::STATUS_LATE             => [lang('status.late'), 'border-[#E5C07B] text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200'],
    AttendanceCalendar::STATUS_ABSENT           => [lang('status.absent'), 'border-[#E0A3A2] text-[#9F2F2D] dark:border-red-800/60 dark:text-red-300'],
    AttendanceCalendar::STATUS_ON_LEAVE         => [lang('status.on_leave'), 'border-[#A8BEDC] text-[#2C5282] dark:border-blue-700/60 dark:text-blue-300'],
    AttendanceCalendar::STATUS_HOLIDAY          => [lang('status.holiday'), 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'],
    AttendanceCalendar::STATUS_NON_WORKDAY      => [lang('status.non_working'), 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'],
    AttendanceCalendar::STATUS_MISSING_CHECKOUT => [lang('status.no_checkout'), 'border-[#E5C07B] text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200'],
    // Today only, before the start time — neutral grey, never the absent red.
    AttendanceCalendar::STATUS_NOT_YET => [lang('status.not_in_yet'), 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'],
];

[$label, $classes] = $styles[$day->status] ?? [lang('status.unknown'), 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'];

// The holiday's name is more useful than the word "Holiday" once it exists.
if ($day->status === AttendanceCalendar::STATUS_HOLIDAY && ($day->holiday_name ?? null) !== null) {
    $label = (string) $day->holiday_name;
}
?>
<span class="inline-flex whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium <?= esc($classes, 'attr') ?>"><?= esc($label) ?></span>
<?php if (($day->late_minutes ?? 0) > 0) : ?>
    <span class="ml-1 whitespace-nowrap text-xs text-[#787774] dark:text-gray-400">+<?= esc((string) $day->late_minutes) ?>m</span>
<?php endif ?>
<?php if (($day->late_excused ?? false) === true) : ?>
    <span class="ml-1 whitespace-nowrap text-xs text-[#787774] dark:text-gray-400"><?= esc(lang('status.late_excused')) ?></span>
<?php endif ?>
<?php if (($day->overtime_seconds ?? 0) > 0) : ?>
    <span class="ml-1 whitespace-nowrap text-xs text-[#787774] dark:text-gray-400"><?= esc(lang('attendance.ot')) ?> <?= esc(attendance_format_duration((int) $day->overtime_seconds)) ?></span>
<?php endif ?>
