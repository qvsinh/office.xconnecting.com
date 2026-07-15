<?php

namespace App\Controllers\Client;

use App\Controllers\CnrConsoleController;
use App\Models\ApiAccessKeyModel;

/**
 * Self-service CNR developer console, scoped to the session user's own
 * enabled keys. `ConsoleController::run()` enforces ownership via
 * `requireOwnKey()` below — a foreign `key_id` 404s, no IDOR.
 */
class ConsoleCnr extends CnrConsoleController
{
    protected function requireOwnKey(): bool
    {
        return true;
    }

    public function index(): string
    {
        $me = session('userWorking');

        return view('client/api_console/cnr/index', [
            'keys'     => model(ApiAccessKeyModel::class)->listForConsole((int) $me->id, 'cnr'),
            'commands' => console_commands('cnr'),
        ]);
    }
}
