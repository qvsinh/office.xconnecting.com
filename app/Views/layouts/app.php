<?php
/**
 * Shared authenticated app shell: TailAdmin-style sidebar + topbar, plus the
 * shared toast / confirm-modal / alert Alpine components (defined once here,
 * reused by every future feature page). Child views extend this via
 * $this->extend('layouts/app') and fill the 'content' section.
 *
 * Expects (optional, falls back safely if absent):
 * - $user   : object|null  session('userWorking') — name/email/zone.
 * - $navZone: 'admin'|'client' — which sidebar nav to render (defaults by is_admin()).
 */
helper('auth');

/** @var object|null $user */
$user ??= session('userWorking');
$navZone ??= is_admin() ? 'admin' : 'client';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? esc($pageTitle) . ' — ' : '' ?>proxyv2.services.vn</title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="csrf-header-name" content="<?= esc(csrf_header(), 'attr') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full font-sans text-[#111111] antialiased"
      x-data="appShell()"
      @toast.window="pushToast($event.detail)"
      @confirm.window="openConfirm($event.detail)"
      @alert.window="pushAlert($event.detail)">

    <div class="flex h-full min-h-screen">
        <!-- Sidebar -->
        <aside class="hidden w-64 shrink-0 border-r border-[#EAEAEA] bg-white lg:flex lg:flex-col"
               x-show="!sidebarCollapsed" x-cloak>
            <div class="flex h-16 items-center border-b border-[#EAEAEA] px-6">
                <span class="text-lg font-semibold tracking-tight">proxyv2</span>
            </div>
            <nav class="flex-1 space-y-1 px-3 py-4">
                <?php if ($navZone === 'admin') : ?>
                    <a href="/admin/dashboard" class="flex items-center gap-3 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-[#111111]">
                        Dashboard
                    </a>
                    <a href="/admin/api-access-keys" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-[#111111] hover:bg-gray-100">
                        API access keys
                    </a>
                    <a href="/admin/api-history" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-[#111111] hover:bg-gray-100">
                        API history
                    </a>
                    <span class="block px-3 pt-4 text-xs font-medium uppercase tracking-wide text-[#787774]">Coming soon</span>
                    <span class="block cursor-default rounded-md px-3 py-2 text-sm text-[#787774]">Users</span>
                <?php else : ?>
                    <a href="/dashboard" class="flex items-center gap-3 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-[#111111]">
                        Dashboard
                    </a>
                    <a href="/my-api-access-keys" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-[#111111] hover:bg-gray-100">
                        API access keys
                    </a>
                    <a href="/api-history" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-[#111111] hover:bg-gray-100">
                        API history
                    </a>
                    <span class="block px-3 pt-4 text-xs font-medium uppercase tracking-wide text-[#787774]">Coming soon</span>
                    <span class="block cursor-default rounded-md px-3 py-2 text-sm text-[#787774]">CNR / JPRS console</span>
                <?php endif; ?>
            </nav>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col">
            <!-- Topbar -->
            <header class="flex h-16 items-center justify-between border-b border-[#EAEAEA] bg-white px-4 lg:px-6">
                <button type="button" class="rounded-md p-2 text-[#787774] hover:bg-gray-100 lg:hidden"
                        @click="sidebarCollapsed = !sidebarCollapsed" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <span class="text-sm font-medium text-[#787774]"><?= esc($navZone === 'admin' ? 'Admin console' : 'Client console') ?></span>

                <!-- User menu -->
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100"
                            @click="open = !open" :aria-expanded="open">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-[#111111]">
                            <?= esc(mb_strtoupper(mb_substr((string) ($user->name ?? '?'), 0, 1))) ?>
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block font-medium leading-none"><?= esc($user->name ?? '') ?></span>
                            <span class="block text-xs leading-none text-[#787774]"><?= esc($user->email ?? '') ?></span>
                        </span>
                    </button>
                    <div x-show="open" x-transition x-cloak
                         class="absolute right-0 z-20 mt-2 w-56 rounded-md border border-[#EAEAEA] bg-white py-1 shadow-sm">
                        <div class="border-b border-[#EAEAEA] px-4 py-2">
                            <p class="truncate text-sm font-medium"><?= esc($user->name ?? '') ?></p>
                            <p class="truncate text-xs text-[#787774]"><?= esc($user->email ?? '') ?></p>
                        </div>
                        <a href="/logout" class="block px-4 py-2 text-sm text-[#9F2F2D] hover:bg-gray-50">Log out</a>
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
                     'border-red-200 bg-red-50 text-red-800': a.variant === 'danger',
                     'border-yellow-200 bg-yellow-50 text-yellow-800': a.variant === 'warning',
                     'border-green-200 bg-green-50 text-green-800': a.variant === 'success',
                     'border-blue-200 bg-blue-50 text-blue-800': a.variant === 'info',
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
                     'border-green-200 bg-green-50 text-green-800': t.type === 'success',
                     'border-red-200 bg-red-50 text-red-800': t.type === 'error',
                     'border-yellow-200 bg-yellow-50 text-yellow-800': t.type === 'warning',
                     'border-blue-200 bg-blue-50 text-blue-800': t.type === 'info',
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
             class="relative w-full max-w-sm rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm"
             x-trap.noscroll="confirmState.open" role="dialog" aria-modal="true">
            <h2 class="text-base font-semibold" x-text="confirmState.title"></h2>
            <p class="mt-2 text-sm text-[#787774]" x-text="confirmState.body"></p>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50" @click="closeConfirm()">
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
