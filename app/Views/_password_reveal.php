<?php

/**
 * The show/hide button that sits inside a password input.
 *
 * Renders only the button, so each caller keeps control of its own layout — the
 * login card puts it in a plain field, the admin user modal puts it in a flex row
 * next to "Generate". What is shared is the part worth having one copy of: the
 * two icons and the accessibility wiring.
 *
 * The caller is responsible for three things:
 *   1. a `relative` wrapper around the input,
 *   2. `pr-10` on the input so its text does not run under this button,
 *   3. binding the input's type: `:type="<shown> ? 'text' : 'password'"`.
 *
 * @var string      $shown    Alpine boolean expression holding the reveal state
 * @var string|null $controls id of the input this toggles (for `aria-controls`)
 */
$shown ??= 'passwordShown';
$controls ??= null;
?>
<button type="button"
        @click="<?= esc($shown, 'attr') ?> = ! <?= esc($shown, 'attr') ?>"
        :aria-label="<?= esc($shown, 'attr') ?> ? 'Hide password' : 'Show password'"
        :aria-pressed="<?= esc($shown, 'attr') ?> ? 'true' : 'false'"
        <?= $controls === null ? '' : 'aria-controls="' . esc($controls, 'attr') . '"' ?>
        class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-gray-400 transition hover:text-[#111111] focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:text-gray-200">
    <svg x-show="!<?= esc($shown, 'attr') ?>" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
        <circle cx="12" cy="12" r="3" />
    </svg>
    <svg x-show="<?= esc($shown, 'attr') ?>" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M6.5 6.9C4.2 8.5 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.5 0 2.8-.4 4-1M17.9 15.1c2-1.6 3.6-4.1 3.6-4.1S18 5.5 12 5.5c-.6 0-1.2 0-1.7.1" />
    </svg>
</button>
