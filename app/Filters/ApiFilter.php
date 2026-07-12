<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Applied to the `api` route group. These endpoints are Bearer-token
 * authenticated (see ApiController) and stateless — no session/cookie
 * should persist across calls.
 */
class ApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // no-op: auth is handled by ApiController::checkUserAccess()
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        session()->destroy();
    }
}
