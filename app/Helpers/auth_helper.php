<?php

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
