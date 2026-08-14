<?php
/**
 * After an emailed decision has actually been applied.
 *
 * Says plainly what happened and that the employee has been told, because the
 * reader is about to close the tab and will not check anything.
 *
 * @var object|null $row    the decided request
 * @var string      $action 'approve' | 'reject'
 */
$this->extend('layouts/standalone');
$this->setVar('title', 'Decision recorded');
$this->section('content');

$approved = $action === 'approve';
?>
<div class="rounded-2xl border border-[#EAEAEA] bg-white p-6 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <span class="inline-block rounded-full px-3 py-1 text-xs font-medium uppercase tracking-wide <?= $approved ? 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300' : 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/40 dark:text-red-300' ?>">
        <?= esc($approved ? 'Approved' : 'Rejected') ?>
    </span>
    <h1 class="mt-3 text-lg font-semibold tracking-tight dark:text-white/90">
        <?= esc($approved ? 'The request is approved.' : 'The request is rejected.') ?>
    </h1>
    <?php if ($row !== null) : ?>
        <p class="mt-1 text-sm text-[#5C5955] dark:text-gray-400">
            <?= esc(leave_request_label($row)) ?> · <?= esc(leave_request_dates($row)) ?>
        </p>
    <?php endif ?>
    <p class="mt-4 text-sm text-[#5C5955] dark:text-gray-400">
        The employee has been notified. This link has been used and will not work again.
    </p>
    <a href="<?= esc(site_url('admin/requests'), 'attr') ?>" class="btn-secondary mt-6 inline-flex">Open the approval queue</a>
</div>
<?php $this->endSection() ?>
