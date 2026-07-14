<?php
/**
 * Shared authenticated app shell: TailAdmin-style sidebar + topbar, plus the
 * shared toast / confirm-modal / alert Alpine components (defined once here,
 * reused by every future feature page). Child views extend this via
 * $this->extend('layouts/app') and fill the 'content' section.
 *
 * Expects (optional, falls back safely if absent):
 * - $user   : object|null  session('userWorking') — name/email/zone.
 * - $navZone: 'admin'|'client' — which sidebar nav to render (defaults by the
 *   VIEW-ONLY zone helper `is_working_admin()` — an admin "acting as" a client
 *   sees the client nav; see app/Helpers/auth_helper.php).
 */
helper(['auth', 'url']);

/** @var object|null $user */
$user ??= session('userWorking');
$navZone ??= is_working_admin() ? 'admin' : 'client';

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
$navGroups = [
    'Menu' => [
        ['label' => 'Dashboard', 'href' => $navZone === 'admin' ? '/admin/dashboard' : '/dashboard'],
    ],
    'API' => [
        ['label' => 'Access Keys', 'href' => $navZone === 'admin' ? '/admin/api-access-keys' : '/my-api-access-keys'],
        ['label' => 'History', 'href' => $navZone === 'admin' ? '/admin/api-history' : '/api-history'],
        ['label' => 'CNR Console', 'href' => $navZone === 'admin' ? '/admin/console/cnr' : '/console/cnr'],
        ['label' => 'JPRS Console', 'href' => $navZone === 'admin' ? '/admin/console/jprs' : '/console/jprs'],
        ['label' => 'API Docs', 'href' => '/api-docs'],
    ],
    'Account' => [
        ['label' => 'Profile', 'href' => '/account'],
        ['label' => 'Security', 'href' => '/account/security'],
        ['label' => 'Notifications', 'href' => '/notifications'],
    ],
];

