<?php

namespace App\Database\Seeds;

use App\Models\RoleModel;
use App\Libraries\PasswordHasher;
use App\Models\UserModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Creates the first admin so a fresh copy of this starter can be logged
 * into without manual SQL. Idempotent (skips if the target email already
 * exists) and env-configurable — safe to run on any environment, including
 * repeatedly.
 *
 * Env keys: `app.admin.email` (default 'admin@example.com'),
 * `app.admin.password` (default 'admin1234' — change it immediately).
 *
 * Runnable via `php spark db:seed AdminUserSeeder`.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) (env('app.admin.email') ?: 'admin@example.com');

        $userModel = model(UserModel::class);

        if ($userModel->withDeleted()->where('email', $email)->first()) {
            CLI::write("Admin user '{$email}' already exists — skipping.", 'yellow');

            return;
        }

        $password   = (string) (env('app.admin.password') ?: 'admin1234');
        $adminRole  = model(RoleModel::class)->findByName('admin');

        $userModel->insert([
            'name'              => 'Administrator',
            'email'             => $email,
            'password'          => (new PasswordHasher())->hash($password),
            'zone'              => 'admin',
            'status'            => 1,
            'two_factor_method' => 'none',
            'role_id'           => $adminRole->id ?? null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        if ($adminRole === null) {
            CLI::write('Note: the "admin" role is not seeded yet — created with role_id = null.', 'yellow');
        }

        CLI::write("Admin user created: {$email}", 'green');
        CLI::write('CHANGE THIS PASSWORD IMMEDIATELY.', 'red');
    }
}
