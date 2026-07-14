<?php

namespace Config;

use App\Libraries\ApiAuthContext;
use App\Libraries\CnrClient;
use App\Libraries\Google2faService;
use App\Libraries\JprsClient;
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
     * Per-request auth context handed off from `ApiFilter::before()` to
     * `ApiController::initController()` (see App\Libraries\ApiAuthContext).
     */
    public static function apiAuthContext(bool $getShared = true): ApiAuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('apiAuthContext');
        }

        return new ApiAuthContext();
    }

    /**
     * Shared RRP (CNR) upstream client — see App\Libraries\CnrClient. Used by
     * the Bearer proxy (`Api\CnrApi`) and the web developer console.
     */
    public static function cnrClient(bool $getShared = true): CnrClient
    {
        if ($getShared) {
            return static::getSharedInstance('cnrClient');
        }

        return new CnrClient();
    }

    /**
     * Shared JPRS (.jp) upstream client — see App\Libraries\JprsClient. Used
     * by the Bearer proxy (`Api\JprsApi`) and the web developer console.
     */
    public static function jprsClient(bool $getShared = true): JprsClient
    {
        if ($getShared) {
            return static::getSharedInstance('jprsClient');
        }

        return new JprsClient();
    }

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
}
