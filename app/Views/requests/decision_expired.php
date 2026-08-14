<?php
/**
 * Every dead end an emailed link can reach: unknown, already used, expired, or
 * the request was decided or withdrawn in the meantime.
 *
 * One page for all of them on purpose — distinguishing them would tell somebody
 * probing the endpoint whether a token ever existed. The way out is the app,
 * where the reader has a session and can see the real state.
 */
$this->extend('layouts/standalone');
$this->setVar('title', 'Link expired');
$this->section('content');
?>
<div class="rounded-2xl border border-[#EAEAEA] bg-white p-6 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <h1 class="text-lg font-semibold tracking-tight dark:text-white/90">This link no longer works</h1>
    <p class="mt-2 text-sm text-[#5C5955] dark:text-gray-400">
        It has already been used, it has expired, or the request was decided or withdrawn since the email was sent.
    </p>
    <a href="<?= esc(site_url('admin/requests'), 'attr') ?>" class="btn-primary mt-6 inline-flex">Open the approval queue</a>
</div>
<?php $this->endSection() ?>
