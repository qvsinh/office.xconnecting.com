<?php
/**
 * The password policy, shown next to any field that sets one.
 *
 * Rendered from `PasswordRules::describe()` rather than hand-written, so the
 * note can never promise a rule the server doesn't enforce (or omit one it
 * does). Relaxing the policy updates this note automatically.
 *
 * Give the field `aria-describedby="<id>"` so screen readers announce the rules
 * with the input, not as loose text somewhere on the page.
 *
 * @var string $id  element id the input points at with aria-describedby
 */

use App\Validation\PasswordRules;

$id ??= 'password-requirements';
?>
<div id="<?= esc($id, 'attr') ?>" class="mt-1 text-xs text-[#787774] dark:text-gray-400">
    Must contain:
    <ul class="mt-1 list-inside list-disc space-y-0.5">
        <?php foreach (PasswordRules::describe() as $requirement) : ?>
            <li><?= esc($requirement) ?></li>
        <?php endforeach ?>
    </ul>
</div>
