<?php
/**
 * Standalone site-gate page (does not extend layouts/app — sits in front of
 * the app login, no session context yet). Posts JSON-handled form to
 * POST /basic-auth with the CSRF token; contract:
 * success -> {status:'success', redirect_url, csrf_hash} ;
 * failure -> HTTP 401 {status:'failed', message, csrf_hash}.
 */
helper('setting');
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <script>if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authentication — <?= esc(site_name()) ?></title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>[x-cloak] { display: none !important; }</style>
    <link rel="stylesheet" href="/css/app.css">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="flex h-full min-h-screen items-center justify-center bg-gray-50 px-4 font-sans text-[#111111] antialiased dark:bg-gray-900 dark:text-gray-100">
    <div class="w-full max-w-sm" x-data="basicAuthForm()">
        <div class="mb-8 text-center">
            <span class="text-xl font-semibold tracking-tight">Basic Authentication</span>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Authentication required</p>
        </div>

        <div class="rounded-lg border-2 border-[#111111] bg-white p-8 shadow-sm dark:border-gray-100 dark:bg-gray-800">
            <div x-show="errorMessage" x-cloak x-transition
                 class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300" role="alert">
                <div class="flex items-start justify-between gap-3">
                    <span x-text="errorMessage"></span>
                    <button type="button" class="shrink-0 opacity-60 hover:opacity-100" @click="errorMessage = ''" aria-label="Dismiss">&times;</button>
                </div>
            </div>

            <form @submit.prevent="submit">
                <div class="mb-4">
                    <label for="username" class="form-label">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" required
                           x-model="username" class="form-input">
                    <p x-show="fieldErrors.username" x-cloak class="mt-1 text-xs text-red-700 dark:text-red-400" x-text="fieldErrors.username"></p>
                </div>

                <div class="mb-6">
                    <label for="password" class="form-label">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           x-model="password" class="form-input">
                    <p x-show="fieldErrors.password" x-cloak class="mt-1 text-xs text-red-700 dark:text-red-400" x-text="fieldErrors.password"></p>
                </div>

                <button type="submit" :disabled="submitting" class="btn-primary flex w-full">
                    <span x-show="!submitting">Continue</span>
                    <span x-show="submitting" x-cloak>Verifying…</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        function basicAuthForm() {
            return {
                username: '',
                password: '',
                submitting: false,
                errorMessage: '',
                fieldErrors: {},

                validate() {
                    this.fieldErrors = {};
                    if (!this.username) this.fieldErrors.username = 'Username is required.';
                    if (!this.password) this.fieldErrors.password = 'Password is required.';
                    return Object.keys(this.fieldErrors).length === 0;
                },

                async submit() {
                    this.errorMessage = '';
                    if (!this.validate()) return;

                    this.submitting = true;
                    const csrfName = document.querySelector('meta[name="csrf-token-name"]').content;
                    const csrfHashMeta = document.querySelector('meta[name="csrf-token"]');

                    const body = new URLSearchParams();
                    body.set('username', this.username);
                    body.set('password', this.password);
                    body.set(csrfName, csrfHashMeta.content);

                    let res, json;
                    try {
                        res = await fetch('/basic-auth', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                    } catch (e) {
                        this.errorMessage = 'Network error. Please try again.';
                        this.submitting = false;
                        return;
                    }

                    try {
                        json = await res.json();
                    } catch (e) {
                        // Non-JSON response = CSRF/token failure or a cached/challenge
                        // page from a proxy/CDN, not a real network drop.
                        this.errorMessage = 'Security check failed (token expired or page cached). Please reload the page and try again.';
                        this.submitting = false;
                        return;
                    }

                    // Config\Security::$regenerate rotates the CSRF hash on every
                    // validated request (success or failure) — refresh our stored
                    // token now so an immediate retry doesn't 403 on a stale hash.
                    if (json.csrf_hash) {
                        csrfHashMeta.content = json.csrf_hash;
                    }

                    if (res.ok && json.status === 'success') {
                        window.location = json.redirect_url;
                        return;
                    }

                    this.errorMessage = json.message || 'Authentication failed. Please try again.';
                    this.submitting = false;
                },
            };
        }
    </script>
</body>
</html>
