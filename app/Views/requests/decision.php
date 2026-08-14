<?php
/**
 * The confirmation step behind an emailed Approve / Reject button.
 *
 * Opening the link deliberately decides nothing: this page shows what is about
 * to happen and asks for one more click. Mail clients and link scanners fetch
 * URLs on their own, and an approval that a link preview can trigger is not an
 * approval anybody made.
 *
 * Both outcomes are offered here, whichever button was clicked — the reader is
 * looking at the request now, and making them go back to their inbox to change
 * their mind is how the wrong decision gets made.
 *
 * @var object      $row      the leave_requests row, still pending
 * @var object|null $employee the requester
 * @var string      $token    the emailed token, posted back with the decision
 * @var string      $action   'approve' | 'reject' — which button led here
 * @var string|null $error    a decision that was refused (locked period, race)
 */
$this->extend('layouts/standalone');
$this->setVar('title', 'Approve or reject');
$this->section('content');

$name      = $employee === null ? 'Unknown employee' : (trim((string) ($employee->name ?? '')) ?: (string) $employee->email);
$approving = $action === 'approve';
$reason    = trim((string) ($row->reason ?? ''));
?>
<div class="rounded-2xl border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <h1 class="text-lg font-semibold tracking-tight dark:text-white/90">
        <?= esc($approving ? 'Approve this request?' : 'Reject this request?') ?>
    </h1>
    <p class="mt-1 text-sm text-[#5C5955] dark:text-gray-400">
        Nothing has changed yet. Check the details, then confirm.
    </p>

    <?php if ($error !== null) : ?>
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
            <?= esc($error) ?>
        </div>
    <?php endif ?>

    <dl class="mt-5 space-y-3 text-sm">
        <div class="flex justify-between gap-4">
            <dt class="text-[#5C5955] dark:text-gray-400">Employee</dt>
            <dd class="text-right font-medium dark:text-gray-100"><?= esc($name) ?></dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt class="text-[#5C5955] dark:text-gray-400">Type</dt>
            <dd class="text-right font-medium dark:text-gray-100"><?= esc(leave_request_label($row)) ?></dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt class="text-[#5C5955] dark:text-gray-400">Dates</dt>
            <dd class="text-right font-medium tabular-nums dark:text-gray-100"><?= esc(leave_request_dates($row)) ?></dd>
        </div>
        <?php if ($reason !== '') : ?>
            <div>
                <dt class="text-[#5C5955] dark:text-gray-400">Reason</dt>
                <dd class="mt-1 whitespace-pre-line dark:text-gray-100"><?= esc($reason) ?></dd>
            </div>
        <?php endif ?>
    </dl>

    <form method="post" action="<?= esc(site_url('request-decision/' . $token), 'attr') ?>" class="mt-6 space-y-4">
        <?= csrf_field() ?>
        <div>
            <label for="review_note" class="form-label">Note for the employee <span class="font-normal text-[#787774]">(optional)</span></label>
            <input id="review_note" name="review_note" type="text" maxlength="255" class="form-input"
                   placeholder="<?= esc($approving ? 'Enjoy the time off.' : 'Why this cannot be approved', 'attr') ?>">
        </div>

        <div class="flex flex-col gap-2 sm:flex-row-reverse">
            <button type="submit" name="action" value="approve"
                    class="<?= $approving ? 'btn-primary' : 'btn-secondary' ?> w-full sm:w-auto">Approve</button>
            <button type="submit" name="action" value="reject"
                    class="<?= $approving ? 'btn-secondary' : 'btn-danger' ?> w-full sm:w-auto">Reject</button>
        </div>
    </form>
</div>

<p class="mt-4 text-center text-sm text-[#787774] dark:text-gray-400">
    Prefer the full queue? <a href="<?= esc(site_url('admin/requests'), 'attr') ?>" class="font-medium text-[#111111] underline dark:text-gray-200">Open it in the app</a>.
</p>
<?php $this->endSection() ?>
