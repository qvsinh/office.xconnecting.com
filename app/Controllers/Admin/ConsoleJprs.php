<?php

namespace App\Controllers\Admin;

use App\Controllers\JprsConsoleController;
use App\Models\ApiAccessKeyModel;

/**
 * Admin JPRS developer console. Any user's enabled-or-disabled key may be
 * selected; `run()` still always derives identity from the resolved key row
 * (see `ConsoleController::run()`), never from request input.
 */
class ConsoleJprs extends JprsConsoleController
{
    protected function requireOwnKey(): bool
    {
        return false;
    }

    public function index(): string
    {
        return view('admin/api_console/jprs/index', [
            'keys'     => model(ApiAccessKeyModel::class)->listForConsole(null, 'jprs'),
            'commands' => console_commands('jprs'),
        ]);
    }
}
