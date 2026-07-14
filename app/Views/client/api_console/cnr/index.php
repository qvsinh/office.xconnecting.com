<?php
/**
 * Client CNR developer console. Thin entry view — the console UI itself is
 * the shared `console/_console` partial (key selector, command/params
 * builder, response viewer); only `$keys`, `$provider`, `$zone` differ per
 * page. See Client\ConsoleCnr::index().
 *
 * @var list<object> $keys ApiAccessKeyModel::listForConsole() — session user's own enabled CNR keys
 */
$this->extend('layouts/app');
$pageTitle = 'CNR console';
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl">
    <?= view('console/_console', ['keys' => $keys, 'provider' => 'cnr', 'zone' => 'client']) ?>
</div>
<?php $this->endSection() ?>
