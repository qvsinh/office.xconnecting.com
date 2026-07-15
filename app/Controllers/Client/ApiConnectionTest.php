<?php

namespace App\Controllers\Client;

use App\Controllers\ApiConnectionController;

/**
 * Self-service API connection self-test, scoped to the session user's own
 * keys (any provider). `run()` enforces ownership via `requireOwnKey()`
 * below — a foreign `key_id` 404s, no IDOR.
 *
 * @internal
 */
final class ApiConnectionTest extends ApiConnectionController
{
    protected function requireOwnKey(): bool
    {
        return true;
    }
}
