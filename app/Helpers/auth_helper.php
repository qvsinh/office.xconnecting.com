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
