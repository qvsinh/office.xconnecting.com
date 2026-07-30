<?php
/**
 * Public landing page (standalone — does not extend layouts/app, since guests
 * are not signed in). Mirrors the login page's standalone-page scaffolding and
 * uses the admin-editable site_name() for branding.
 */
helper('setting');

// Static, developer-authored feature list (not user data).
$features = [
    ['title' => 'Workspaces & Projects', 'body' => 'Spin up shared workspaces, create projects, and invite the people who need to be there.'],
    ['title' => 'Kanban & Task Lists', 'body' => 'Plan work on a drag-and-drop board with columns, priorities, assignees, and due dates.'],
    ['title' => 'File Sharing', 'body' => 'Keep every project’s files in one place with per-project upload, download, and access control.'],
    ['title' => 'Groups & Permissions', 'body' => 'Organise people into groups and control who can see and manage each workspace.'],
    ['title' => 'API for AI Agents', 'body' => 'Issue personal API keys so your AI agents can read projects and manage tasks over REST.'],
    ['title' => 'Dashboards', 'body' => 'A company-wide overview for admins and a personal dashboard for everyone else.'],
];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <script>if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(site_name()) ?> — Internal Workspace</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css?v=<?= esc(asset_version(), 'attr') ?>">
</head>
<body class="flex min-h-full flex-col bg-gray-50 font-sans text-[#111111] antialiased dark:bg-gray-900 dark:text-gray-100">

    <!-- Top bar -->
    <header class="flex items-center justify-between px-4 py-5 sm:px-6 lg:px-8">
        <span class="text-lg font-semibold tracking-tight"><?= esc(site_name()) ?></span>
        <a href="/login" class="btn-primary">Log in</a>
    </header>

    <!-- Hero -->
    <main class="flex-1">
        <section class="mx-auto max-w-4xl px-4 pb-16 pt-14 text-center sm:px-6 sm:pt-24 lg:px-8">
            <h1 class="text-3xl font-bold leading-tight tracking-tight sm:text-5xl">
                Your company’s internal workspace
            </h1>
            <p class="mx-auto mt-4 max-w-2xl text-base text-[#787774] sm:text-lg dark:text-gray-400">
                Projects, tasks, files, groups, and an API for your AI agents — all in one place,
                behind your company login.
            </p>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <a href="/login" class="btn-primary px-6 py-2.5 text-base">Log in to continue</a>
            </div>
        </section>

        <!-- Feature grid -->
        <section class="mx-auto max-w-5xl px-4 pb-24 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($features as $feature) : ?>
                    <div class="rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="text-base font-semibold"><?= esc($feature['title']) ?></h2>
                        <p class="mt-2 text-sm text-[#787774] dark:text-gray-400"><?= esc($feature['body']) ?></p>
                    </div>
                <?php endforeach ?>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="border-t border-[#EAEAEA] px-4 py-8 text-center text-sm text-[#787774] sm:px-6 lg:px-8 dark:border-gray-800 dark:text-gray-400">
        &copy; <?= esc(date('Y')) ?> <?= esc(site_name()) ?>. Internal use only.
    </footer>

</body>
</html>
