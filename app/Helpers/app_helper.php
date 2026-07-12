<?php

use Hashids\Hashids;

if (! function_exists('get_hashids')) {
    /**
     * Build a Hashids codec using the app-wide salt (`hashid_salt` in .env).
     * Used to mint/decode API access tokens (`[user_id, env, service_id]`).
     */
    function get_hashids(int $minHashLength = 5): Hashids
    {
        return new Hashids((string) getenv('hashid_salt'), $minHashLength);
    }
}
