<?php

namespace App\Controllers;

use App\Models\ApiAccessKeyModel;

/**
 * Shared API connection self-test flow behind both zones (admin/client):
 * lists the caller's testable keys (scoped by `requireOwnKey()`) so the
 * view can run the actual probe as a `fetch()` FROM THE CALLER'S OWN
 * BROWSER — real client IP + origin, so it also exercises the per-key IP
 * allowlist — straight against the public `/api/{provider}/{env}/` proxy
 * route. There is no server-side run step: the browser holds the token
 * (`listForConnectionTest()` now includes `access_key`) and calls the
 * existing authenticated proxy directly.
 */
abstract class ApiConnectionController extends BaseController
{
    /**
     * True for the client zone (caller may only test its own keys); false
     * for the admin zone (any key, identity is still always taken from the
     * row).
     */
    abstract protected function requireOwnKey(): bool;

    public function index(): string
    {
        $userId = $this->requireOwnKey() ? (int) session('userWorking')->id : null;

        return view('api_test/index', [
            'keys' => model(ApiAccessKeyModel::class)->listForConnectionTest($userId),
            'zone' => $this->requireOwnKey() ? 'client' : 'admin',
        ]);
    }
}
