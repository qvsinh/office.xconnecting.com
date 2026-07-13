<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;

/**
 * Client dashboard shell (placeholder cards only). Real widgets are a later
 * card — see kanban plan §2 backlog.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        return view('client/dashboard', [
            'user' => session('userWorking'),
        ]);
    }
}
