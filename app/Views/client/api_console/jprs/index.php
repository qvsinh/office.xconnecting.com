<?php
/**
 * Client JPRS developer console. Thin entry view — see
 * `client/api_console/cnr/index.php` for the shared-partial rationale.
 *
 * @var list<object> $keys ApiAccessKeyModel::listForConsole() — session user's own enabled JPRS keys
 */
$this->extend('layouts/app');
$pageTitle = 'JPRS console';
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <?= view('console/_console', ['keys' => $keys, 'provider' => 'jprs', 'zone' => 'client']) ?>
</div>
<?php $this->endSection() ?>
