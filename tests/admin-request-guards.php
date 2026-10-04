<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the request guards on admin and public file-including actions: reflected values
 * are escaped, state-changing actions check the CSRF token, and every include goes
 * through PluginAjaxFile.  Usage: php tests/admin-request-guards.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

function guard_src(string $path): string
{
    return (string) file_get_contents(ABS_PATH . $path);
}

/** Body of one `case 'name':` up to the next case at the same indent. */
function guard_case(string $src, string $case): string
{
    $start = strpos($src, "case ('" . $case . "'):");
    if ($start === false) {
        $start = strpos($src, "case '" . $case . "':");
    }
    if ($start === false) {
        return '';
    }
    $end = preg_match('/\n            (case |default:)/', $src, $m, PREG_OFFSET_CAPTURE, $start + 10)
        ? $m[0][1] : strlen($src);

    return substr($src, $start, $end - $start);
}

$plugins = guard_src('oc-includes/osclass/classes/controller/admin/CAdminPlugins.php');
$view    = guard_src('oc-admin/themes/modern/plugins/index.php');

harness_section('plugin install error iframe');
check('error value is escaped and url-encoded', strpos($view, "osc_esc_html(urlencode(Params::getParamString('error')))") !== false);
check('raw error value is not echoed', strpos($view, "echo Params::getParam('error')") === false);
check('iframe url carries the CSRF token', strpos($view, 'action=error_plugin&amp;<?php') !== false && strpos($view, 'osc_csrf_token_url()') !== false);

$case = guard_case($plugins, 'error_plugin');
check('error_plugin case found', $case !== '');
check('error_plugin checks CSRF', strpos($case, 'osc_csrf_check()') !== false);
check('error_plugin refuses on demo', strpos($case, 'refuseOnDemo') !== false);
check('error_plugin resolves through PluginAjaxFile', strpos($case, 'PluginAjaxFile::resolve') !== false);
check('error_plugin no longer includes a raw path', strpos($case, 'include(osc_plugins_path()') === false);

harness_section('sort and direction hidden inputs');
foreach (array('items', 'users') as $screen) {
    $src = guard_src('oc-admin/themes/modern/' . $screen . '/index.php');
    check($screen . ': sort is escaped', strpos($src, 'value="<?php echo $sort; ?>"') === false
        && strpos($src, 'osc_esc_html($sort)') !== false);
    check($screen . ': direction is escaped', strpos($src, 'value="<?php echo $direction; ?>"') === false
        && strpos($src, 'osc_esc_html($direction)') !== false);
    check($screen . ': direction is allow-listed', strpos($src, "array('asc', 'desc')") !== false);
}

harness_section('alert status change');
$users = guard_case(guard_src('oc-includes/osclass/classes/controller/admin/CAdminUsers.php'), 'status_alerts');
check('status_alerts checks CSRF', strpos($users, 'osc_csrf_check()') !== false);
$frm = guard_src('oc-admin/themes/modern/users/frm.php');
check('user edit alert links carry the token', substr_count($frm, "status_alerts&alert_id[]='") === 2
    && substr_count($frm, 'osc_csrf_token_url()') >= 2);

harness_section('file includes are confined');
$appearance = guard_src('oc-includes/osclass/classes/controller/admin/CAdminAppearance.php');
$render     = guard_case($appearance, 'render');
check('appearance render uses PluginAjaxFile', strpos($render, 'PluginAjaxFile::resolveWithin') !== false);
check('appearance render no longer trusts file_exists', strpos($render, 'file_exists(osc_base_path()') === false);
$appView = guard_src('oc-admin/themes/modern/appearance/view.php');
check('appearance view requires only a resolved path', strpos($appView, 'file_exists($file)') === false);
check('public custom page uses PluginAjaxFile', strpos(guard_src('oc-includes/osclass/classes/controller/CWebCustom.php'), 'PluginAjaxFile::resolve') !== false);
$theme = guard_src('oc-includes/osclass/helpers/hTheme.php');
$fn    = substr($theme, (int) strpos($theme, 'function osc_render_file('), 1400);
check('osc_render_file uses PluginAjaxFile', strpos($fn, 'PluginAjaxFile::resolve') !== false);
check('osc_render_file no longer includes by file_exists', strpos($fn, 'file_exists(osc_plugins_path()') === false);

exit(harness_result());
