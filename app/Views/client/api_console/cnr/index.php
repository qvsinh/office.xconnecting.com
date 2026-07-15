<?php
/**
 * Client CNR developer console. Thin entry view — the console UI itself is
 * the shared `console/_console` partial (key selector, command/params
 * builder, response viewer); only `$keys`, `$provider`, `$zone` differ per
 * page. See Client\ConsoleCnr::index().
 *
 * @var list<object>                                                                        $keys     ApiAccessKeyModel::listForConsole() — session user's own enabled CNR keys
 * @var array<string, list<array{name: string, description: string, example?: array|null}>> $commands console_commands('cnr') — command catalog for the picker/docs panel
 */
$this->extend('layouts/app');
$pageTitle = 'CNR console';
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl">
    <?= view('console/_console', ['keys' => $keys, 'commands' => $commands, 'provider' => 'cnr', 'zone' => 'client']) ?>
</div>
<?php $this->endSection() ?>
