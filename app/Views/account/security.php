<?php
/**
 * Self-service 2FA (Google Authenticator / TOTP) enrollment + disable.
 * Account::setupGoogle2fa() returns the secret/QR/otpauth URL exactly once,
 * for the request that generates it — this page never re-fetches or
 * persists that response; a page reload always starts from
 * `two_factor_method` alone (no secret in view data, see Account::security()).
 *
 * @var array{id:int,name:string,email:string,company:?string,phone_number:?string,language:string,timezone:string,two_factor_method:string} $user
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-2xl" x-data="accountSecurity(<?= esc(json_encode($user['two_factor_method']), 'attr') ?>)">

    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Security</h1>
            <p class="mt-1 text-sm text-[#787774]">Two-factor authentication for your account.</p>
        </div>
        <a href="/account" class="text-sm font-medium text-[#111111] hover:underline">← Profile</a>
    </div>

    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-base font-semibold">Two-factor authentication</h2>
                <p class="mt-1 text-sm text-[#787774]">
                    Status:
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide"
                          :class="method === 'none' ? 'bg-[#FBF3DB] text-[#956400]' : 'bg-[#EDF3EC] text-[#346538]'"
                          x-text="method === 'none' ? 'Disabled' : 'Enabled (' + method + ')'"></span>
                </p>
            </div>
            <button type="button" x-show="method === 'none' && !enrolling" x-cloak @click="startSetup()"
                    :disabled="startingSetup"
                    class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60">
                <span x-show="!startingSetup">Enable 2FA</span>
                <span x-show="startingSetup" x-cloak>Starting…</span>
            </button>
        </div>

        <!-- Enrollment: QR + manual key shown once, then a code to confirm -->
        <div x-show="enrolling" x-cloak x-transition class="mt-6 space-y-4 border-t border-[#EAEAEA] pt-6">
            <div class="rounded-md border border-yellow-200 bg-[#FBF3DB] px-4 py-3 text-sm text-[#956400]">
                Save this key now — it will not be shown again. Scan the QR code (or enter the key
                manually) in your authenticator app, then enter a code below to finish enabling 2FA.
            </div>
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
                <img :src="qrCode" alt="2FA QR code" class="h-40 w-40 rounded-md border border-[#EAEAEA]">
                <div class="flex-1">
                    <label class="mb-1.5 block text-sm font-medium">Manual key</label>
                    <div class="flex items-center gap-2">
                        <code class="block flex-1 rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 text-xs" x-text="secret"></code>
                        <button type="button" class="text-sm font-medium text-[#111111] hover:underline" @click="copySecret()">Copy</button>
                    </div>
                </div>
            </div>
            <form @submit.prevent="verifySetup" class="space-y-2">
                <label for="verify_code" class="mb-1.5 block text-sm font-medium">Authentication code</label>
                <input id="verify_code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" required
                       x-model="verifyCode" placeholder="123456"
                       class="block w-full max-w-[200px] rounded-md border border-[#EAEAEA] px-3 py-2 text-sm tracking-widest focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <div class="flex gap-2 pt-2">
                    <button type="submit" :disabled="verifying"
                            class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-show="!verifying">Verify &amp; enable</span>
                        <span x-show="verifying" x-cloak>Verifying…</span>
                    </button>
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm hover:bg-gray-50" @click="cancelSetup()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- Disable: requires current code or account password -->
        <div x-show="method !== 'none' && !enrolling" x-cloak class="mt-6 space-y-3 border-t border-[#EAEAEA] pt-6">
            <h3 class="text-sm font-semibold">Disable two-factor authentication</h3>
            <p class="text-sm text-[#787774]">Confirm with your current authentication code or your account password.</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="disable_code" class="mb-1.5 block text-sm font-medium">Current code</label>
                    <input id="disable_code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           x-model="disableCode" placeholder="123456"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                </div>
                <div>
                    <label for="disable_password" class="mb-1.5 block text-sm font-medium">Account password</label>
                    <input id="disable_password" type="password" autocomplete="current-password"
                           x-model="disablePassword"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                </div>
            </div>
            <div class="flex justify-end pt-2">
                <button type="button" :disabled="disabling" @click="confirmDisable()"
                        class="rounded-md bg-[#9F2F2D] px-4 py-2 text-sm font-medium text-white hover:bg-[#7f2523] disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="!disabling">Disable 2FA</span>
                    <span x-show="disabling" x-cloak>Disabling…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function accountSecurity(initialMethod) {
        return {
            method: initialMethod,
            enrolling: false,
            startingSetup: false,
            verifying: false,
            disabling: false,
            secret: '',
            qrCode: '',
            verifyCode: '',
            disableCode: '',
            disablePassword: '',

            async startSetup() {
                this.startingSetup = true;
                const json = await postForm('/account/2fa/setup', {});
                this.startingSetup = false;
                if (!json) return;
                if (json.status === 'success') {
                    this.secret = json.secret;
                    this.qrCode = json.qr_code;
                    this.verifyCode = '';
                    this.enrolling = true;
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Could not start enrollment.' });
                }
            },

            cancelSetup() {
                this.enrolling = false;
                this.secret = '';
                this.qrCode = '';
                this.verifyCode = '';
            },

            async copySecret() {
                try {
                    await navigator.clipboard.writeText(this.secret);
                    this.$dispatch('toast', { type: 'success', text: 'Key copied to clipboard.' });
                } catch (e) {
                    this.$dispatch('toast', { type: 'error', text: 'Could not copy — copy it manually.' });
                }
            },

            async verifySetup() {
                this.verifying = true;
                const json = await postForm('/account/2fa/verify', { code: this.verifyCode });
                this.verifying = false;
                if (!json) return;
                if (json.status === 'success') {
                    this.method = 'google2fa';
                    this.cancelSetup();
                    this.$dispatch('toast', { type: 'success', text: json.message });
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Invalid code.' });
                }
            },

            confirmDisable() {
                if (!this.disableCode && !this.disablePassword) {
                    this.$dispatch('toast', { type: 'error', text: 'Enter your current code or account password.' });
                    return;
                }
                this.$dispatch('confirm', {
                    title: 'Disable two-factor authentication',
                    body: 'Your account will only require a password to sign in. This cannot be undone without re-enrolling.',
                    variant: 'danger',
                    confirmLabel: 'Disable',
                    onConfirm: () => this.doDisable(),
                });
            },

            async doDisable() {
                this.disabling = true;
                const json = await postForm('/account/2fa/disable', { code: this.disableCode, password: this.disablePassword });
                this.disabling = false;
                if (!json) return;
                if (json.status === 'success') {
                    this.method = 'none';
                    this.disableCode = '';
                    this.disablePassword = '';
                    this.$dispatch('toast', { type: 'success', text: json.message });
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Could not disable 2FA.' });
                }
            },
        };
    }
</script>

<?php $this->endSection() ?>
