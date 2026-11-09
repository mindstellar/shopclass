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
 * Pins osc_admin_plugin_page(): a plugin declares a route screen's header once, and the
 * plugin view reads it back for that route only.
 * Usage:  php tests/admin-plugin-page.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

define('OC_ADMIN', true);

class Params
{
    public static function getParamString($key)
    {
        return 'acme-records';
    }
}

function __get($key)
{
    return $GLOBALS['view_file'];
}

function osc_admin_page(array $opts)
{
}

function osc_current_admin_theme_path($path)
{
    echo '[' . $path . ']';
}

harness_section('declaring a plugin screen\'s header');

pin('a route that declared nothing reads empty', array(), osc_admin_plugin_page('acme-records'));
$opts = array('title' => 'Records', 'help' => 'What it is for.', 'actions' => array(array('icon' => 'bi-plus-circle-fill', 'url' => '#', 'title' => 'Add')));
osc_admin_plugin_page('acme-records', $opts);
pin('a declared route reads back what it declared', $opts, osc_admin_plugin_page('acme-records'));
pin('and another route is not touched', array(), osc_admin_plugin_page('acme-other'));

$view = file_get_contents(ABS_PATH . 'oc-admin/themes/modern/plugins/view.php');
check('the plugin view reads it for the current route', strpos($view, "osc_admin_plugin_page(Params::getParamString('route'))") !== false);

harness_section('the plugin view\'s wrapper');
$plugin = tempnam(sys_get_temp_dir(), 'plg');
file_put_contents($plugin, '<div class="acme-own"><b>mine</b></div>');
$GLOBALS['view_file'] = $plugin;
ob_start();
include ABS_PATH . 'oc-admin/themes/modern/plugins/view.php';
$html = (string) ob_get_clean();
unlink($plugin);
check('the plugin output sits inside a plain .theme-files', strpos($html, '<div class="theme-files"><div class="acme-own"><b>mine</b></div></div>') !== false, $html);
check('between the header and the footer', preg_match('#^\[parts/header.php\]<div class="theme-files">.*</div>\[parts/footer.php\]$#s', $html) === 1, $html);
check('no HTML comment is printed', strpos($html, '<!--') === false, $html);

exit(harness_result());
