<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->group('api', ['filter' => 'apiFilter'], static function (RouteCollection $routes) {
    $routes->get('cnr/(:any)', 'Api\CnrApi::proxy');
    $routes->match(['get', 'post'], 'jprs/(:any)', 'Api\JprsApi::proxy');
});
