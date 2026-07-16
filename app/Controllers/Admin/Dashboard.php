<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserModel;

/**
 * Admin dashboard: at-a-glance user counts.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        return view('admin/dashboard', [
            'user'        => session('userWorking'),
            'usersTotal'  => model(UserModel::class)->countAdmin([]),
            'usersActive' => model(UserModel::class)->countAdmin(['status' => 1]),
        ]);
    }
}
