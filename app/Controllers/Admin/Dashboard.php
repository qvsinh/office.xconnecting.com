<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;
use App\Models\UserModel;

/**
 * Admin dashboard: at-a-glance counts across all users — API keys (tokens),
 * users, and API calls broken down by environment/outcome, plus service usage.
 *
 * Env encoding: keys use `user_status` (1 = LIVE, 0 = TESTING); users use the
 * `env` column ('LIVE'/'TESTING'). API-call outcome uses `api_history.status`
 * (1 = success, 0 = failed). "Services in use" = distinct services that have at
 * least one key in that environment; "services total" = configured services.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        helper('service_config');

        $keyModel     = model(ApiAccessKeyModel::class);
        $userModel    = model(UserModel::class);
        $historyModel = model(ApiHistoryModel::class);

        $since = date('Y-m-d H:i:s', time() - 86400);

        return view('admin/dashboard', [
            'user' => session('userWorking'),

            // Tokens (API keys) by environment.
            'tokensTotal'   => $keyModel->countAdmin([]),
            'tokensLive'    => $keyModel->countByEnv(1),
            'tokensTesting' => $keyModel->countByEnv(0),

            // Users by environment.
            'usersTotal'   => $userModel->countAdmin([]),
            'usersLive'    => $userModel->countByEnv('LIVE'),
            'usersTesting' => $userModel->countByEnv('TESTING'),

            // API calls by outcome (logged history only).
            'apiSuccess'  => $historyModel->countHistory(['status' => 1]),
            'apiFailed'   => $historyModel->countHistory(['status' => 0]),
            'apiCalls24h' => $historyModel->countSince($since),

            // Services: total configured, and distinct services in use per env.
            'servicesTotal'   => count(list_api_services()),
            'servicesLive'    => $keyModel->distinctServiceCountByEnv(1),
            'servicesTesting' => $keyModel->distinctServiceCountByEnv(0),
        ]);
    }
}
