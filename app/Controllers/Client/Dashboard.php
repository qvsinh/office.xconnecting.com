<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;
use App\Models\ApiAccessKeyModel;
use App\Models\ApiHistoryModel;

/**
 * Client dashboard: the signed-in user's own counts (API keys used vs. cap,
 * their API calls in the last 24h) plus their account environment.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        $user  = session('userWorking');
        $uid   = (int) $user->id;
        $since = date('Y-m-d H:i:s', time() - 86400);

        return view('client/dashboard', [
            'user'          => $user,
            'myKeys'        => model(ApiAccessKeyModel::class)->countForUser($uid),
            'maxKeys'       => ApiAccessKeyModel::MAX_KEYS_PER_USER,
            'myApiCalls24h' => model(ApiHistoryModel::class)->countSince($since, $uid),
        ]);
    }
}
