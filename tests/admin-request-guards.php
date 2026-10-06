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
 * The plugins screen escapes the error value it puts in the install-error frame, and the
 * error_plugin and status_alerts actions refuse a request without a CSRF token. The view is
 * rendered and the controllers are driven; nothing here reads source text.
 *
 * DB-free.  Usage: php tests/admin-request-guards.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
define('OSC_DEBUG', false);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once __DIR__ . '/lib/stubs.php';

/** What a missing or wrong token ends in; the real check redirects and exits. */
class CsrfRefused extends RuntimeException
{
}

/** Thrown in place of the exit() a real redirect ends the request with. */
class GuardRedirect extends RuntimeException
{
}

function osc_csrf_check()
{
    if (Params::getParam('CSRFToken') !== 'good') {
        throw new CsrfRefused();
    }
}
function osc_csrf_token_url()
{
    return 'CSRFName=n&CSRFToken=good';
}
function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
}
function osc_plugins_path()
{
    return $GLOBALS['pluginsPath'];
}
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flashes'][] = $msg;
}
function _m($text)
{
    return $text;
}
function _e($text)
{
    echo $text;
}
function osc_market_i18n($type)
{
    return array();
}
function osc_asset_url_versioned($url)
{
    return $url;
}
function osc_current_admin_theme_js_url($file)
{
    return $file;
}
// Page chrome and market panels the error frame does not depend on.
foreach (array(
    'osc_admin_page', 'osc_current_admin_theme_path', 'osc_register_script', 'osc_enqueue_script',
    'osc_admin_page_head', 'osc_admin_empty', 'osc_admin_pagination', 'osc_admin_per_page',
    'osc_market_render_browse', 'osc_market_render_updates', 'osc_market_render_detail_dialog',
    'osc_package_list_open', 'osc_package_list_close',
) as $name) {
    if (!function_exists($name)) {
        eval('function ' . $name . '(...$args) { return ""; }');
    }
}

/** The controller base class, stubbed: the section permission check has already happened. */
class AdminSecBaseModel
{
    protected $action;

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        throw new GuardRedirect((string) $url);
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminUsers.php';

/** Run one controller action with $query as the request; returns how it ended. */
function guard_drive(string $class, string $action, array $query): string
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
    } catch (CsrfRefused $e) {
        return 'csrf refused';
    } catch (GuardRedirect $e) {
        return 'redirect';
    }

    return 'finished';
}

$GLOBALS['pluginsPath'] = sys_get_temp_dir() . '/osc-guards-' . getmypid() . '/';
@mkdir($GLOBALS['pluginsPath'] . 'demo', 0777, true);
file_put_contents($GLOBALS['pluginsPath'] . 'demo/README.md', 'not code');

harness_section('plugin install error frame');
$_GET = $_REQUEST = array('page' => 'plugins', 'error' => 'demo/x.php" onload="alert(1)');
Params::init();
View::getInstance()->_exportVariableToView('aPlugins', array('aaData' => array()));
ob_start();
include ABS_PATH . 'oc-admin/themes/modern/plugins/index.php';
$page = (string) ob_get_clean();
check('the frame is drawn', strpos($page, 'action=error_plugin') !== false);
check('the error value is encoded into the address', strpos($page, 'plugin=demo%2Fx.php') !== false);
check('...and cannot close the attribute', strpos($page, 'onload="alert') === false);
check('the frame address carries the CSRF token', strpos($page, 'action=error_plugin&amp;CSRFName=n&CSRFToken=good&amp;plugin=') !== false);

harness_section('error_plugin');
pin('without a token it is refused', 'csrf refused', guard_drive('CAdminPlugins', 'error_plugin', array('plugin' => 'demo/index.php')));
pin('with a token, a non-.php file is refused', 'redirect', guard_drive('CAdminPlugins', 'error_plugin', array('plugin' => 'demo/README.md', 'CSRFToken' => 'good')));
pin('...with a message', array('Invalid plugin file'), $GLOBALS['flashes']);
pin('a path out of the plugins folder is refused', 'redirect', guard_drive('CAdminPlugins', 'error_plugin', array('plugin' => '../../index.php', 'CSRFToken' => 'good')));

harness_section('status_alerts');
pin('without a token it is refused', 'csrf refused', guard_drive('CAdminUsers', 'status_alerts', array('status' => '1', 'alert_id' => array('1'))));
pin('with a token it goes on to read the request', 'redirect', guard_drive('CAdminUsers', 'status_alerts', array('status' => '1', 'alert_id' => '1', 'CSRFToken' => 'good')));
pin('...and refuses an id that is not a list', array("Alert id isn't in the correct format"), $GLOBALS['flashes']);

@unlink($GLOBALS['pluginsPath'] . 'demo/README.md');
@rmdir($GLOBALS['pluginsPath'] . 'demo');
@rmdir($GLOBALS['pluginsPath']);

exit(harness_result());
