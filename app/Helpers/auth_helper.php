<?php

use App\Models\RoleModel;

/**
 * Web-auth session helpers, ported from
 * ../proxy.services.vn/app/Helpers/auth_helper.php. `userWorking` is set
 * equal to `userAuthenticated` this card (sub-user delegation is a later
 * card — see kanban plan §2/§7).
 */
if (! function_exists('is_authenticated')) {
    function is_authenticated(): bool
    {
        return (bool) session('userAuthenticated');
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return is_authenticated() && session('userAuthenticated')->zone === 'global';
    }
}

if (! function_exists('is_client')) {
    function is_client(): bool
    {
        return is_authenticated() && session('userAuthenticated')->zone === 'client';
    }
}

/*
 * VIEW-LAYER ONLY below this line. `current_zone()`/`is_working_admin()` read
 * `userWorking` (the "acting-as" identity) so the sidebar/nav render the zone
 * the admin is currently impersonating. They MUST NOT be used for
 * authorization — that stays on `is_admin()`/`is_client()`/`userAuthenticated`
 * above, which an act-as switch never changes.
 */

if (! function_exists('current_zone')) {
    function current_zone(): string
    {
        $working = session('userWorking') ?? session('userAuthenticated');

        if ($working === null) {
            return 'client';
        }

        return $working->zone ?? 'client';
    }
}

if (! function_exists('is_working_admin')) {
    function is_working_admin(): bool
    {
        return current_zone() === 'global';
    }
}

/*
 * PERMISSION LAYER (additive). `user_can()` is a new, opt-in authorization
 * capability layered on top of the zone gate above — it never replaces
 * is_admin()/is_client(), which remain the source of truth for the existing
 * global/client admin gate. An admin (zone) is treated as an implicit
 * super-user and passes user_can() for every permission.
 */

if (! function_exists('user_can')) {
    function user_can(string $permission): bool
    {
        if (is_admin()) {
            return true;
        }

        $user   = session('userWorking') ?? session('userAuthenticated');
        $roleId = $user->role_id ?? null;

        if ($roleId === null) {
            return false;
        }

        $roleId = (int) $roleId;
        $cache  = &user_can_permission_cache();

        if (! array_key_exists($roleId, $cache)) {
            $cache[$roleId] = model(RoleModel::class)->permissionsFor($roleId);
        }

        return in_array($permission, $cache[$roleId], true);
    }
}

if (! function_exists('user_can_permission_cache')) {
    /**
     * @internal Backs `user_can()`'s per-request `role_id => permission
     * names` cache (so repeated `user_can()` calls in the same request don't
     * re-query `role_permissions`). Exposed only so `user_can_reset_cache()`
     * (tests) can clear the static state between test methods running in the
     * same PHP process — a real request is a fresh process and never needs
     * to.
     *
     * @return array<int, list<string>>
     */
    function &user_can_permission_cache(): array
    {
        static $cache = [];

        return $cache;
    }
}

if (! function_exists('user_can_reset_cache')) {
    /**
     * Test-only: clears `user_can()`'s cache between test methods.
     */
    function user_can_reset_cache(): void
    {
        $cache = &user_can_permission_cache();
        $cache = [];
    }
}
