<?php
/**
 * One request-status pill, one colour vocabulary — shared by the employee list
 * and the approval queue so the same request never reads two different ways.
 *
 * @var string $status LeaveRequestModel status value
 */

use App\Models\LeaveRequestModel;

$styles = [
    LeaveRequestModel::STATUS_PENDING   => ['Pending', 'border-[#E5C07B] text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200'],
    LeaveRequestModel::STATUS_APPROVED  => ['Approved', 'border-[#9CBFA0] text-[#2F6B39] dark:border-green-700/60 dark:text-green-300'],
    LeaveRequestModel::STATUS_REJECTED  => ['Rejected', 'border-[#E0A3A2] text-[#9F2F2D] dark:border-red-800/60 dark:text-red-300'],
    LeaveRequestModel::STATUS_CANCELLED => ['Withdrawn', 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'],
];

[$label, $classes] = $styles[$status ?? ''] ?? ['Unknown', 'border-[#EAEAEA] text-[#787774] dark:border-gray-600 dark:text-gray-400'];
?>
<span class="inline-flex whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium <?= esc($classes, 'attr') ?>"><?= esc($label) ?></span>
