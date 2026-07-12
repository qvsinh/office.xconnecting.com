<?php

namespace App\Libraries;

/**
 * Per-request auth context resolved by `ApiFilter::before()` and consumed by
 * `ApiController::initController()`. Filter and controller are separate
 * classes instantiated in the same request lifecycle; this shared service
 * (see `Config\Services::apiAuthContext()`) is how the filter hands the
 * authenticated caller to the controller without a global or session.
 */
class ApiAuthContext
{
    public ?object $user      = null;
    public ?int $serviceId    = null;
    public ?int $keyEnableLog = null;
}
