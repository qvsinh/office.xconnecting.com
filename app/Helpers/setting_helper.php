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
