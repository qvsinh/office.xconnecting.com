<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Usage: `['filter' => 'permission:users.manage']`. Requires an
 * authenticated user whose role grants the given permission name (see
 * `user_can()` in `app/Helpers/auth_helper.php`) — an admin-zone user always
 * passes. Opt-in: not wired to any route yet, available for new apps to add
 * per-route.
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('auth');

        if (! is_authenticated()) {
            return redirect()->to('/login');
        }

        $permission = $arguments[0] ?? null;

        if ($permission !== null && user_can($permission)) {
            return null;
        }

        if ($request instanceof IncomingRequest && $request->isAJAX()) {
            return service('response')->setJSON(['error' => 'Forbidden'])->setStatusCode(403);
        }

        return redirect()->to('/dashboard');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
