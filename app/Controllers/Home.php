<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Home extends BaseController
{
    /**
     * Public entry point. Authenticated users are routed straight to their
     * console; guests see the branded landing page. (The basic-auth site gate,
     * when enabled, runs as a global before-filter ahead of this.)
     */
    public function index(): RedirectResponse|string
    {
        helper('auth');

        if (is_authenticated()) {
            return redirect()->to(is_admin() ? '/admin/dashboard' : '/dashboard');
        }

        return view('home/landing');
    }
}
