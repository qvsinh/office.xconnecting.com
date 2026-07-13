<?php
/**
 * Standalone login page (does not extend layouts/app — no session yet).
 * Posts JSON-handled form to POST /login with the CSRF token; contract:
 * success -> {status:'success', redirect_url} ; failure -> HTTP 401
 * {status:'failed', message}.
 */
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — proxyv2.services.vn</title>
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="flex h-full min-h-screen items-center justify-center bg-gray-50 px-4 font-sans text-[#111111] antialiased">
    <div class="w-full max-w-sm" x-data="loginForm()">
        <div class="mb-8 text-center">
            <span class="text-xl font-semibold tracking-tight">proxyv2</span>
            <p class="mt-1 text-sm text-[#787774]">Sign in to your console</p>
        </div>

        <div class="rounded-lg border border-[#EAEAEA] bg-white p-8 shadow-sm">
            <div x-show="errorMessage" x-cloak x-transition
                 class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <div class="flex items-start justify-between gap-3">
                    <span x-text="errorMessage"></span>
                    <button type="button" class="shrink-0 opacity-60 hover:opacity-100" @click="errorMessage = ''" aria-label="Dismiss">&times;</button>
                </div>
            </div>

            <form @submit.prevent="submit">
                <div class="mb-4">
                    <label for="email" class="mb-1.5 block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required
                           x-model="email"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    <p x-show="fieldErrors.email" x-cloak class="mt-1 text-xs text-red-700" x-text="fieldErrors.email"></p>
                </div>

                <div class="mb-6">
                    <label for="password" class="mb-1.5 block text-sm font-medium">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           x-model="password"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    <p x-show="fieldErrors.password" x-cloak class="mt-1 text-xs text-red-700" x-text="fieldErrors.password"></p>
                </div>

                <button type="submit" :disabled="submitting"
                        class="flex w-full items-center justify-center rounded-md bg-[#111111] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="!submitting">Log in</span>
                    <span x-show="submitting" x-cloak>Signing in…</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        function loginForm() {
            return {
                email: '',
                password: '',
                submitting: false,
                errorMessage: '',
                fieldErrors: {},

                validate() {
                    this.fieldErrors = {};
                    if (!this.email) this.fieldErrors.email = 'Email is required.';
                    if (!this.password) this.fieldErrors.password = 'Password is required.';
                    else if (this.password.length < 5) this.fieldErrors.password = 'Password must be at least 5 characters.';
                    return Object.keys(this.fieldErrors).length === 0;
                },

                async submit() {
                    this.errorMessage = '';
                    if (!this.validate()) return;

                    this.submitting = true;
                    const csrfName = document.querySelector('meta[name="csrf-token-name"]').content;
                    const csrfHash = document.querySelector('meta[name="csrf-token"]').content;

                    const body = new URLSearchParams();
                    body.set('email', this.email);
                    body.set('password', this.password);
                    body.set(csrfName, csrfHash);

                    try {
                        const res = await fetch('/login', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                        const json = await res.json();

                        if (res.ok && json.status === 'success') {
                            window.location = json.redirect_url;
                            return;
                        }

                        this.errorMessage = json.message || 'Login failed. Please try again.';
                    } catch (e) {
                        this.errorMessage = 'Network error. Please try again.';
                    } finally {
                        this.submitting = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
