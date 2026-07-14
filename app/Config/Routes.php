<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Site gate: precedes app login (BasicAuthFilter, registered as a global
// before-filter with `except => ['basic-auth', 'api/*']`), so these routes
// must not sit behind authFilter/guestFilter/adminFilter.
$routes->get('basic-auth', 'BasicAuth::index');
$routes->post('basic-auth', 'BasicAuth::authenticate', ['filter' => 'csrf']);

// Login is guest-only; CSRF is scoped to this web POST only, never to
// the stateless `api` group below (its ApiFilter::after() destroys the
// session, so a session-based CSRF token would never survive there).
$routes->group('', ['filter' => 'guestFilter'], static function (RouteCollection $routes) {
    $routes->get('login', 'Auth::showLogin');
    $routes->post('login', 'Auth::login', ['filter' => 'csrf']);
});

$routes->get('logout', 'Auth::logout', ['filter' => 'authFilter']);

// 2FA login challenge: the user isn't authenticated yet (only password-
// verified, pending a second factor), so this sits behind neither
// guestFilter (would let a pending user straight through, indistinguishable
// from a guest) nor authFilter (would just bounce to /login) — Auth itself
// guards on `pending2faUserId` (see Auth::showVerify2fa()/verify2fa()).
$routes->get('verify-2fa', 'Auth::showVerify2fa');
$routes->post('verify-2fa', 'Auth::verify2fa', ['filter' => 'csrf']);

$routes->group('admin', ['filter' => ['authFilter', 'adminFilter']], static function (RouteCollection $routes) {
    $routes->get('dashboard', 'Admin\Dashboard::index');

    $routes->get('api-access-keys', 'Admin\ApiAccessKeys::index');
    $routes->post('api-access-keys/create', 'Admin\ApiAccessKeys::create', ['filter' => 'csrf']);
    $routes->post('api-access-keys/update', 'Admin\ApiAccessKeys::update', ['filter' => 'csrf']);
    $routes->post('api-access-keys/toggle', 'Admin\ApiAccessKeys::toggle', ['filter' => 'csrf']);
    $routes->post('api-access-keys/toggle-log', 'Admin\ApiAccessKeys::toggleLog', ['filter' => 'csrf']);
    $routes->post('api-access-keys/delete', 'Admin\ApiAccessKeys::delete', ['filter' => 'csrf']);

    $routes->get('api-history', 'Admin\ApiHistory::logs');
    $routes->get('api-history/preview', 'Admin\ApiHistory::preview');
    $routes->post('api-history/delete', 'Admin\ApiHistory::delete', ['filter' => 'csrf']);
    $routes->post('api-history/delete-all', 'Admin\ApiHistory::deleteAll', ['filter' => 'csrf']);

    $routes->get('console/cnr', 'Admin\ConsoleCnr::index');
    $routes->post('console/cnr/run', 'Admin\ConsoleCnr::run', ['filter' => 'csrf']);
    $routes->get('console/jprs', 'Admin\ConsoleJprs::index');
    $routes->post('console/jprs/run', 'Admin\ConsoleJprs::run', ['filter' => 'csrf']);

    $routes->get('system', 'Admin\System::index');
    $routes->post('system/opcache/reset', 'Admin\System::resetOpcache', ['filter' => 'csrf']);
    $routes->get('system/migrations', 'Admin\System::migrations');
    $routes->get('system/logs', 'Admin\SystemLogs::index');
    $routes->get('system/logs/preview', 'Admin\SystemLogs::preview');
    $routes->get('system/logs/download', 'Admin\SystemLogs::download');
    $routes->post('system/logs/delete', 'Admin\SystemLogs::delete', ['filter' => 'csrf']);
    $routes->post('system/logs/delete-all', 'Admin\SystemLogs::deleteAll', ['filter' => 'csrf']);

    $routes->get('users', 'Admin\Users::index');
    $routes->post('users/create', 'Admin\Users::create', ['filter' => 'csrf']);
    $routes->get('users/edit/(:num)', 'Admin\Users::edit/$1');
    $routes->post('users/update', 'Admin\Users::update', ['filter' => 'csrf']);
    $routes->get('users/view/(:num)', 'Admin\Users::view/$1');
    $routes->get('users/note/(:num)', 'Admin\Users::note/$1');
    $routes->post('users/note', 'Admin\Users::updateNote', ['filter' => 'csrf']);
    $routes->post('users/act-as/(:num)', 'Admin\Users::actAs/$1', ['filter' => 'csrf']);
    $routes->post('users/stop-acting-as', 'Admin\Users::stopActing', ['filter' => 'csrf']);

    $routes->get('servers', 'Admin\Servers::index');
    $routes->post('servers/create', 'Admin\Servers::create', ['filter' => 'csrf']);
    $routes->get('servers/edit/(:num)', 'Admin\Servers::edit/$1');
    $routes->post('servers/update', 'Admin\Servers::update', ['filter' => 'csrf']);
    $routes->post('servers/delete', 'Admin\Servers::delete', ['filter' => 'csrf']);

    $routes->get('settings', 'Admin\Settings::index');
    $routes->post('settings/create', 'Admin\Settings::create', ['filter' => 'csrf']);
    $routes->get('settings/edit/(:num)', 'Admin\Settings::edit/$1');
    $routes->post('settings/update', 'Admin\Settings::update', ['filter' => 'csrf']);
    $routes->post('settings/delete', 'Admin\Settings::delete', ['filter' => 'csrf']);

    $routes->get('translations', 'Admin\Translations::index');
    $routes->get('translations/export', 'Admin\Translations::export');
    $routes->post('translations/create', 'Admin\Translations::create', ['filter' => 'csrf']);
    $routes->get('translations/edit/(:num)', 'Admin\Translations::edit/$1');
    $routes->post('translations/update', 'Admin\Translations::update', ['filter' => 'csrf']);
    $routes->post('translations/delete', 'Admin\Translations::delete', ['filter' => 'csrf']);

    $routes->get('email-templates', 'Admin\EmailTemplates::index');
    $routes->post('email-templates/create', 'Admin\EmailTemplates::create', ['filter' => 'csrf']);
    $routes->get('email-templates/edit/(:num)', 'Admin\EmailTemplates::edit/$1');
    $routes->post('email-templates/update', 'Admin\EmailTemplates::update', ['filter' => 'csrf']);
    $routes->post('email-templates/delete', 'Admin\EmailTemplates::delete', ['filter' => 'csrf']);

    // Read-only sent-email log — `id` is a VARCHAR token, hence `(:segment)`.
    $routes->get('emails', 'Admin\Emails::index');
    $routes->get('emails/show/(:segment)', 'Admin\Emails::show/$1');

    $routes->get('scheduled-tasks', 'Admin\ScheduledTasks::index');
    $routes->post('scheduled-tasks/create', 'Admin\ScheduledTasks::create', ['filter' => 'csrf']);
    $routes->get('scheduled-tasks/edit/(:num)', 'Admin\ScheduledTasks::edit/$1');
    $routes->post('scheduled-tasks/update', 'Admin\ScheduledTasks::update', ['filter' => 'csrf']);
    $routes->post('scheduled-tasks/delete', 'Admin\ScheduledTasks::delete', ['filter' => 'csrf']);
    $routes->post('scheduled-tasks/toggle', 'Admin\ScheduledTasks::toggle', ['filter' => 'csrf']);
    $routes->post('scheduled-tasks/run', 'Admin\ScheduledTasks::runNow', ['filter' => 'csrf']);
});

$routes->group('', ['filter' => 'authFilter'], static function (RouteCollection $routes) {
    $routes->get('dashboard', 'Client\Dashboard::index');

    $routes->get('account', 'Account::profile');
    $routes->post('account/profile', 'Account::updateProfile', ['filter' => 'csrf']);
    $routes->post('account/change-password', 'Account::changePassword', ['filter' => 'csrf']);
    $routes->get('account/security', 'Account::security');
    $routes->post('account/2fa/setup', 'Account::setupGoogle2fa', ['filter' => 'csrf']);
    $routes->post('account/2fa/verify', 'Account::verifyGoogle2fa', ['filter' => 'csrf']);
    $routes->post('account/2fa/disable', 'Account::disable2fa', ['filter' => 'csrf']);

    $routes->get('my-api-access-keys', 'Client\ApiAccessKeys::index');
    $routes->post('my-api-access-keys/create', 'Client\ApiAccessKeys::create', ['filter' => 'csrf']);
    $routes->post('my-api-access-keys/toggle', 'Client\ApiAccessKeys::toggle', ['filter' => 'csrf']);
    $routes->post('my-api-access-keys/toggle-log', 'Client\ApiAccessKeys::toggleLog', ['filter' => 'csrf']);
    $routes->post('my-api-access-keys/remove', 'Client\ApiAccessKeys::remove', ['filter' => 'csrf']);

    $routes->get('api-history', 'Client\ApiHistory::logs');
    $routes->get('api-history/preview', 'Client\ApiHistory::preview');
    $routes->post('api-history/delete', 'Client\ApiHistory::delete', ['filter' => 'csrf']);
    $routes->post('api-history/delete-all', 'Client\ApiHistory::deleteAll', ['filter' => 'csrf']);
    $routes->post('api-history/delete-by-age', 'Client\ApiHistory::deleteByAge', ['filter' => 'csrf']);

    $routes->get('console/cnr', 'Client\ConsoleCnr::index');
    $routes->post('console/cnr/run', 'Client\ConsoleCnr::run', ['filter' => 'csrf']);
    $routes->get('console/jprs', 'Client\ConsoleJprs::index');
    $routes->post('console/jprs/run', 'Client\ConsoleJprs::run', ['filter' => 'csrf']);

    $routes->get('api-docs', 'ApiDocs::index');

    $routes->get('notifications', 'Notifications::index');
    $routes->get('notifications/feed', 'Notifications::feed');
    $routes->get('notifications/unread-count', 'Notifications::unreadCount');
    $routes->post('notifications/mark-read', 'Notifications::markRead', ['filter' => 'csrf']);
    $routes->post('notifications/mark-all-read', 'Notifications::markAllRead', ['filter' => 'csrf']);
});

$routes->group('api', ['filter' => 'apiFilter'], static function (RouteCollection $routes) {
    $routes->get('cnr/(:any)', 'Api\CnrApi::proxy');
    $routes->match(['get', 'post'], 'jprs/(:any)', 'Api\JprsApi::proxy');
});
