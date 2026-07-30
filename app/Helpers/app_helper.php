<?php

use Hashids\Hashids;

if (! function_exists('get_hashids')) {
    /**
     * Build a Hashids codec using the app-wide salt (`hashid_salt` in .env).
     * Used to mint/decode API access tokens.
     */
    function get_hashids(int $minHashLength = 5): Hashids
    {
        return new Hashids((string) getenv('hashid_salt'), $minHashLength);
    }
}

if (! function_exists('asset_version')) {
    /**
     * Cache-busting token for `/css/app.css` and friends.
     *
     * Driven by whether `asset.version` is set rather than by `ENVIRONMENT`: a
     * configured value is a deliberate, stable token, so the browser actually
     * caches the stylesheet between deploys and you invalidate it by bumping the
     * key. Production sets it (DEPLOYMENT.md §3 lists it).
     *
     * With nothing configured, randomise per render so an edit to
     * `resources/css/app.css` shows up on the next refresh instead of after a
     * hard reload. Forgetting the key in production therefore costs a re-download
     * on every page view — never correctness.
     */
    function asset_version(): string
    {
        $configured = trim((string) env('asset.version'));

        if ($configured !== '') {
            return $configured;
        }

        return (string) random_int(1, 999999999);
    }
}
