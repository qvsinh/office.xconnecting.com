<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Legacy-compatible password hashing config, ported verbatim from
 * ../proxy.services.vn/app/Config/Auth.php so existing `users.password`
 * hashes (md5, no salt) keep working. Do not "upgrade" this without a
 * dedicated rehash migration card (see kanban plan §7).
 */
class Auth extends BaseConfig
{
    public string $password_algorithm = 'md5';
    public string $password_salt      = '';
}
