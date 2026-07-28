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

            <?= view('_password_reveal', ['shown' => $shown, 'controls' => $field]) ?>
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
