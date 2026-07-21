<?php

namespace App\Libraries;

/**
 * The authenticated API-key user for the current request, resolved by
 * `ApiKeyFilter::before()` and read by every `Api\V1` controller instead of
 * the web session (there isn't one for token auth). See
 * `kanban/processing/20260721-07-rest-api.md`.
 *
 * ponytail: a request is one PHP process — a static is the minimal correct
 * carrier, no request-attribute/DI plumbing needed for a value this narrow.
 */
final class ApiContext
{
    private static ?int $userId   = null;
    private static string $scopes = '';

    public static function set(int $userId, string $scopes): void
    {
        self::$userId = $userId;
        self::$scopes = $scopes;
    }

    public static function reset(): void
    {
        self::$userId = null;
        self::$scopes = '';
    }

    public static function userId(): ?int
    {
        return self::$userId;
    }

    public static function scopes(): string
    {
        return self::$scopes;
    }

    public static function hasWrite(): bool
    {
        return str_contains(self::$scopes, 'write');
    }
}
