<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Password storage settings, read by `App\Libraries\PasswordHasher` — the only
 * class that hashes or verifies a password.
 *
 * Every value is overridable from `.env` (CI4 maps `auth.<property>`):
 *
 *   auth.password_algorithm = 'bcrypt'
 *   auth.password_cost      = 12
 *
 * Passwords used to be stored as an unsalted MD5 digest, ported from
 * ../proxy.services.vn. The `legacy_*` values below exist so those rows still
 * verify; `Auth::login()` rewrites each one with a modern hash the next time its
 * owner signs in. See DEPLOYMENT.md §0.2.
 */
class Auth extends BaseConfig
{
    /**
     * How new passwords are hashed: `bcrypt`, `argon2i`, `argon2id`, or
     * `default` (whatever PHP currently considers strongest).
     *
     * bcrypt is the default here because it is available on every PHP build,
     * needs no libargon2, and has a single tuning knob. argon2id is a fine
     * choice if the host supports it.
     */
    public string $password_algorithm = 'bcrypt';

    /**
     * bcrypt work factor. Each +1 doubles the time to hash *and* to crack.
     *
     * 12 is roughly 200-300 ms on a small instance — slow enough to make offline
     * cracking expensive, fast enough that nobody notices a login. Raise it as
     * hardware improves; existing rows migrate themselves on next login because
     * `password_needs_rehash()` notices the change.
     *
     * Ignored for argon2, which is tuned with memory/time/threads instead.
     */
    public int $password_cost = 12;

    /**
     * The pre-migration digest, used **only** to verify old rows — never to
     * create a hash. Set `legacy_algorithm` to an empty string to refuse legacy
     * hashes outright, once every row has been migrated.
     */
    public string $legacy_algorithm = 'md5';

    public string $legacy_salt = '';
}
