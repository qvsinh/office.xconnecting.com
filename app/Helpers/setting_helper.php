<?php

use App\Models\SiteVariableModel;

/**
 * Site Variable Helper
 *
 * Reads admin-managed key/value settings (`site_variable` table, see
 * `Admin\Settings`) so stored config takes effect app-wide, not just in the
 * admin controller.
 *
 * Usage:
 *  helper('setting');
 *  $appName = get_site_variable('app_name', 'proxyv2');
 */
if (! function_exists('get_site_variable')) {
    function get_site_variable(string $name, ?string $default = null): ?string
    {
        return model(SiteVariableModel::class)->getValue($name, $default);
    }
}

if (! function_exists('site_name')) {
    /**
     * The site/brand name used in page <title> tags. Sourced from the
     * admin-editable `site_name` site variable, falling back to a default
     * when it is unset or blank. Single source of the default so the four
     * title-bearing views can't drift.
     */
    function site_name(): string
    {
        $name = get_site_variable('site_name', 'CI4 Starter');

        return ($name === null || $name === '') ? 'CI4 Starter' : $name;
    }
}
