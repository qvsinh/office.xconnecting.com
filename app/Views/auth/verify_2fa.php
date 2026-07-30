<?php
/**
 * Standalone second-factor challenge page (does not extend layouts/app —
 * the user is only password-correct, not yet authenticated; session has
 * `pending2faUserId` only, see Auth::showVerify2fa()/verify2fa()).
 * Posts to POST /verify-2fa with the CSRF token; contract:
 * success -> {status:'success', redirect_url} ; failure -> HTTP 401
 * {status:'failed', message} ; lockout -> HTTP 429 {status:'failed', message}.
 * Mirrors the hardened fetch pattern in auth/login.php.
 */
helper('setting');
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <script>(function () {
        // Paint the canvas before the first frame. The dark background otherwise
        // lives only on <body> via the external stylesheet, so <html> stays white
        // and every full-page navigation flashes white before the CSS lands.
        var dark = localStorage.getItem('theme') === 'dark';
        if (dark) document.documentElement.classList.add('dark');
        document.documentElement.style.backgroundColor = dark ? '#111827' : '#f9fafb';
    })();</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor verification — <?= esc(site_name()) ?></title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css?v=<?= esc(asset_version(), 'attr') ?>">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="flex h-full min-h-screen items-center justify-center bg-gray-50 px-4 font-sans text-[#111111] antialiased dark:bg-gray-900 dark:text-gray-100">
    <div class="w-full max-w-sm" x-data="verify2faForm()">
        <div class="mb-8 text-center">
            <span class="text-xl font-semibold tracking-tight">2FA</span>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Enter the code from your authenticator app</p>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-8 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div x-show="errorMessage" x-cloak x-transition
                 class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300" role="alert">
                <div class="flex items-start justify-between gap-3">
                    <span x-text="errorMessage"></span>
                    <button type="button" class="shrink-0 opacity-60 hover:opacity-100" @click="errorMessage = ''" aria-label="Dismiss">&times;</button>
                </div>
            </div>

            <div x-show="lockedOut" x-cloak x-transition
                 class="mb-4 rounded-md border border-yellow-200 bg-[#FBF3DB] px-4 py-3 text-sm text-[#956400] dark:border-yellow-900/50 dark:bg-yellow-950/40 dark:text-yellow-300" role="alert">
                Too many attempts. <a href="/login" class="font-medium underline">Return to login</a>.
            </div>

            <form @submit.prevent="submit" x-show="!lockedOut">
                <div class="mb-6">
                    <label for="code" class="form-label">Authentication code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required
                           x-model="code" placeholder="123456" class="form-input text-center text-lg tracking-widest">
                </div>

                <button type="submit" :disabled="submitting" class="btn-primary flex w-full">
                    <span x-show="!submitting">Verify</span>
                    <span x-show="submitting" x-cloak>Verifying…</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        function verify2faForm() {
            return {
                code: '',
                submitting: false,
                errorMessage: '',
                lockedOut: false,

                async submit() {
                    this.errorMessage = '';
                    if (!this.code || this.code.length !== 6) {
                        this.errorMessage = 'Enter the 6-digit code from your authenticator app.';
                        return;
                    }

                    this.submitting = true;
                    const csrfName = document.querySelector('meta[name="csrf-token-name"]').content;
                    const csrfHashMeta = document.querySelector('meta[name="csrf-token"]');

                    const body = new URLSearchParams();
                    body.set('code', this.code);
                    // Device context for the admin access log; diagnostics only.
                    body.set('client_screen', screen.width + 'x' + screen.height);
                    body.set(csrfName, csrfHashMeta.content);

                    let res, json;
                    try {
                        res = await fetch('/verify-2fa', {
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

                    if (res.status === 429) {
                        this.lockedOut = true;
                        this.submitting = false;
                        return;
                    }

                    this.errorMessage = json.message || 'Invalid code. Please try again.';
                    this.code = '';
                    this.submitting = false;
                },
            };
        }
    </script>
</body>
</html>
