<?php
/**
 * Shared authenticated app shell: TailAdmin-style sidebar + topbar, plus the
 * shared toast / confirm-modal / alert Alpine components (defined once here,
 * reused by every future feature page). Child views extend this via
 * $this->extend('layouts/app') and fill the 'content' section.
 *
 * The topbar identity is read straight from the session as `$navUser` and is
 * deliberately NOT a view variable: pages legitimately pass their own `$user`
 * (an array on /account, a *different* user on /admin/users/view), and a
 * `$user ??= session(...)` fallback let those shadow the chrome — the avatar and
 * name went blank on the account pages and showed the wrong person on the admin
 * user pages.
 *
 * Expects (optional, falls back safely if absent):
 * - $navZone: 'admin'|'user' — which sidebar nav to render (defaults by the
 *   VIEW-ONLY zone helper `is_working_admin()` — an admin "acting as" a client
 *   sees the client nav; see app/Helpers/auth_helper.php).
 */
helper(['auth', 'url', 'setting']);

/** The signed-in (or acted-as) identity the topbar renders. Never a view variable. */
$navUser = session('userWorking');
$navZone ??= is_working_admin() ? 'admin' : 'user';

// Admin-editable brand name (site_name setting; falls back in site_name()).
$siteName = site_name();

// Acting-as banner: only set when an admin has switched `userWorking` away
// from their own `userAuthenticated` identity via Admin\Users::actAs().
$actingAsUser = null;
if (is_authenticated()) {
    $workingUser = session('userWorking');
    $authUser    = session('userAuthenticated');
    if ($workingUser !== null && $authUser !== null && (int) $workingUser->id !== (int) $authUser->id) {
        $actingAsUser = $workingUser;
    }
}

// Sidebar nav, grouped TailAdmin-style. Same group shape for both zones so
// admin-as-client and real-client render identical Account links; the Admin
// group only exists when $navZone === 'admin'.
// Dashboard and Docs are top-level links (not wrapped in a
// group); the rest (Account, Admin) are collapsible accordion groups.
$dashboardHref = $navZone === 'admin' ? '/admin/dashboard' : '/dashboard';

// The employee's own attendance — everyone gets these, they are self-service.
// The management screens live in the Admin group below, so a user never sees
// company-wide links mixed in with their own.
$navGroups = [
    'Attendance' => [
        ['label' => 'My Attendance', 'href' => '/attendance'],
        ['label' => 'My Month', 'href' => '/attendance/report'],
        ['label' => 'My Requests', 'href' => '/requests'],
    ],
    'Account' => [
        ['label' => 'Profile', 'href' => '/account'],
        ['label' => 'Security', 'href' => '/account/security'],
        ['label' => 'Notifications', 'href' => '/notifications'],
        ['label' => 'API Keys', 'href' => '/account/api-keys'],
    ],
];

// Attendance management, keyed by the permission that actually gates the route.
// Kept separate from the zone check below because these are permission-gated,
// not admin-zone-gated: a department head holding `attendance.manage` must get
// them without being made a global admin.
$attendanceAdminItems = array_values(array_filter([
    user_can('attendance.manage') ? ['label' => 'Attendance · Today', 'href' => '/admin/attendance/today'] : null,
    user_can('attendance.manage') ? ['label' => 'Attendance · All employees', 'href' => '/admin/attendance'] : null,
    user_can('attendance.manage') ? ['label' => 'Attendance · Monthly report', 'href' => '/admin/attendance/report'] : null,
    user_can('requests.approve') ? ['label' => 'Attendance · Approve requests', 'href' => '/admin/requests'] : null,
    user_can('attendance.manage') ? ['label' => 'Attendance · Work schedules', 'href' => '/admin/attendance/schedules'] : null,
    user_can('attendance.manage') ? ['label' => 'Attendance · Holidays', 'href' => '/admin/attendance/holidays'] : null,
]));

