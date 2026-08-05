<?php

namespace Config;

use App\Libraries\DatabaseLanguage;
use App\Libraries\Google2faService;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */

    /**
     * Google Authenticator (TOTP) wrapper — see App\Libraries\Google2faService.
     * Used by `Account` (enrollment) and `Auth::verify2fa()` (login 2nd factor).
     */
    public static function google2fa(bool $getShared = true): Google2faService
    {
        if ($getShared) {
            return static::getSharedInstance('google2fa');
        }

        return new Google2faService();
    }

    /**
     * Replaces CI4's file-only translator with one that also reads the
     * `translation` table, so `/admin/translations` changes what the app says.
     *
     * Same signature and shared-instance behaviour as the framework's own
     * `language()` service — only the class differs. See
     * `App\Libraries\DatabaseLanguage` for how the two sources layer.
     */
    public static function language(?string $locale = null, bool $getShared = true): DatabaseLanguage
    {
        // Resolve the locale *before* the shared lookup, unlike the framework's
        // own service which only does so on the non-shared path.
        //
        // `Language::setLocale(null)` is a no-op, so a shared instance otherwise
        // keeps whatever locale it was first built with — for the life of the
        // process. One request per php-fpm process hides that, but anything
        // handling several requests in one process (spark serve, a worker, the
        // test suite) then renders every later request in the first one's
        // language. Passing a concrete locale makes the instance follow the
        // request it is actually serving.
        $locale ??= service('request')->getLocale();

        if ($getShared) {
            return static::getSharedInstance('language', $locale)->setLocale($locale);
        }

        return new DatabaseLanguage($locale);
    }
}
