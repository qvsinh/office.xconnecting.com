<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark user:reset-password <id|email> [password]` — reset a user's
 * password from the CLI (e.g. locked-out admin, no working email flow yet).
 *
 * Looks the user up by numeric id or email (soft-deleted users excluded).
 * If the password argument is omitted it is prompted for (with confirmation).
 * Hashes exactly like `Auth::login()` (via `config('Auth')`) so the new
 * password verifies on the next login — the two must never drift.
 */
class ResetUserPassword extends BaseCommand
{
    protected $group       = 'Users';
    protected $name        = 'user:reset-password';
    protected $description = 'Reset a user password by id or email.';
    protected $usage       = 'user:reset-password <id|email> [password]';
    protected $arguments   = [
        'identifier' => 'Numeric user id or email address.',
        'password'   => '(optional) New password; omit to be prompted securely.',
    ];

    public function run(array $params)
    {
        $identifier = trim((string) ($params[0] ?? CLI::prompt('User id or email')));

        if ($identifier === '') {
            CLI::error('A user id or email is required.');

            return EXIT_ERROR;
        }

        $userModel = model(UserModel::class);
        // Both accessors return a typed ?object and exclude soft-deleted rows.
        $user = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? $userModel->findActiveByEmail($identifier)
            : $userModel->findUser((int) $identifier);

        if (! $user) {
            CLI::error("No user found for '{$identifier}'.");

            return EXIT_ERROR;
        }

        $password = (string) ($params[1] ?? '');

        if ($password === '') {
            $password = (string) CLI::prompt('New password', null, 'required|min_length[5]');
            if ($password !== (string) CLI::prompt('Confirm password', null, 'required')) {
                CLI::error('Passwords do not match.');

                return EXIT_ERROR;
            }
        } elseif (strlen($password) < 5) {
            CLI::error('Password must be at least 5 characters.');

            return EXIT_ERROR;
        }

        // Hash identically to Auth::login() so the reset password verifies.
        $authConfig = config('Auth');
        $hash       = hash($authConfig->password_algorithm, $password . $authConfig->password_salt);

        $userModel->update($user->id, ['password' => $hash]);

        CLI::write(CLI::color("Password updated for user #{$user->id} ({$user->email}).", 'green'));

        return EXIT_SUCCESS;
    }
}
