<?php
/**
 * Self-service profile: edit own name/company/phone/language/timezone, and
 * change own password. Both forms post via postForm() (shared in
 * layouts/app) to Account::updateProfile() / Account::changePassword().
 * Email is read-only (not part of the update contract). No secret/hash is
 * ever rendered here.
 *
 * @var array{id:int,name:string,email:string,company:?string,phone_number:?string,language:string,timezone:string,two_factor_method:string} $user
 */
use App\Validation\PasswordRules;

$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-3xl" x-data="accountProfile()">

    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100"><?= esc(lang('account.title')) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><?= esc(lang('account.intro')) ?></p>
        </div>
        <a href="/account/security" class="text-sm font-medium text-[#111111] hover:underline dark:text-gray-100"><?= lang('account.security_link') ?></a>
    </div>

    <!-- Profile card -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-base font-semibold dark:text-gray-100"><?= esc(lang('account.profile')) ?></h2>
        <form @submit.prevent="submitProfile" class="mt-4 space-y-4">
            <div>
                <label class="form-label"><?= esc(lang('account.email')) ?></label>
                <input type="email" value="<?= esc($user['email'], 'attr') ?>" disabled
                       class="form-input cursor-not-allowed bg-gray-50 dark:bg-gray-900/40">
            </div>
            <div>
                <label for="name" class="form-label"><?= esc(lang('account.name')) ?></label>
                <input id="name" name="name" type="text" required maxlength="255"
                       x-model="form.name" class="form-input">
            </div>
            <div>
                <label for="company" class="form-label"><?= esc(lang('account.company')) ?></label>
                <input id="company" name="company" type="text" maxlength="255"
                       x-model="form.company" class="form-input">
            </div>
            <div>
                <label for="phone_number" class="form-label"><?= esc(lang('account.phone')) ?></label>
                <input id="phone_number" name="phone_number" type="text" maxlength="20"
                       x-model="form.phone_number" class="form-input">
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="language" class="form-label"><?= esc(lang('account.language')) ?></label>
                    <input id="language" name="language" type="text" maxlength="5" placeholder="en"
                           x-model="form.language" class="form-input">
                </div>
                <div>
                    <label for="timezone" class="form-label">Timezone</label>
                    <input id="timezone" name="timezone" type="text" maxlength="30" placeholder="Asia/Ho_Chi_Minh"
                           x-model="form.timezone" class="form-input">
                </div>
            </div>
            <div class="flex justify-end pt-2">
                <button type="submit" :disabled="savingProfile" class="btn-primary">
                    <span x-show="!savingProfile"><?= esc(lang('account.save_profile')) ?></span>
                    <span x-show="savingProfile" x-cloak><?= esc(lang('common.saving')) ?></span>
                </button>
            </div>
        </form>
    </div>

    <!-- Change password card -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-base font-semibold dark:text-gray-100"><?= esc(lang('account.change_password')) ?></h2>
        <form @submit.prevent="submitPassword" class="mt-4 space-y-4">
            <div>
                <label for="current_password" class="form-label"><?= esc(lang('account.current_password')) ?></label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                       x-model="passwordForm.current_password" class="form-input">
            </div>
            <div>
                <label for="password" class="form-label"><?= esc(lang('account.new_password')) ?></label>
                <input id="password" name="password" type="password" required
                       minlength="<?= esc((string) PasswordRules::MIN_LENGTH, 'attr') ?>"
                       maxlength="<?= esc((string) PasswordRules::MAX_LENGTH, 'attr') ?>"
                       autocomplete="new-password" aria-describedby="password-requirements"
                       x-model="passwordForm.password" class="form-input">
                <?= view('account/_password_note', ['id' => 'password-requirements']) ?>
            </div>
            <div>
                <label for="password_confirm" class="form-label"><?= esc(lang('account.confirm_password')) ?></label>
                <input id="password_confirm" name="password_confirm" type="password" required
                       minlength="<?= esc((string) PasswordRules::MIN_LENGTH, 'attr') ?>"
                       autocomplete="new-password"
                       x-model="passwordForm.password_confirm" class="form-input">
                <p x-show="passwordMismatch" x-cloak class="mt-1 text-xs text-red-700 dark:text-red-400">Passwords do not match.</p>
            </div>
            <div class="flex justify-end pt-2">
                <button type="submit" :disabled="savingPassword" class="btn-primary">
                    <span x-show="!savingPassword">Change password</span>
                    <span x-show="savingPassword" x-cloak><?= esc(lang('account.changing')) ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function accountProfile() {
        return {
            form: {
                name: <?= json_encode($user['name']) ?>,
                company: <?= json_encode($user['company'] ?? '') ?>,
                phone_number: <?= json_encode($user['phone_number'] ?? '') ?>,
                language: <?= json_encode($user['language']) ?>,
                timezone: <?= json_encode($user['timezone']) ?>,
            },
            passwordForm: { current_password: '', password: '', password_confirm: '' },
            savingProfile: false,
            savingPassword: false,

            get passwordMismatch() {
                return this.passwordForm.password_confirm !== '' && this.passwordForm.password !== this.passwordForm.password_confirm;
            },

            async submitProfile() {
                this.savingProfile = true;
                const json = await postForm('/account/profile', this.form);
                this.savingProfile = false;
                if (!json) return;
                if (json.status === 'success') {
                    this.$dispatch('toast', { type: 'success', text: json.message });
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update profile.' });
                }
            },

            async submitPassword() {
                if (this.passwordMismatch) {
                    this.$dispatch('toast', { type: 'error', text: <?= json_encode(lang('account.mismatch'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> });
                    return;
                }
                this.savingPassword = true;
                const json = await postForm('/account/change-password', this.passwordForm);
                this.savingPassword = false;
                if (!json) return;
                if (json.status === 'success') {
                    this.$dispatch('toast', { type: 'success', text: json.message });
                    this.passwordForm = { current_password: '', password: '', password_confirm: '' };
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Could not change password.' });
                }
            },
        };
    }
</script>

<?php $this->endSection() ?>
