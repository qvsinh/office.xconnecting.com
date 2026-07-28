<?php

namespace App\Commands;

use App\Libraries\PasswordHasher;
use App\Models\UserModel;
use App\Validation\PasswordRules;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark user:password <email> <password>` — sets a legacy-compatible
 * md5 password hash for an existing, active (non-soft-deleted) user.
 */
class UserPassword extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'user:password';
    protected $description = "Set a user's password (md5 hash) by email.";
    protected $usage       = 'user:password <email> <password>';
    protected $arguments   = [
        'email'    => "The user's email address",
        'password' => 'The new plain-text password',
    ];

    public function run(array $params)
    {
        $email    = $params[0] ?? null;
        $password = $params[1] ?? null;

        if (! $email || ! $password) {
            CLI::error('Usage: php spark user:password <email> <password>');

            return;
        }

        $userModel = model(UserModel::class);
        $user      = $userModel->findActiveByEmail((string) $email);

        if (! $user) {
            CLI::error("No active user found with email: {$email}");

            return;
        }

        // Same policy as the web forms — see App\Validation\PasswordRules.
        $error = null;

        if (! (new PasswordRules())->strong_password((string) $password, $error)) {
            CLI::error($error ?? 'Password does not meet the policy.');
            CLI::write('Required: ' . implode('; ', PasswordRules::describe()) . '.', 'yellow');

            return;
        }

        $hash = (new PasswordHasher())->hash($password);

        $userModel->update($user->id, ['password' => $hash]);

        CLI::write("Password updated for {$email}", 'green');
    }
}