if ($navZone === 'admin') {
    $navGroups['Admin'] = [
        ['label' => 'Users', 'href' => '/admin/users'],
        ['label' => 'Servers', 'href' => '/admin/servers'],
        ['label' => 'Settings', 'href' => '/admin/settings'],
        ['label' => 'Translations', 'href' => '/admin/translations'],
        ['label' => 'Email Templates', 'href' => '/admin/email-templates'],
        ['label' => 'Sent Emails', 'href' => '/admin/emails'],
        ['label' => 'Scheduled Tasks', 'href' => '/admin/scheduled-tasks'],
        ['label' => 'System info', 'href' => '/admin/system'],
        ['label' => 'Migrations', 'href' => '/admin/system/migrations'],
        ['label' => 'Logs', 'href' => '/admin/system/logs'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <script>if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? esc($pageTitle) . ' — ' : '' ?>proxyv2.services.vn</title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="csrf-header-name" content="<?= esc(csrf_header(), 'attr') ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    <style>[x-cloak] { display: none !important; }</style>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#ECF3FF', 100: '#DDE9FF', 200: '#C2D6FF', 300: '#9CB9FF',
                            400: '#7592FF', 500: '#465FFF', 600: '#3641F5', 700: '#2A31D8',
                            800: '#252DAE', 900: '#262E89',
                        },
                    },
                },
            },
        };
    </script>
    <script defer src="https://unpkg.com/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full bg-gray-50 font-sans text-[#111111] antialiased dark:bg-gray-900 dark:text-gray-100"
      x-data="appShell()"
      @toast.window="pushToast($event.detail)"
      @confirm.window="openConfirm($event.detail)"
      @alert.window="pushAlert($event.detail)">

    <?php if ($actingAsUser !== null) : ?>
        <!-- Acting-as banner: a plain form (no Alpine) so it can never
             regress the shared appShell() toast/confirm root above. -->
        <div class="flex items-center justify-between gap-3 border-b border-yellow-200 bg-yellow-50 px-4 py-2 text-sm text-yellow-800 dark:border-yellow-900/50 dark:bg-yellow-950/40 dark:text-yellow-300">
            <span>Acting as <strong><?= esc($actingAsUser->name) ?></strong> (<?= esc($actingAsUser->email) ?>) — you are viewing their data.</span>
            <form method="post" action="/admin/users/stop-acting-as" class="shrink-0">
                <input type="hidden" name="<?= esc(csrf_token(), 'attr') ?>" value="<?= esc(csrf_hash(), 'attr') ?>">
                <button type="submit" class="rounded-md border border-yellow-300 bg-white px-3 py-1 text-xs font-medium text-yellow-800 hover:bg-yellow-100 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-300 dark:hover:bg-yellow-900">
                    Return to admin
                </button>
            </form>
        </div>
    <?php endif ?>

    <div class="flex h-full min-h-screen">
        <!-- Sidebar -->
        <aside class="hidden w-64 shrink-0 border-r border-[#EAEAEA] bg-white lg:flex lg:flex-col dark:border-gray-800 dark:bg-gray-900"
               x-show="!sidebarCollapsed" x-cloak>
            <div class="flex h-16 items-center border-b border-[#EAEAEA] px-6 dark:border-gray-800">
                <span class="text-lg font-semibold tracking-tight">proxyv2</span>
            </div>
            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                <?php foreach ($navGroups as $groupLabel => $groupItems) : ?>
                    <div>
                        <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500"><?= esc($groupLabel) ?></p>
                        <div class="space-y-1">
                            <?php foreach ($groupItems as $item) : $active = url_is($item['href']); ?>
                                <a href="<?= esc($item['href'], 'attr') ?>"
                                   class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium <?= $active
                                        ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                                        : 'text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                                    <?= esc($item['label']) ?>
                                </a>
                            <?php endforeach ?>
                        </div>
                    </div>
                <?php endforeach ?>
            </nav>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col">
            <!-- Topbar -->
            <header class="flex h-16 items-center justify-between border-b border-[#EAEAEA] bg-white px-4 lg:px-6 dark:border-gray-800 dark:bg-gray-900">
                <button type="button" class="rounded-md p-2 text-[#787774] hover:bg-gray-100 lg:hidden dark:text-gray-400 dark:hover:bg-gray-800"
                        @click="sidebarCollapsed = !sidebarCollapsed" aria-label="Toggle sidebar">
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
                            @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); localStorage.setItem('theme', dark ? 'dark' : 'light')"
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
                            <?= esc(mb_strtoupper(mb_substr((string) ($user->name ?? '?'), 0, 1))) ?>
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block font-medium leading-none"><?= esc($user->name ?? '') ?></span>
                            <span class="block text-xs leading-none text-[#787774] dark:text-gray-400"><?= esc($user->email ?? '') ?></span>
                        </span>
                    </button>
                    <div x-show="open" x-transition x-cloak
                         class="absolute right-0 z-20 mt-2 w-56 rounded-md border border-[#EAEAEA] bg-white py-1 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-[#EAEAEA] px-4 py-2 dark:border-gray-700">
                            <p class="truncate text-sm font-medium"><?= esc($user->name ?? '') ?></p>
                            <p class="truncate text-xs text-[#787774] dark:text-gray-400"><?= esc($user->email ?? '') ?></p>
                        </div>
                        <a href="/account" class="block px-4 py-2 text-sm text-[#111111] hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">Account</a>
                        <a href="/account/security" class="block px-4 py-2 text-sm text-[#111111] hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">Security</a>
                        <a href="/logout" class="block border-t border-[#EAEAEA] px-4 py-2 text-sm text-[#9F2F2D] hover:bg-gray-50 dark:border-gray-700 dark:text-red-400 dark:hover:bg-gray-700">Log out</a>
                    </div>
                </div>
                </div>
            </header>

            <main class="flex-1 p-4 lg:p-6">
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
             class="relative w-full max-w-sm rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
             x-trap.noscroll="confirmState.open" role="dialog" aria-modal="true">
            <h2 class="text-base font-semibold" x-text="confirmState.title"></h2>
            <p class="mt-2 text-sm text-[#787774] dark:text-gray-400" x-text="confirmState.body"></p>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700" @click="closeConfirm()">
                    Cancel
                </button>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm font-medium text-white"
                        :class="confirmState.variant === 'danger' ? 'bg-[#9F2F2D] hover:bg-[#7f2523]' : 'bg-[#111111] hover:bg-[#333333]'"
                        @click="confirmAccept()">
                    <span x-text="confirmState.confirmLabel || 'Confirm'"></span>
                </button>
            </div>
        </div>
    </div>

    <script>
        function appShell() {
            return {
                sidebarCollapsed: false,
                toasts: [],
                alerts: [],
                confirmState: { open: false, title: '', body: '', variant: 'default', confirmLabel: '', onConfirm: null },
                _seq: 0,

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