if ($navZone === 'admin') {
    $navGroups['Admin'] = [
        ['label' => 'Users', 'href' => '/admin/users'],
        ['label' => 'Groups', 'href' => '/admin/groups'],
        ['label' => 'Settings', 'href' => '/admin/settings'],
        ['label' => 'Translations', 'href' => '/admin/translations'],
        ['label' => 'Email Templates', 'href' => '/admin/email-templates'],
        ['label' => 'Emails', 'href' => '/admin/emails'],
        ['label' => 'Access log', 'href' => '/admin/access-logs'],
        ['label' => 'Scheduled Tasks', 'href' => '/admin/scheduled-tasks'],
        ['label' => 'System info', 'href' => '/admin/system'],
        ['label' => 'Migrations', 'href' => '/admin/system/migrations'],
        ['label' => 'Logs', 'href' => '/admin/system/logs'],
    ];

    $navGroups['Admin'] = array_merge($attendanceAdminItems, $navGroups['Admin']);
} elseif ($attendanceAdminItems !== []) {
    // A permission holder who is not admin-zone still needs the management
    // screens; give them a Management group rather than the full Admin one.
    $navGroups['Management'] = $attendanceAdminItems;
}

// Inline icon-per-group map (heroicons-outline style, stroke="currentColor").
// Static developer-authored markup, not user data — no esc() needed on these.
$navIcons = [
    'Dashboard'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z" /></svg>',
    'Account'    => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 0114 0" /></svg>',
    'Workspace'  => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h6a2 2 0 012 2v1H3V7z" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7z" /></svg>',
    'Admin'      => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z" /></svg>',
    'Attendance' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l3 2" /></svg>',
    'Management' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-4-2.8M9 20H4v-2a3 3 0 014-2.8m5 4.8v-2a3 3 0 00-6 0v2m3-8a3 3 0 100-6 3 3 0 000 6zm7 1a2 2 0 100-4 2 2 0 000 4z" /></svg>',
    'Docs'       => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25a8.987 8.987 0 00-3-.512c-2.305 0-4.408.867-6 2.292m0-14.25v14.25" /></svg>',
];
$navIconDefault = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="8" /></svg>';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <script>
        var dark = localStorage.getItem('theme') === 'dark';
        if (dark) document.documentElement.classList.add('dark');
        // Paint the canvas now: the dark background lives on <body> via the
        // external stylesheet, so <html> would flash white on every navigation.
        document.documentElement.style.backgroundColor = dark ? '#111827' : '#f9fafb';
        // Pre-paint sidebar state (mirrors the dark-mode toggle above): read the
        // persisted desktop expanded/collapsed choice before Alpine boots so the
        // sidebar's initial width matches on first paint — no reload flash.
        if (localStorage.getItem('sidebarExpanded') === 'false') document.documentElement.classList.add('sidebar-collapsed');
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? esc($pageTitle) . ' — ' : '' ?><?= esc($siteName) ?></title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="csrf-header-name" content="<?= esc(csrf_header(), 'attr') ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css?v=<?= esc(asset_version(), 'attr') ?>">
    <script>
        /*
         * "3 hours ago" for a stored timestamp, with the exact value in the title.
         *
         * Timestamps are stored UTC (Config\App::$appTimezone = 'UTC'), and the
         * `Z` below is what says so — without it the browser would read
         * "2026-07-30 09:00:00" as local time and be hours out.
         *
         * NOT for the attendance screens: those already render Vietnam-local
         * times, and hydrating them here would convert twice.
         */
        window.timeAgo = function (value) {
            if (!value) return '\u2014';

            const at = new Date(String(value).replace(' ', 'T') + 'Z');
            if (isNaN(at)) return String(value);

            let seconds = Math.round((Date.now() - at.getTime()) / 1000);
            const future = seconds < 0;
            seconds = Math.abs(seconds);

            const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
            const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];

            for (const [unit, size] of units) {
                if (seconds >= size) {
                    const amount = Math.floor(seconds / size);
                    return rtf.format(future ? amount : -amount, unit);
                }
            }

            return rtf.format(future ? seconds : -seconds, 'second');
        };

        // Progressive enhancement: the server already rendered the raw value as
        // the element's text, so with JavaScript off the page still reads
        // correctly — and `assertSee('2026-07-30 09:00:00')` still passes.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-timeago]').forEach(function (el) {
                const raw = el.getAttribute('data-timeago');
                if (!raw) return;

                el.textContent = window.timeAgo(raw);
                el.setAttribute('title', raw + ' UTC');
            });
        });
    </script>
    <script defer src="https://unpkg.com/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full bg-gray-50 font-sans text-[#111111] antialiased dark:bg-gray-900 dark:text-gray-100"
      x-data="appShell()"
      x-init="init()"
      @toast.window="pushToast($event.detail)"
      @confirm.window="openConfirm($event.detail)"
      @alert.window="pushAlert($event.detail)">

    <div class="flex h-full min-h-screen">
        <!-- Mobile off-canvas backdrop -->
        <div class="fixed inset-0 z-40 bg-black/50 lg:hidden" x-show="sidebarMobileOpen" x-cloak
             x-transition.opacity @click="sidebarMobileOpen = false"
             @keydown.escape.window="sidebarMobileOpen = false"></div>

        <!-- Sidebar: fixed on desktop so the icon-rail hover-expand overlays
             content instead of reflowing it; off-canvas drawer on mobile. -->
        <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-[#EAEAEA] bg-white transition-all duration-200 ease-linear lg:translate-x-0 dark:border-gray-800 dark:bg-gray-900"
               :class="{
                   '-translate-x-full': !sidebarMobileOpen,
                   'translate-x-0': sidebarMobileOpen,
                   'lg:w-20': !sidebarExpanded && !sidebarHovered,
                   'lg:w-72': sidebarExpanded || sidebarHovered,
                   'lg:shadow-lg': sidebarHovered && !sidebarExpanded,
               }"
               @mouseenter="sidebarHovered = true" @mouseleave="sidebarHovered = false"
               x-trap.noscroll="sidebarMobileOpen">
            <div class="flex h-16 shrink-0 items-center overflow-hidden border-b border-[#EAEAEA] px-6 dark:border-gray-800">
                <span class="text-lg font-semibold tracking-tight" x-show="navLabelsVisible()"><?= esc($siteName) ?></span>
                <span class="text-lg font-semibold tracking-tight" x-show="!navLabelsVisible()" x-cloak><?= esc(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-3 py-4">
                <?php $dashboardActive = url_is($dashboardHref); ?>
                <a href="<?= esc($dashboardHref, 'attr') ?>" @click="sidebarMobileOpen = false"
                   class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium <?= $dashboardActive
                        ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                        : 'text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                    <?= $navIcons['Dashboard'] ?? $navIconDefault ?>
                    <span class="whitespace-nowrap" x-show="navLabelsVisible()">Dashboard</span>
                </a>
                <?php $docsActive = url_is('docs*'); ?>
                <a href="/docs" @click="sidebarMobileOpen = false"
                   class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium <?= $docsActive
                        ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                        : 'text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                    <?= $navIcons['Docs'] ?? $navIconDefault ?>
                    <span class="whitespace-nowrap" x-show="navLabelsVisible()">Docs</span>
                </a>
                <?php foreach ($navGroups as $groupLabel => $groupItems) :
                    $groupActive = false;

                    foreach ($groupItems as $item) {
                        if (url_is($item['href'])) {
                            $groupActive = true;
                            break;
                        }
                    }
                    ?>
                    <div x-data="{ open: <?= $groupActive ? 'true' : 'false' ?> }">
                        <button type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-sm font-medium text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                                @click="open = !open" :aria-expanded="open">
                            <span class="flex items-center gap-3">
                                <?= $navIcons[$groupLabel] ?? $navIconDefault ?>
                                <span class="whitespace-nowrap" x-show="navLabelsVisible()"><?= esc($groupLabel) ?></span>
                            </span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 transition-transform duration-200"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"
                                 x-show="navLabelsVisible()" :class="open ? 'rotate-180' : ''">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
                            </svg>
                        </button>
                        <!--
                          x-cloak, because the row-size class comes from :class and
                          therefore does not exist until Alpine binds. Without it
                          every group renders open on first paint, the nav overflows,
                          and its scrollbar flashes before collapsing.
                        -->
                        <div x-cloak class="grid transition-all duration-200 ease-in-out"
                             :class="(open && navLabelsVisible()) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
                            <div class="overflow-hidden">
                                <div class="space-y-1 pt-1">
                                    <?php foreach ($groupItems as $item) : $active = url_is($item['href']); ?>
                                        <a href="<?= esc($item['href'], 'attr') ?>" @click="sidebarMobileOpen = false"
                                           class="block whitespace-nowrap rounded-md py-2 pl-11 pr-3 text-sm font-medium <?= $active
                                                    ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                                                    : 'text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                                            <?= esc($item['label']) ?>
                                        </a>
                                    <?php endforeach ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </nav>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col transition-all duration-200 ease-linear"
             :class="sidebarExpanded ? 'lg:pl-72' : 'lg:pl-20'">
            <?php if ($actingAsUser !== null) : ?>
                <!-- Acting-as banner: lives INSIDE the content column (not at the
                     body top) so the fixed sidebar can't overlap it. Plain form,
                     no Alpine, so it never touches the shared appShell() root. -->
                <div class="flex items-center justify-between gap-3 border-b border-yellow-200 bg-yellow-50 px-4 py-2 text-sm text-yellow-800 sm:px-6 lg:px-8 dark:border-yellow-900/50 dark:bg-yellow-950/40 dark:text-yellow-300">
                    <span>Acting as <strong><?= esc($actingAsUser->name) ?></strong> (<?= esc($actingAsUser->email) ?>) — you are viewing their data.</span>
                    <form method="post" action="/admin/users/stop-acting-as" class="shrink-0">
                        <input type="hidden" name="<?= esc(csrf_token(), 'attr') ?>" value="<?= esc(csrf_hash(), 'attr') ?>">
                        <button type="submit" class="rounded-md border border-yellow-300 bg-white px-3 py-1 text-xs font-medium text-yellow-800 hover:bg-yellow-100 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-300 dark:hover:bg-yellow-900">
                            Return to admin
                        </button>
                    </form>
                </div>
            <?php endif ?>
            <!-- Topbar -->
            <header class="flex h-16 items-center justify-between border-b border-[#EAEAEA] bg-white px-4 sm:px-6 lg:px-8 dark:border-gray-800 dark:bg-gray-900">
                <button type="button" class="rounded-md p-2 text-[#787774] hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                        @click="toggleSidebar()" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <span class="text-sm font-medium text-[#787774] dark:text-gray-400"><?= esc($navZone === 'admin' ? 'Admin console' : 'Client console') ?></span>

                <div class="flex items-center gap-2">
                <!-- Theme toggle: isolated nested Alpine component, never merged
                     into appShell() (same invariant as the notification bell
                     below). Pre-paint script above already set the initial
                     `dark` class before Alpine loads, so this just mirrors it. -->
                <div x-data="{ dark: document.documentElement.classList.contains('dark') }">
                    <button type="button"
                            class="rounded-md p-2 text-[#787774] hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                            @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); document.documentElement.style.backgroundColor = dark ? '#111827' : '#f9fafb'; localStorage.setItem('theme', dark ? 'dark' : 'light')"
                            :aria-pressed="dark" aria-label="Toggle dark mode">
                        <svg x-show="!dark" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                        <svg x-show="dark" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M15.5 12a3.5 3.5 0 11-7 0 3.5 3.5 0 017 0z" />
                        </svg>
                    </button>
                </div>

                <!-- Notification bell: isolated nested Alpine component so it
                     never touches the shared appShell() toast/confirm/alert root. -->
                <div class="relative" x-data="notificationBell()" x-init="init()" @click.outside="open = false">
                    <button type="button" class="relative rounded-md p-2 text-[#787774] hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                            @click="toggle()" :aria-expanded="open" aria-label="Notifications">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9" />
                        </svg>
                        <span x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread"
                              class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-[#9F2F2D] px-1 text-[10px] font-medium leading-none text-white"></span>
                    </button>
                    <div x-show="open" x-transition x-cloak
                         class="absolute right-0 z-20 mt-2 w-80 rounded-md border border-[#EAEAEA] bg-white py-1 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-[#EAEAEA] px-4 py-2 dark:border-gray-700">
                            <p class="text-sm font-medium">Notifications</p>
                            <button type="button" class="text-xs font-medium text-[#111111] hover:underline disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-100"
                                    :disabled="markingAll || unread === 0" @click="markAllRead()">
                                Mark all as read
                            </button>
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            <p x-show="loading" x-cloak class="px-4 py-6 text-center text-sm text-[#787774] dark:text-gray-400">Loading…</p>
                            <template x-for="item in items" :key="item.id">
                                <button type="button" class="block w-full px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-700"
                                        :class="!item.is_read ? 'bg-[#E1F3FE]/40 dark:bg-brand-500/10' : ''"
                                        @click="markRead(item)">
                                    <p class="text-sm" :class="!item.is_read ? 'font-medium text-[#111111] dark:text-gray-100' : 'text-[#787774] dark:text-gray-400'" x-text="item.message"></p>
                                    <p class="mt-0.5 text-xs text-[#787774] dark:text-gray-500" x-text="item.created_at"></p>
                                </button>
                            </template>
                            <p x-show="!loading && items.length === 0" x-cloak class="px-4 py-6 text-center text-sm text-[#787774] dark:text-gray-400">No notifications yet.</p>
                        </div>
                        <a href="/notifications" class="block border-t border-[#EAEAEA] px-4 py-2 text-center text-sm font-medium text-[#111111] hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">
                            View all
                        </a>
                    </div>
                </div>

                <!-- User menu -->
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-800"
                            @click="open = !open" :aria-expanded="open">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-[#111111] dark:bg-gray-700 dark:text-gray-100">
                            <?= esc(mb_strtoupper(mb_substr((string) ($navUser->name ?? '?'), 0, 1))) ?>
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block font-medium leading-none"><?= esc($navUser->name ?? '') ?></span>
                            <span class="block text-xs leading-none text-[#787774] dark:text-gray-400"><?= esc($navUser->email ?? '') ?></span>
                        </span>
                    </button>
                    <div x-show="open" x-transition x-cloak
                         class="absolute right-0 z-20 mt-2 w-56 rounded-md border border-[#EAEAEA] bg-white py-1 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-[#EAEAEA] px-4 py-2 dark:border-gray-700">
                            <p class="truncate text-sm font-medium"><?= esc($navUser->name ?? '') ?></p>
                            <p class="truncate text-xs text-[#787774] dark:text-gray-400"><?= esc($navUser->email ?? '') ?></p>
                        </div>
                        <a href="/account" class="block px-4 py-2 text-sm text-[#111111] hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">Account</a>
                        <a href="/account/security" class="block px-4 py-2 text-sm text-[#111111] hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">Security</a>
                        <a href="/logout" class="block border-t border-[#EAEAEA] px-4 py-2 text-sm text-[#9F2F2D] hover:bg-gray-50 dark:border-gray-700 dark:text-red-400 dark:hover:bg-gray-700">Log out</a>
                    </div>
                </div>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                <?= $this->renderSection('content') ?>
            </main>
        </div>
    </div>

    <!-- Shared alert banner: any page/script triggers it via $dispatch('alert', {variant, text}) -->
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4">
        <template x-for="a in alerts" :key="a.id">
            <div class="pointer-events-auto w-full max-w-lg rounded-md border px-4 py-3 text-sm shadow-sm"
                 :class="{
                     'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300': a.variant === 'danger',
                     'border-yellow-200 bg-yellow-50 text-yellow-800 dark:border-yellow-900/50 dark:bg-yellow-950/40 dark:text-yellow-300': a.variant === 'warning',
                     'border-green-200 bg-green-50 text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300': a.variant === 'success',
                     'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/40 dark:text-blue-300': a.variant === 'info',
                 }"
                 x-show="true" x-transition role="alert">
                <div class="flex items-start justify-between gap-3">
                    <span x-text="a.text"></span>
                    <button type="button" class="shrink-0 text-current opacity-60 hover:opacity-100" @click="dismissAlert(a.id)" aria-label="Dismiss">&times;</button>
                </div>
            </div>
        </template>
    </div>

    <!-- Shared toast stack -->
    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex flex-col gap-2">
        <template x-for="t in toasts" :key="t.id">
            <div class="pointer-events-auto w-72 rounded-md border px-4 py-3 text-sm shadow-sm"
                 :class="{
                     'border-green-200 bg-green-50 text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300': t.type === 'success',
                     'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300': t.type === 'error',
                     'border-yellow-200 bg-yellow-50 text-yellow-800 dark:border-yellow-900/50 dark:bg-yellow-950/40 dark:text-yellow-300': t.type === 'warning',
                     'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/40 dark:text-blue-300': t.type === 'info',
                 }"
                 x-show="true" x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0" role="status">
                <span x-text="t.text"></span>
            </div>
        </template>
    </div>

    <!-- Shared confirm modal -->
    <div x-show="confirmState.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4"
         @keydown.escape.window="closeConfirm()">
        <div class="fixed inset-0 bg-black/40" x-show="confirmState.open" x-transition.opacity @click="closeConfirm()"></div>
        <div x-show="confirmState.open" x-transition
             class="modal-panel relative max-w-sm"
             x-trap.noscroll="confirmState.open" role="dialog" aria-modal="true">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="closeConfirm()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90" x-text="confirmState.title"></h2>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" x-text="confirmState.body"></p>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" class="btn-secondary" @click="closeConfirm()">
                    Cancel
                </button>
                <button type="button"
                        :class="confirmState.variant === 'danger' ? 'btn-danger' : 'btn-primary'"
                        @click="confirmAccept()">
                    <span x-text="confirmState.confirmLabel || 'Confirm'"></span>
                </button>
            </div>
        </div>
    </div>

    <script>
        function appShell() {
            return {
                // Desktop expanded/collapsed choice, persisted; mirrors the
                // 'sidebar-collapsed' html class the pre-paint <head> script
                // sets from localStorage before Alpine boots (no reload flash).
                sidebarExpanded: !document.documentElement.classList.contains('sidebar-collapsed'),
                sidebarHovered: false, // transient: true while hovering a collapsed desktop rail
                sidebarMobileOpen: false, // transient: mobile off-canvas drawer
                toasts: [],
                alerts: [],
                confirmState: { open: false, title: '', body: '', variant: 'default', confirmLabel: '', onConfirm: null },
                _seq: 0,

                init() {
                    this.$watch('sidebarExpanded', (v) => localStorage.setItem('sidebarExpanded', v ? 'true' : 'false'));
                },

                // Header hamburger: toggles the persisted rail on desktop (lg+,
                // matches Tailwind's `lg` breakpoint), the off-canvas drawer below it.
                toggleSidebar() {
                    if (window.innerWidth >= 1024) {
                        this.sidebarExpanded = !this.sidebarExpanded;
                    } else {
                        this.sidebarMobileOpen = !this.sidebarMobileOpen;
                    }
                },

                // Nav labels/chevrons show when: desktop expanded, desktop
                // collapsed-but-hovered (rail hover-expand), or mobile drawer open.
                navLabelsVisible() {
                    return this.sidebarExpanded || this.sidebarHovered || this.sidebarMobileOpen;
                },

                pushToast(detail) {
                    const id = ++this._seq;
                    this.toasts.push({ id, type: detail.type || 'info', text: detail.text || '' });
                    setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, detail.duration || 4000);
                },

                pushAlert(detail) {
                    const id = ++this._seq;
                    this.alerts.push({ id, variant: detail.variant || 'info', text: detail.text || '' });
                    return id;
                },
                dismissAlert(id) {
                    this.alerts = this.alerts.filter(a => a.id !== id);
                },

                openConfirm(detail) {
                    this.confirmState = {
                        open: true,
                        title: detail.title || 'Are you sure?',
                        body: detail.body || '',
                        variant: detail.variant || 'default',
                        confirmLabel: detail.confirmLabel || 'Confirm',
                        onConfirm: detail.onConfirm || null,
                    };
                },
                closeConfirm() {
                    this.confirmState.open = false;
                },
                confirmAccept() {
                    const cb = this.confirmState.onConfirm;
                    this.confirmState.open = false;
                    if (typeof cb === 'function') cb();
                },
            };
        }

        // Isolated topbar notification bell — its own x-data, never merged
        // into appShell(), so this can't regress the shared toast/confirm/alert
        // root. Loads the badge once on page load, the recent feed only when
        // opened (no interval polling, see kanban/processing/20260713-notifications.md §7).
        function notificationBell() {
            return {
                open: false,
                loading: false,
                markingAll: false,
                unread: 0,
                items: [],

                init() {
                    fetch('/notifications/unread-count', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(res => res.json())
                        .then(json => { if (json && json.status === 'success') this.unread = json.unread_count; })
                        .catch(() => {});
                },

                toggle() {
                    this.open = !this.open;
                    if (this.open) this.loadFeed();
                },

                async loadFeed() {
                    this.loading = true;
                    try {
                        const res = await fetch('/notifications/feed', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        const json = await res.json();
                        if (json && json.status === 'success') {
                            this.items = json.items;
                            this.unread = json.unread_count;
                        }
                    } catch (e) {
                        // silent — bell stays showing the last-known badge count
                    }
                    this.loading = false;
                },

                async markRead(item) {
                    if (item.is_read) return;
                    const json = await postForm('/notifications/mark-read', { id: item.id });
                    if (json && json.status === 'success') {
                        item.is_read = true;
                        this.unread = json.unread_count;
                    }
                },

                async markAllRead() {
                    this.markingAll = true;
                    const json = await postForm('/notifications/mark-all-read', {});
                    this.markingAll = false;
                    if (json && json.status === 'success') {
                        this.items.forEach(i => { i.is_read = true; });
                        this.unread = json.unread_count;
                    }
                },
            };
        }

        // Copy text to the clipboard, returning a Promise<bool>. The async
        // Clipboard API only exists in a secure context (HTTPS or localhost);
        // this app is served over plain HTTP, where navigator.clipboard is
        // undefined — so fall back to a temporary-textarea execCommand('copy'),
        // which works everywhere. Callers toast on the boolean result.
        window.copyText = async function (text) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return true;
                }
            } catch (e) {
                // fall through to the legacy path
            }
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            } catch (e) {
                return false;
            }
        };

        // CSRF helper for fetch() calls from any page: window.csrf.{fieldName,token,headerName}
        window.csrf = {
            fieldName: document.querySelector('meta[name="csrf-token-name"]')?.content,
            token: document.querySelector('meta[name="csrf-token"]')?.content,
            headerName: document.querySelector('meta[name="csrf-header-name"]')?.content,
        };

        // Shared POST-as-form helper for fetch()-driven mutation endpoints.
        // Sends `fields` + the CSRF token as x-www-form-urlencoded, refreshes
        // window.csrf.token from the JSON envelope's csrf_hash (rotates on
        // every validated request — see auth/login.php), parses JSON on both
        // success and error responses, and never throws on 4xx. Returns the
        // parsed JSON, or null on a network/parse failure (after toasting).
        async function postForm(url, fields) {
            const body = new URLSearchParams();
            Object.entries(fields || {}).forEach(([k, v]) => body.set(k, v ?? ''));
            body.set(window.csrf.fieldName, window.csrf.token);

            let res, json;
            try {
                res = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
            } catch (e) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: 'Network error. Please try again.' } }));
                return null;
            }

            try {
                json = await res.json();
            } catch (e) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: 'Network error. Please try again.' } }));
                return null;
            }

            if (json.csrf_hash) window.csrf.token = json.csrf_hash;
            return json;
        }
    </script>
</body>
</html>
