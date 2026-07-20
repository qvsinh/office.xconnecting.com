<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Site gate: precedes app login (BasicAuthFilter, registered as a global
// before-filter with `except => ['basic-auth']`), so this route must not
// sit behind authFilter/guestFilter/adminFilter.
$routes->get('basic-auth', 'BasicAuth::index');
$routes->post('basic-auth', 'BasicAuth::authenticate', ['filter' => 'csrf']);

// Login is guest-only.
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

    $routes->get('system', 'Admin\System::index');
    $routes->post('system/opcache/reset', 'Admin\System::resetOpcache', ['filter' => 'csrf']);
    $routes->post('system/cache/clear', 'Admin\System::clearCache', ['filter' => 'csrf']);
    $routes->post('system/sessions/reset', 'Admin\System::resetSessions', ['filter' => 'csrf']);
    $routes->get('system/migrations', 'Admin\System::migrations');
    $routes->post('system/migrations/run', 'Admin\System::runMigrations', ['filter' => 'csrf']);
    $routes->post('system/migrations/rollback', 'Admin\System::rollbackMigration', ['filter' => 'csrf']);
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

    $routes->get('settings', 'Admin\Settings::index');
    $routes->post('settings/create', 'Admin\Settings::create', ['filter' => 'csrf']);
    $routes->get('settings/edit/(:num)', 'Admin\Settings::edit/$1');
    $routes->post('settings/update', 'Admin\Settings::update', ['filter' => 'csrf']);
    $routes->post('settings/delete', 'Admin\Settings::delete', ['filter' => 'csrf']);

    $routes->get('groups', 'Admin\Groups::index');
    $routes->post('groups/create', 'Admin\Groups::create', ['filter' => 'csrf']);
    $routes->get('groups/edit/(:num)', 'Admin\Groups::edit/$1');
    $routes->post('groups/update', 'Admin\Groups::update', ['filter' => 'csrf']);
    $routes->post('groups/delete', 'Admin\Groups::delete', ['filter' => 'csrf']);
    $routes->get('groups/members/(:num)', 'Admin\Groups::members/$1');
    $routes->get('groups/(:num)/members-data', 'Admin\Groups::membersData/$1');
    $routes->post('groups/(:num)/members/add', 'Admin\Groups::addMember/$1', ['filter' => 'csrf']);
    $routes->post('groups/(:num)/members/remove', 'Admin\Groups::removeMember/$1', ['filter' => 'csrf']);

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

    $routes->get('notifications', 'Notifications::index');
    $routes->get('notifications/feed', 'Notifications::feed');
    $routes->get('notifications/unread-count', 'Notifications::unreadCount');
    $routes->post('notifications/mark-read', 'Notifications::markRead', ['filter' => 'csrf']);
    $routes->post('notifications/mark-all-read', 'Notifications::markAllRead', ['filter' => 'csrf']);

    $routes->get('workspaces', 'Workspaces::index');
    $routes->post('workspaces/create', 'Workspaces::create', ['filter' => 'csrf']);
    $routes->get('workspaces/(:num)', 'Workspaces::show/$1');
    $routes->post('workspaces/update/(:num)', 'Workspaces::update/$1', ['filter' => 'csrf']);
    $routes->post('workspaces/delete/(:num)', 'Workspaces::delete/$1', ['filter' => 'csrf']);
    $routes->post('workspaces/(:num)/join', 'Workspaces::join/$1', ['filter' => 'csrf']);
    $routes->post('workspaces/(:num)/leave', 'Workspaces::leave/$1', ['filter' => 'csrf']);
    $routes->post('workspaces/(:num)/members/add', 'Workspaces::addMember/$1', ['filter' => 'csrf']);
    $routes->post('workspaces/(:num)/members/remove', 'Workspaces::removeMember/$1', ['filter' => 'csrf']);
    $routes->post('workspaces/(:num)/members/add-group', 'Workspaces::addGroup/$1', ['filter' => 'csrf']);
});
