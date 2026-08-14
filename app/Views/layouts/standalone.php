<?php
/**
 * Chrome for a page somebody reaches without being signed in — today, the
 * one-click decision pages the administrator's email links to.
 *
 * Deliberately not `layouts/app`: that one is a signed-in console (sidebar,
 * user menu, notification feed) and every one of those needs a session. The
 * head is the same as the auth screens', including the pre-paint that stops a
 * dark-mode visitor seeing a white flash.
 *
 * @var string|null $title
 */
helper(['setting', 'app']);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <script>(function () {
        var dark = localStorage.getItem('theme') === 'dark';
        if (dark) document.documentElement.classList.add('dark');
        document.documentElement.style.backgroundColor = dark ? '#111827' : '#f9fafb';
    })();</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Request') ?> — <?= esc(site_name()) ?></title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css?v=<?= esc(asset_version(), 'attr') ?>">
</head>
<body class="flex h-full min-h-screen items-center justify-center bg-gray-50 px-4 py-10 font-sans text-[#111111] antialiased dark:bg-gray-900 dark:text-gray-100">
    <div class="w-full max-w-lg">
        <?= $this->renderSection('content') ?>
        <p class="mt-6 text-center text-xs text-[#787774] dark:text-gray-400">
            <?= esc(site_name()) ?>
        </p>
    </div>
</body>
</html>
