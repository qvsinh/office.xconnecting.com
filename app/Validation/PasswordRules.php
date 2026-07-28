<?php

namespace App\Validation;

/**
 * `strong_password` — the one definition of what an acceptable password is.
 *
 * Registered in `Config\Validation::$ruleSets`, so every path that *sets* a
 * password uses the same rule: the account change-password form, the admin
 * create/edit-user form, and the `user:password` / `user:reset-password` CLI
 * commands. A second copy of these rules would drift the first time one of them
 * is relaxed.
 *
 * Deliberately NOT applied to **login** (`Auth::login`) or the basic-auth gate:
 * tightening the rule there would lock out every existing account whose password
 * predates it. Existing passwords stay valid until their owner changes them.
 *
 * To relax or tighten the policy, edit `REQUIREMENTS` — the form note and the
 * error messages are both generated from it, so they cannot fall out of step
 * with what is actually enforced.
 */
class PasswordRules
{
    public const MIN_LENGTH = 8;

    /**
     * Upper bound so an absurd input can't be pushed through hashing. Well above
     * any real password.
     */
    public const MAX_LENGTH = 128;

    /**
     * The characters that count as "special". Anything that is not a letter or a
     * digit, spelled out so the form can show the user exactly what qualifies.
     */
    public const SPECIAL_CHARS = '!@#$%^&*()-_=+[]{};:\'",.<>/?\\|`~';

    /**
     * `label => pattern`, in the order they are shown to the user. One list
     * drives the checker, the error message and the form note.
     *
     * @var array<string, string>
     */
    public const REQUIREMENTS = [
        'at least one lowercase letter' => '/[a-z]/',
        'at least one uppercase letter' => '/[A-Z]/',
        'at least one number'           => '/[0-9]/',
        'at least one special character (e.g. ! @ # $ % ^ & *)' => '/[^A-Za-z0-9]/',
    ];

    /**
     * Human-readable policy, for the form note and the docs. Generated from the
     * constants above so the note can never promise a rule that isn't enforced.
     *
     * @return list<string>
     */
    public static function describe(): array
    {
        return array_merge(
            ['at least ' . self::MIN_LENGTH . ' characters'],
            array_keys(self::REQUIREMENTS),
        );
    }

    /**
     * A random password that satisfies the policy above.
     *
     * Lives here, next to the rule, because a generator that produces something
     * the rule would reject is a contradiction the system should not be able to
     * express — `bin2hex(random_bytes(8))` (the previous generator) is
     * hex-only, so it has no uppercase and no special character.
     *
     * Uses `random_int()` (CSPRNG) throughout, then shuffles so the guaranteed
     * one-of-each characters are not always in the same positions.
     */
    public static function generate(int $length = 16): string
    {
        $length = max($length, self::MIN_LENGTH);

        $pools = [
            'abcdefghijkmnopqrstuvwxyz',   // no 'l'
            'ABCDEFGHJKLMNPQRSTUVWXYZ',    // no 'I', 'O'
            '23456789',                    // no '0', '1'
            '!@#$%^&*-_=+?',
        ];

        $chars = [];

        // One from each pool first, so the result always satisfies every
        // requirement rather than relying on luck.
        foreach ($pools as $pool) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        $all = implode('', $pools);

        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates with a CSPRNG — shuffle() is not cryptographically secure.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * The validation rule itself.
     *
     * Reports *which* requirement failed rather than a generic "invalid
     * password" — a user who cannot see what is wrong just tries variations of
     * the same rejected password.
     */
    public function strong_password(?string $str, ?string &$error = null): bool
    {
        $password = (string) $str;

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $error = 'The password must be at least ' . self::MIN_LENGTH . ' characters long.';

            return false;
        }

        if (mb_strlen($password) > self::MAX_LENGTH) {
            $error = 'The password must not be longer than ' . self::MAX_LENGTH . ' characters.';

            return false;
        }

        $missing = [];

        foreach (self::REQUIREMENTS as $label => $pattern) {
            if (preg_match($pattern, $password) !== 1) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            $error = 'The password needs ' . implode(', ', $missing) . '.';

            return false;
        }

        return true;
    }
}
