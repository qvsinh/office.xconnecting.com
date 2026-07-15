<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiConnectionController;

/**
 * Admin API connection self-test. Any user's key (any provider) may be
 * tested; `run()` still always derives provider/env from the resolved key
 * row (see `ApiConnectionController::run()`), never from request input.
 *
 * @internal
 */
final class ApiConnectionTest extends ApiConnectionController
{
    protected function requireOwnKey(): bool
    {
        return false;
    }
}
