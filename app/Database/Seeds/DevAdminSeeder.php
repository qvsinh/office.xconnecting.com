<?php

namespace App\Database\Seeds;

use App\Models\UserModel;
use CodeIgniter\Database\Seeder;

/**
 * Ensures a known dev admin exists so the app is loginable out of the box.
 * Non-destructive: only inserts if the email is entirely absent (including
 * soft-deleted rows, to avoid a unique-key collision on re-seed) — never
 * overwrites an existing account. Dev/test only; do not run on prod.
 */
class DevAdminSeeder extends Seeder
{
    private const EMAIL = 'admin@xconnecting.com';

    public function run(): void
    {
        $userModel = model(UserModel::class);

        if ($userModel->withDeleted()->where('email', self::EMAIL)->first()) {
            return;
        }

        $authConfig = config('Auth');

        $userModel->insert([
            'name'              => 'Dev Admin',
            'email'             => self::EMAIL,
            'password'          => hash($authConfig->password_algorithm, 'Proxy@2026!' . $authConfig->password_salt),
            'zone'              => 'admin',
            'status'            => 1,
            'two_factor_method' => 'none',
            'language'          => 'en',
        ]);
    }
}
