<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires an authenticated user with `zone === 'global'`. Stacked after
 * `authFilter` on the `admin` route group, but self-contained (redirects to
 * `/login` if not authenticated at all) in case it's ever used standalone.
 */
class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('auth');

        if (! is_authenticated()) {
            return redirect()->to('/login');
        }

        if (! is_admin()) {
            return redirect()->to('/dashboard');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
