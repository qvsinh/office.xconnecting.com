<?php

use App\Validation\PasswordRules;

/**
 * Password input for the create/edit user modals: reveal toggle, Generate, Copy.
 *
 * One partial for both modals so the markup, the accessibility wiring and the
 * policy attributes cannot drift between them.
 *
 * Generate calls the server (`Admin\Users::generatePassword`) rather than rolling
 * a password in JavaScript — the policy lives in `PasswordRules` only.
 *
 * Revealing is the point here, not a lapse: an admin creating an account has to
 * read the password to hand it over. It is only ever a password the admin has
 * just typed or generated, never one loaded from the database.
 *
 * @var string $prefix 'create' or 'edit' — names the ids and the Alpine model
 * @var string $hint   what a blank field means in this modal
 */
$field = $prefix . '_password';
$model = $prefix . 'Form.password';
$shown = $prefix . 'PasswordShown';
$notes = $prefix . '-password-requirements';
?>
<div>
    <label for="<?= esc($field, 'attr') ?>" class="form-label">Password</label>

    <div class="flex items-start gap-2">
        <div class="relative flex-1">
            <input id="<?= esc($field, 'attr') ?>"
                   :type="<?= esc($shown, 'attr') ?> ? 'text' : 'password'"
                   x-model="<?= esc($model, 'attr') ?>"
                   minlength="<?= esc((string) PasswordRules::MIN_LENGTH, 'attr') ?>"
                   maxlength="<?= esc((string) PasswordRules::MAX_LENGTH, 'attr') ?>"
                   autocomplete="new-password"
                   aria-describedby="<?= esc($notes, 'attr') ?>"
                   class="form-input pr-10">

            <button type="button"
                    @click="<?= esc($shown, 'attr') ?> = ! <?= esc($shown, 'attr') ?>"
                    :aria-label="<?= esc($shown, 'attr') ?> ? 'Hide password' : 'Show password'"
                    :aria-pressed="<?= esc($shown, 'attr') ?> ? 'true' : 'false'"
                    class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-gray-400 transition hover:text-[#111111] focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:text-gray-200">
                <svg x-show="!<?= esc($shown, 'attr') ?>" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                <svg x-show="<?= esc($shown, 'attr') ?>" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M6.5 6.9C4.2 8.5 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.5 0 2.8-.4 4-1M17.9 15.1c2-1.6 3.6-4.1 3.6-4.1S18 5.5 12 5.5c-.6 0-1.2 0-1.7.1" />
                </svg>
            </button>
        </div>

        <button type="button" class="btn-secondary whitespace-nowrap"
                @click="fillGeneratedPassword('<?= esc($prefix, 'attr') ?>')"
                :disabled="generatingPassword">
            <span x-show="!generatingPassword">Generate</span>
            <span x-show="generatingPassword" x-cloak>…</span>
        </button>
    </div>

    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">
        <span x-show="!<?= esc($model, 'attr') ?>"><?= esc($hint) ?></span>
        <button type="button" x-show="<?= esc($model, 'attr') ?>" x-cloak
                @click="copyPassword(<?= esc($model, 'attr') ?>)"
                class="font-medium text-brand-500 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-500">
            Copy password
        </button>
    </p>

    <?= view('account/_password_note', ['id' => $notes]) ?>
</div>
