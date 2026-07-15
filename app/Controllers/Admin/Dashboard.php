<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;
use App\Models\UserModel;

/**
 * Admin dashboard: at-a-glance counts across all users (total API keys,
 * API calls in the last 24h, active users).
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        $since = date('Y-m-d H:i:s', time() - 86400);

        return view('admin/dashboard', [
            'user'        => session('userWorking'),
            'totalKeys'   => model(ApiAccessKeyModel::class)->countAdmin([]),
            'apiCalls24h' => model(ApiHistoryModel::class)->countSince($since),
            'activeUsers' => model(UserModel::class)->countAdmin(['status' => 1]),
        ]);
    }
}
