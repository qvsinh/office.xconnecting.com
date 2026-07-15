<?php
/**
 * Admin JPRS developer console. Thin entry view — see
 * `client/api_console/cnr/index.php` for the shared-partial rationale.
 *
 * @var list<object>                                                                        $keys     ApiAccessKeyModel::listForConsole(null, 'jprs') — every user's JPRS keys, joined with owner name/email
 * @var array<string, list<array{name: string, description: string, example?: array|null}>> $commands console_commands('jprs') — command catalog for the picker/docs panel
 */
$this->extend('layouts/app');
$pageTitle = 'JPRS console';
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl">
    <?= view('console/_console', ['keys' => $keys, 'commands' => $commands, 'provider' => 'jprs', 'zone' => 'admin']) ?>
</div>
<?php $this->endSection() ?>
