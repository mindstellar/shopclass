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
 * The admin screens refuse a theme or plugin that needs a newer Shopclass: the controllers are
 * driven and the flash message and the saved theme are checked.
 *
 * DB-free.  Usage: php tests/admin-compat-gates.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
define('OSC_DEBUG', false);
define('OSCLASS_VERSION', '9.9.9');

class GateRedirect extends RuntimeException
{
}

$GLOBALS['themesDir']  = sys_get_temp_dir() . '/osc-gates-themes-' . getmypid() . '/';
$GLOBALS['pluginsDir'] = sys_get_temp_dir() . '/osc-gates-plugins-' . getmypid() . '/';
$GLOBALS['prefs']      = array();
$GLOBALS['flashes']    = array();
define('PLUGINS_PATH', $GLOBALS['pluginsDir']);

function osc_themes_path()
{
    return $GLOBALS['themesDir'];
}
function osc_plugins_path()
{
    return $GLOBALS['pluginsDir'];
}
function osc_theme()
{
    return 'oldtheme';
}
function osc_active_plugins()
{
    return serialize(array());
}
function osc_installed_plugins()
{
    return serialize(array());
}
function osc_csrf_check()
{
}
function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
}
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flashes'][] = $msg;
}
function osc_add_flash_ok_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flashes'][] = 'ok: ' . $msg;
}
function osc_set_preference($name, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['prefs'][$name] = $value;
}
function _m($text)
{
    return $text;
}

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

/** The controller base class, stubbed: the section permission check has already happened. */
class AdminSecBaseModel
{
    protected $action;

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        throw new GateRedirect((string) $url);
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminPlugins.php';

/** Run one controller action with $query as the request; returns the flash messages shown. */
function gate_drive(string $class, string $action, array $query): array
{
    $_GET     = $_REQUEST = $query + array('action' => $action);
    $_POST    = array();
    Params::init();
    $GLOBALS['flashes'] = array();
    $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $prop       = new ReflectionProperty('AdminSecBaseModel', 'action');
    $prop->setAccessible(true);
    $prop->setValue($controller, $action);
    try {
        $controller->doModel();
    } catch (GateRedirect $e) {
        // The redirect ends the request.
    }

    return $GLOBALS['flashes'];
}

foreach (array('oldtheme' => '', 'toonew' => "Requires Shopclass: 99.0.0\n") as $theme => $line) {
    mkdir($GLOBALS['themesDir'] . $theme, 0777, true);
    file_put_contents($GLOBALS['themesDir'] . $theme . '/index.php', "<?php\n/*\nTheme Name: $theme\n$line*/\n");
}
mkdir($GLOBALS['pluginsDir'] . 'toonew', 0777, true);
file_put_contents($GLOBALS['pluginsDir'] . 'toonew/index.php', "<?php\n/*\nPlugin Name: Too New\nRequires Shopclass: 99.0.0\n*/\nthrow new Error('loaded');\n");

harness_section('activating a theme');
$flashes = gate_drive('CAdminAppearance', 'activate', array('theme' => 'toonew'));
pin('a theme that needs a newer Shopclass is refused with the reason', array('Requires Shopclass 99.0.0 or newer (you have 9.9.9).'), $flashes);
pin('...and is not made the theme', false, isset($GLOBALS['prefs']['theme']));

harness_section('the plugin install frame');
$flashes = gate_drive('CAdminPlugins', 'error_plugin', array('plugin' => 'toonew/index.php'));
pin('a plugin that needs a newer Shopclass is refused before its file is loaded', array('Requires Shopclass 99.0.0 or newer (you have 9.9.9).'), $flashes);

unlink($GLOBALS['pluginsDir'] . 'toonew/index.php');
rmdir($GLOBALS['pluginsDir'] . 'toonew');
rmdir($GLOBALS['pluginsDir']);
foreach (array('oldtheme', 'toonew') as $theme) {
    unlink($GLOBALS['themesDir'] . $theme . '/index.php');
    rmdir($GLOBALS['themesDir'] . $theme);
}
rmdir($GLOBALS['themesDir']);

exit(harness_result());
