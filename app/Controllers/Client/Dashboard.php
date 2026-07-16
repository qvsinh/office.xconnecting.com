<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;

/**
 * Client dashboard: the signed-in user's own account summary.
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
