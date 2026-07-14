<?php
/**
 * Admin CNR developer console. Thin entry view — see
 * `client/api_console/cnr/index.php` for the shared-partial rationale. Admin
 * zone lists every user's CNR keys (enabled or not); the shared partial
 * shows the owning user and a disabled badge, and the search filters across
 * users client-side.
 *
 * @var list<object> $keys ApiAccessKeyModel::listForConsole(null, 'cnr') — every user's CNR keys, joined with owner name/email
 */
$this->extend('layouts/app');
$pageTitle = 'CNR console';
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <?= view('console/_console', ['keys' => $keys, 'provider' => 'cnr', 'zone' => 'admin']) ?>
</div>
<?php $this->endSection() ?>
