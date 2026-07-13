<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Admin dashboard shell (placeholder cards only). Real widgets are a later
 * card — see kanban plan §2 backlog.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        return view('admin/dashboard', [
            'user' => session('userWorking'),
        ]);
    }
}
