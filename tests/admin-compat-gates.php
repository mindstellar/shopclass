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
 * The admin screens and the command line refuse a theme or plugin that needs a newer Shopclass: the
 * controllers and the CLI are driven and what they say and save is checked.
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
    return $GLOBALS['prefs']['active_plugins'] ?? serialize(array());
}
function osc_installed_plugins()
{
    return $GLOBALS['prefs']['installed_plugins'] ?? serialize(array());
}
function osc_reset_preferences()
{
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
require_once ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php';

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

/** Keeps what the CLI writes to STDOUT and STDERR, which an output buffer does not see. */
final class Capture extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $GLOBALS['__captured'] .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
stream_filter_register('capture', Capture::class);

/** Forget the installed list Plugins cached, after a test edits the preference. */
function forget_installed(): void
{
    $prop = new ReflectionProperty('Plugins', 'installed');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/** Run the CLI and hand back its exit code and what it printed. */
function cli(array $argv): array
{
    $GLOBALS['__captured'] = '';
    $filters = array(stream_filter_append(STDOUT, 'capture'), stream_filter_append(STDERR, 'capture'));
    $code    = \mindstellar\cli\Cli::run($argv);
    array_map('stream_filter_remove', $filters);

    return array($code, $GLOBALS['__captured']);
}

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

harness_section('turning a plugin on');
$flashes = gate_drive('CAdminPlugins', 'enable', array('plugin' => 'toonew/index.php'));
pin('a plugin that needs a newer Shopclass is refused with the reason', array('Requires Shopclass 99.0.0 or newer (you have 9.9.9).'), $flashes);

harness_section('plugin:activate');
$GLOBALS['prefs']['installed_plugins'] = serialize(array('toonew/index.php'));
forget_installed();
[$code, $out] = cli(array('plugin:activate', '--plugin=toonew'));
pin('a plugin that needs a newer Shopclass is refused', array(1, true), array($code, str_contains($out, 'Requires Shopclass 99.0.0')));
pin('...and stays off', serialize(array()), $GLOBALS['prefs']['active_plugins'] ?? serialize(array()));

harness_section('theme:activate');
[$code, $out] = cli(array('theme:activate', '--theme=toonew'));
pin('a theme that needs a newer Shopclass is refused', array(1, true, false), array($code, str_contains($out, 'Requires Shopclass 99.0.0'), isset($GLOBALS['prefs']['theme'])));

unlink($GLOBALS['pluginsDir'] . 'toonew/index.php');
rmdir($GLOBALS['pluginsDir'] . 'toonew');
rmdir($GLOBALS['pluginsDir']);
foreach (array('oldtheme', 'toonew') as $theme) {
    unlink($GLOBALS['themesDir'] . $theme . '/index.php');
    rmdir($GLOBALS['themesDir'] . $theme);
}
rmdir($GLOBALS['themesDir']);

exit(harness_result());
