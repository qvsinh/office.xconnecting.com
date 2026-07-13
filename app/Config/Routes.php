<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Login is guest-only; CSRF is scoped to this web POST only, never to
// the stateless `api` group below (its ApiFilter::after() destroys the
// session, so a session-based CSRF token would never survive there).
$routes->group('', ['filter' => 'guestFilter'], static function (RouteCollection $routes) {
    $routes->get('login', 'Auth::showLogin');
    $routes->post('login', 'Auth::login', ['filter' => 'csrf']);
});

$routes->get('logout', 'Auth::logout', ['filter' => 'authFilter']);

$routes->group('admin', ['filter' => ['authFilter', 'adminFilter']], static function (RouteCollection $routes) {
    $routes->get('dashboard', 'Admin\Dashboard::index');
});

$routes->group('', ['filter' => 'authFilter'], static function (RouteCollection $routes) {
    $routes->get('dashboard', 'Client\Dashboard::index');
});

$routes->group('api', ['filter' => 'apiFilter'], static function (RouteCollection $routes) {
    $routes->get('cnr/(:any)', 'Api\CnrApi::proxy');
    $routes->match(['get', 'post'], 'jprs/(:any)', 'Api\JprsApi::proxy');
});
