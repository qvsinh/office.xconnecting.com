<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Global site gate, ported from the legacy `BaseController::initController`
 * `basic_auth` check. Sits in front of the app login: when
 * `app.basic.auth.username` is set, every browser request must pass a
 * username/password wall (session flag `basicAuthAuthenticated`) before
 * reaching any route. Registered as a global before-filter in
 * `Config\Filters` with `except => ['basic-auth', 'api/*']` so the gate
 * route itself and Bearer `api/*` clients are never redirected.
 */
class BasicAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (empty(getenv('app.basic.auth.username'))) {
            return null;
        }

        // Belt-and-suspenders: the `except` list in Config\Filters already
        // spares this path, but guard here too in case of routing changes.
        if (trim($request->getUri()->getPath(), '/') === 'basic-auth') {
            return null;
        }

        if (session('basicAuthAuthenticated')) {
            return null;
        }

        return redirect()->to('/basic-auth');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
