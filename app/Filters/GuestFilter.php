<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Applied to the login routes: redirects an already-authenticated user to
 * their dashboard instead of showing the login form again.
 */
class GuestFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('auth');

        if (is_authenticated()) {
            return redirect()->to(is_admin() ? '/admin/dashboard' : '/dashboard');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
