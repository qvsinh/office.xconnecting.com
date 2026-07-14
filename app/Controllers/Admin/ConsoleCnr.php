<?php

namespace App\Controllers\Admin;

use App\Controllers\CnrConsoleController;
use App\Models\ApiAccessKeyModel;

/**
 * Admin CNR developer console. Any user's enabled-or-disabled key may be
 * selected; `run()` still always derives identity from the resolved key row
 * (see `ConsoleController::run()`), never from request input.
 */
class ConsoleCnr extends CnrConsoleController
{
    protected function requireOwnKey(): bool
    {
        return false;
    }

    public function index(): string
    {
        return view('admin/api_console/cnr/index', [
            'keys' => model(ApiAccessKeyModel::class)->listForConsole(null, 'cnr'),
        ]);
    }
}
