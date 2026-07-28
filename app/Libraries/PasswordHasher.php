<?php

namespace App\Libraries;

use RuntimeException;

/**
 * The one place a password is turned into a stored hash, or checked against one.
 *
 * Replaces seven copies of `hash($algorithm, $plain . $salt)` — an **unsalted
 * MD5** digest inherited from the starter. MD5 has no per-password salt and no
 * work factor, so a leaked `users` table was crackable with public rainbow
 * tables. `password_hash()` gives every password its own random salt and a cost
 * that can be raised as hardware gets faster.
 *
 * Configured through `Config\Auth`, so it is `.env`-driven:
 *
 *   auth.password_algorithm = 'bcrypt'   # bcrypt | argon2i | argon2id | default
 *   auth.password_cost      = 12         # bcrypt only; argon2 uses PHP defaults
 *
 * ## Existing passwords keep working
 *
 * `verify()` recognises the legacy digest and accepts it, then `needsRehash()`
 * reports that the row is stale. `Auth::login()` rehashes on the spot, so every
 * account upgrades itself the next time its owner signs in — no forced reset, no
 * migration that would need the plaintext nobody has.
 *
 * The legacy settings exist for verification only and are never used to create a
 * hash. Once `SELECT COUNT(*) FROM users WHERE password NOT LIKE '$%'` is zero,
 * they can be deleted along with `legacyHash()`.
 */
class PasswordHasher
{
    /**
     * Accepted `auth.password_algorithm` values.
     *
     * `default` follows whatever PHP considers strongest, which means an upgrade
     * of PHP can change the stored format — fine, because `needsRehash()` then
     * migrates rows on login.
     */
    private const ALGORITHMS = [
        'default'  => PASSWORD_DEFAULT,
        'bcrypt'   => PASSWORD_BCRYPT,
        'argon2i'  => PASSWORD_ARGON2I,
        'argon2id' => PASSWORD_ARGON2ID,
    ];

    private string $algorithm;

    /**
     * @var array<string, int>
     */
    private array $options;

    private string $legacyAlgorithm;
    private string $legacySalt;

    public function __construct(?object $config = null)
    {
        $config ??= config('Auth');

        $name = strtolower(trim((string) $config->password_algorithm));

        if (! isset(self::ALGORITHMS[$name])) {
            throw new RuntimeException(
                "Unknown auth.password_algorithm '{$name}'. Use one of: " . implode(', ', array_keys(self::ALGORITHMS)) . '.',
            );
        }

        $this->algorithm = self::ALGORITHMS[$name];

        // Fail loudly at construction rather than at the first login: an argon2
        // build that PHP does not support would otherwise surface as a fatal
        // inside password_hash() on the login request itself.
        if (! in_array($this->algorithm, password_algos(), true)) {
            throw new RuntimeException("auth.password_algorithm '{$name}' is not supported by this PHP build.");
        }

        // Cost applies to bcrypt only; argon2 takes memory/time/threads instead,
        // and passing `cost` to it is ignored. Clamped to bcrypt's valid range so
        // a typo in .env cannot make every hash attempt throw.
        $this->options = $this->algorithm === PASSWORD_BCRYPT
            ? ['cost' => max(4, min(31, (int) $config->password_cost))]
            : [];

        $this->legacyAlgorithm = (string) ($config->legacy_algorithm ?? '');
        $this->legacySalt      = (string) ($config->legacy_salt ?? '');
    }

    /**
     * The hash to store. Never reversible, and never the same twice for the same
     * password — the salt is generated per call and embedded in the result.
     */
    public function hash(string $plain): string
    {
        return password_hash($plain, $this->algorithm, $this->options);
    }

    /**
     * Does `$plain` match `$stored`?
     *
     * Handles both formats so a half-migrated table logs in normally. Both paths
     * are constant-time comparisons: `password_verify()` internally, and
     * `hash_equals()` for the legacy digest.
     */
    public function verify(string $plain, ?string $stored): bool
    {
        $stored = (string) $stored;

        if ($stored === '' || $plain === '') {
            return false;
        }

        if ($this->isPhpHash($stored)) {
            return password_verify($plain, $stored);
        }

        return $this->legacyAlgorithm !== '' && hash_equals($stored, $this->legacyHash($plain));
    }

    /**
     * Should this row be rewritten with a fresh hash?
     *
     * True for a legacy digest, and true when the configured algorithm or cost
     * has moved on since the row was written. Only meaningful straight after a
     * successful `verify()` — that is the one moment the plaintext is available.
     */
    public function needsRehash(?string $stored): bool
    {
        $stored = (string) $stored;

        if ($stored === '' || ! $this->isPhpHash($stored)) {
            return true;
        }

        return password_needs_rehash($stored, $this->algorithm, $this->options);
    }

    /**
     * Is this one of PHP's own hashes (`$2y$…`, `$argon2id$…`) rather than a bare
     * legacy digest? `algoName` is `'unknown'` for anything password_hash() did
     * not produce.
     */
    private function isPhpHash(string $stored): bool
    {
        return (password_get_info($stored)['algoName'] ?? 'unknown') !== 'unknown';
    }

    /**
     * The pre-migration digest — verification only, never for new passwords.
     */
    private function legacyHash(string $plain): string
    {
        return hash($this->legacyAlgorithm, $plain . $this->legacySalt);
    }
}
