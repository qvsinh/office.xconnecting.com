<?php
/**
 * One event pill — shared by the log list and the per-employee page so the same
 * event never reads two different ways.
 *
 * @var string $event AccessLogModel event value
 */

use App\Models\AccessLogModel;

$styles = [
    AccessLogModel::EVENT_LOGIN    => ['Login', 'border-[#A8BEDC] text-[#2C5282] dark:border-blue-700/60 dark:text-blue-300'],
    AccessLogModel::EVENT_CHECKIN  => ['Check-in', 'border-[#9CBFA0] text-[#2F6B39] dark:border-green-700/60 dark:text-green-300'],
    AccessLogModel::EVENT_CHECKOUT => ['Check-out', 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'],
];

[$label, $classes] = $styles[$event ?? ''] ?? ['Unknown', 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'];
?>
<span class="inline-flex whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium <?= esc($classes, 'attr') ?>"><?= esc($label) ?></span>
