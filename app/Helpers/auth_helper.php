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

if (! function_exists('zone_is_admin')) {
    /**
     * Whether a `users.zone` value denotes an administrator.
     *
     * `zone` was renamed `global` -> `admin` / `client` -> `user` (migration
     * 2026-07-27-010000). Sessions cache the user row, so anyone signed in when
     * that migration ran still carries the legacy value until their next login —
     * this accepts both so nobody loses admin access mid-session.
     *
     * ponytail: the ONLY place the legacy value is tolerated. Once every session
     * has rolled over, drop the `=== 'global'` arm and this stays a one-liner.
     */
    function zone_is_admin(?string $zone): bool
    {
        return $zone === 'admin' || $zone === 'global';
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return is_authenticated() && zone_is_admin(session('userAuthenticated')->zone ?? null);
    }
}

if (! function_exists('is_client')) {
    /**
     * A non-administrator (an ordinary employee). Defined as "not an admin"
     * rather than by matching a value, so the legacy `client` and the current
     * `user` both resolve correctly without a second legacy list.
     */
    function is_client(): bool
    {
        return is_authenticated() && ! zone_is_admin(session('userAuthenticated')->zone ?? null);
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
    /**
     * The acted-as identity's zone, normalised to the current vocabulary
     * (`admin` / `user`) so callers never have to know about the legacy values.
     */
    function current_zone(): string
    {
        $working = session('userWorking') ?? session('userAuthenticated');

        if ($working === null) {
            return 'user';
        }

        return zone_is_admin($working->zone ?? null) ? 'admin' : 'user';
    }
}

if (! function_exists('is_working_admin')) {
    function is_working_admin(): bool
    {
        return current_zone() === 'admin';
    }
}

if (! function_exists('is_impersonating')) {
    /**
     * Whether an admin is currently "acting as" somebody else (`userWorking`
     * differs from `userAuthenticated`).
     *
     * Read/ownership scoping deliberately follows `userWorking` — but actions
     * that *attest to a person's own conduct* must not. Recording a check-in
     * while impersonating would file an arrival under the employee's name that
     * the employee never made, i.e. falsified payroll input, so `Attendance`
     * refuses it (viewing stays allowed).
     */
    function is_impersonating(): bool
    {
        $authenticated = session('userAuthenticated');
        $working       = session('userWorking');

        return $authenticated !== null
            && $working !== null
            && (int) ($authenticated->id ?? 0) !== (int) ($working->id ?? 0);
    }
}

if (! function_exists('current_user_id')) {
    /**
     * The session identity that owns/scopes collaboration data (workspaces,
     * projects, tasks, ...): `userWorking`, the "acting-as" identity, so
     * ownership checks follow an admin's act-as switch the same way the
     * account area does (see `Account::me()`). Returns `0` when there's no
     * working user (never null — callers can compare against real ids
     * directly without a null check).
     */
    function current_user_id(): int
    {
        return (int) (session('userWorking')->id ?? 0);
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
