<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Site gate: precedes app login (BasicAuthFilter, registered as a global
// before-filter with `except => ['basic-auth']`), so this route must not
// sit behind authFilter/guestFilter/adminFilter.
// One-click approve / reject from the administrator's email. No auth filter and
// no site gate: the token in the URL is the credential, and there is nobody to
// hand a second password to. The GET only *asks* — deciding needs the button on
// that page, so a mail client following the link changes nothing.
$routes->get('request-decision/(:segment)/(:segment)', 'RequestDecision::show/$1/$2');
$routes->get('request-decision/(:segment)', 'RequestDecision::show/$1');
$routes->post('request-decision/(:segment)', 'RequestDecision::confirm/$1', ['filter' => 'csrf']);

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
    $routes->get('users/generate-password', 'Admin\Users::generatePassword');
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

    // Access log: personal data, so admin-zone only — not the attendance
    // permission a department head may hold.
    $routes->get('access-logs', 'Admin\AccessLogs::index');
    $routes->get('access-logs/user/(:num)', 'Admin\AccessLogs::user/$1');
    $routes->get('access-logs/show/(:num)', 'Admin\AccessLogs::show/$1');

    $routes->get('scheduled-tasks', 'Admin\ScheduledTasks::index');
    $routes->post('scheduled-tasks/create', 'Admin\ScheduledTasks::create', ['filter' => 'csrf']);
    $routes->get('scheduled-tasks/edit/(:num)', 'Admin\ScheduledTasks::edit/$1');
    $routes->post('scheduled-tasks/update', 'Admin\ScheduledTasks::update', ['filter' => 'csrf']);
    $routes->post('scheduled-tasks/delete', 'Admin\ScheduledTasks::delete', ['filter' => 'csrf']);
    $routes->post('scheduled-tasks/toggle', 'Admin\ScheduledTasks::toggle', ['filter' => 'csrf']);
    $routes->post('scheduled-tasks/run', 'Admin\ScheduledTasks::runNow', ['filter' => 'csrf']);
});

/*
 * Leave approval: a separate permission from attendance management, so a manager
 * can be given one without the other.
 */
$routes->group('admin', ['filter' => ['authFilter', 'permission:requests.approve']], static function (RouteCollection $routes) {
    $routes->get('requests', 'Admin\Requests::index');
    $routes->post('requests/approve/(:num)', 'Admin\Requests::approve/$1', ['filter' => 'csrf']);
    $routes->post('requests/reject/(:num)', 'Admin\Requests::reject/$1', ['filter' => 'csrf']);
});

/*
 * Attendance management shares the `/admin` prefix but NOT `adminFilter`: it is
 * permission-gated so a department head can review and correct their team's
 * attendance without holding global admin rights. `PermissionFilter` still lets
 * admin-zone users through. A second group with the same prefix is legal in CI4
 * — verify with `php spark routes` after editing.
 */
$routes->group('admin', ['filter' => ['authFilter', 'permission:attendance.manage']], static function (RouteCollection $routes) {
    // Today board first — it is the screen a manager opens daily.
    $routes->get('attendance/today', 'Admin\Attendance::today');

    $routes->get('attendance/report', 'Admin\AttendanceReport::index');
    $routes->get('attendance/report/user/(:num)', 'Admin\AttendanceReport::user/$1');
    $routes->get('attendance/report/export', 'Admin\AttendanceReport::export');
    $routes->post('attendance/report/lock', 'Admin\AttendanceReport::lock', ['filter' => 'csrf']);
    $routes->post('attendance/report/unlock', 'Admin\AttendanceReport::unlock', ['filter' => 'csrf']);

    $routes->get('attendance', 'Admin\Attendance::index');
    $routes->get('attendance/edit/(:num)', 'Admin\Attendance::edit/$1');
    $routes->post('attendance/update', 'Admin\Attendance::update', ['filter' => 'csrf']);
    $routes->post('attendance/create', 'Admin\Attendance::create', ['filter' => 'csrf']);

    // Work schedules + holidays: the baseline the attendance figures are
    // measured against (card 01b).
    $routes->get('attendance/schedules', 'Admin\WorkSchedules::index');
    $routes->post('attendance/schedules/create', 'Admin\WorkSchedules::create', ['filter' => 'csrf']);
    $routes->get('attendance/schedules/edit/(:num)', 'Admin\WorkSchedules::edit/$1');
    $routes->post('attendance/schedules/update', 'Admin\WorkSchedules::update', ['filter' => 'csrf']);
    $routes->post('attendance/schedules/delete', 'Admin\WorkSchedules::delete', ['filter' => 'csrf']);

    $routes->get('attendance/holidays', 'Admin\Holidays::index');
    $routes->post('attendance/holidays/create', 'Admin\Holidays::create', ['filter' => 'csrf']);
    $routes->post('attendance/holidays/delete', 'Admin\Holidays::delete', ['filter' => 'csrf']);
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

    $routes->get('account/api-keys', 'Account\ApiKeys::index');
    $routes->post('account/api-keys/create', 'Account\ApiKeys::create', ['filter' => 'csrf']);
    $routes->post('account/api-keys/revoke', 'Account\ApiKeys::revoke', ['filter' => 'csrf']);

    $routes->get('docs', 'Docs::index');
    $routes->get('docs/getting-started', 'Docs::gettingStarted');

    // Employee self-service attendance — always scoped to the session user,
    // never takes a user id.
    $routes->get('attendance', 'Attendance::index');
    $routes->get('attendance/report', 'Attendance::report');

    // Leave / late-arrival requests (card 02) — always the session user's own.
    $routes->get('requests', 'Requests::index');
    $routes->get('requests/preview', 'Requests::preview');
    $routes->post('requests/create', 'Requests::create', ['filter' => 'csrf']);
    $routes->post('requests/cancel/(:num)', 'Requests::cancel/$1', ['filter' => 'csrf']);
    $routes->post('attendance/checkin', 'Attendance::checkin', ['filter' => 'csrf']);
    $routes->post('attendance/checkout', 'Attendance::checkout', ['filter' => 'csrf']);

    $routes->get('notifications', 'Notifications::index');
    $routes->get('notifications/feed', 'Notifications::feed');
    $routes->get('notifications/unread-count', 'Notifications::unreadCount');
    $routes->post('notifications/mark-read', 'Notifications::markRead', ['filter' => 'csrf']);
    $routes->post('notifications/mark-all-read', 'Notifications::markAllRead', ['filter' => 'csrf']);
});

// REST API for AI agents (Bearer token, `api_keys` table) — stateless, so no
// csrf/authFilter/session here; `apikey` resolves+scopes the request instead
// (see `kanban/processing/20260721-07-rest-api.md`).
// Language switcher, available before login: the login screen is the first page
// anyone sees, so it is the first place the choice matters.
$routes->post('locale/switch', 'Locale::switch', ['filter' => 'csrf']);

$routes->group('api/v1', ['filter' => 'apikey'], static function (RouteCollection $routes) {
    $routes->get('me', 'Api\V1\Me::index');
});
