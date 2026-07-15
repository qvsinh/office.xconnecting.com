<?php

namespace App\Controllers\Client;

use App\Controllers\JprsConsoleController;
use App\Models\ApiAccessKeyModel;

/**
 * Self-service JPRS developer console, scoped to the session user's own
 * enabled keys. `ConsoleController::run()` enforces ownership via
 * `requireOwnKey()` below — a foreign `key_id` 404s, no IDOR.
 */
class ConsoleJprs extends JprsConsoleController
{
    protected function requireOwnKey(): bool
    {
        return true;
    }

    public function index(): string
    {
        $me = session('userWorking');

        return view('client/api_console/jprs/index', [
            'keys'     => model(ApiAccessKeyModel::class)->listForConsole((int) $me->id, 'jprs'),
            'commands' => console_commands('jprs'),
        ]);
    }
}
