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
 * The currencies screen's Decimals field: the add form prefills it from the code, a save
 * stores what was typed, a bad value is refused with a message, and the list shows it.
 *
 * Usage:  php tests/admin-currency-form.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_currency_form');

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}

if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_add_admin_submenu_page')) {
    function osc_add_admin_submenu_page($menu, $title, $url, $id, $capability = 'administrator')
    {
    }
}

// Recorded rather than enforced: whether the check runs at all, and on which actions, is
// one of the things this file asserts.
$GLOBALS['csrfChecks'] = array();
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
        $GLOBALS['csrfChecks'][] = Params::getParam('action');

        return true;
    }
}
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('error', $msg);
    }
}
if (!function_exists('osc_add_flash_ok_message')) {
    function osc_add_flash_ok_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('ok', $msg);
    }
}
if (!function_exists('osc_add_flash_warning_message')) {
    function osc_add_flash_warning_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('warning', $msg);
    }
}
// The page shell the theme draws around the form. Not stubbed: osc_admin_page() is what
// carries the screen's title into the browser title, which is chrome the migration had to
// keep.
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
if (!function_exists('osc_current_admin_theme_url')) {
    function osc_current_admin_theme_url($file = '')
    {
        return 'https://example.test/oc-admin/themes/modern/' . $file;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
// After hSanitize.php, so its real osc_esc_html() is not shadowed by stubs.php's.
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/**
 * The controller's base class, stubbed: a redirect is recorded rather than taken (the real
 * one exits), and the section permission check has already happened by the time doModel()
 * runs.
 */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function doModel()
    {
    }

    public function isModerator()
    {
        return false;
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }

    /** The admin theme's view for this screen, drawn where the real one would draw it. */
    public function doView($view)
    {
        // The form view declares functions, so it can be drawn once per process.
        if (!isset($GLOBALS['drawnViews'][$view])) {
            $GLOBALS['drawnViews'][$view] = true;
            include ABS_PATH . 'oc-admin/themes/modern/' . $view;
        }
    }

    /** Mirrors the real guard: flashes, redirects, and says that it refused. */
    protected function refuseOnDemo($redirectUrl = null)
    {
        if (!defined('DEMO')) {
            return false;
        }
        osc_add_flash_warning_message('This action cannot be done because it is a demo site', 'admin');
        $this->redirectTo($redirectUrl ?? osc_admin_base_url(true));

        return true;
    }
}

if (!function_exists('osc_add_flash_info_message')) {
    function osc_add_flash_info_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('info', $msg);
    }
}
if (!function_exists('_e')) {
    function _e($text)
    {
        echo $text;
    }
}
if (!function_exists('_ex')) {
    function _ex($text, $context = '')
    {
        echo $text;
    }
}
if (!function_exists('osc_purge_page_cache')) {
    function osc_purge_page_cache($group = '')
    {
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCurrencies.php';

$GLOBALS['flashes']    = array();
$GLOBALS['redirects']  = array();
$GLOBALS['drawnViews'] = array();

function drive(array $fields = array(), array $query = array()): array
{
    $_GET  = $query + array('page' => 'settings', 'action' => 'currencies');
    $_POST = $fields;
    Params::init();
    $GLOBALS['flashes']    = array();
    $GLOBALS['redirects']  = array();
    $GLOBALS['csrfChecks'] = array();
    View::getInstance()->_exportVariableToView('aCurrency', null);

    ob_start();
    $controller = new CAdminSettingsCurrencies();
    $controller->doModel();
    $drawn = (string) ob_get_clean();

    return array(
        'flashes'  => $GLOBALS['flashes'],
        'csrf'     => $GLOBALS['csrfChecks'],
        'drawn'    => $drawn,
        'currency' => View::getInstance()->_get('aCurrency'),
    );
}

function decimals_of(mysqli $admin, string $code): ?int
{
    $res = $admin->query('SELECT i_decimals FROM ' . DB_TABLE_PREFIX . "t_currency WHERE pk_c_code = '" . $admin->real_escape_string($code) . "'");
    $row = $res ? $res->fetch_assoc() : null;

    return $row ? (int) $row['i_decimals'] : null;
}

function decimals_box(string $html): ?string
{
    return preg_match('/<input type="number"[^>]*name="i_decimals"[^>]*value="([^"]*)"/', $html, $m)
        || preg_match('/<input type="number"[^>]*value="([^"]*)"[^>]*name="i_decimals"/', $html, $m) ? $m[1] : null;
}

harness_section('the add form prefills Decimals from the code');

$r = drive(array(), array('type' => 'add', 'code' => 'JPY'));
pin('JPY is prefilled with 0', '0', decimals_box($r['drawn']));
$r = drive(array(), array('type' => 'add', 'code' => 'KWD'));
pin('KWD is prefilled with 3', 3, $r['currency']['i_decimals']);
$r = drive(array(), array('type' => 'add'));
pin('no code gives the usual 2', 2, $r['currency']['i_decimals']);

harness_section('a save stores the decimals that were typed');

$r = drive(array('type' => 'add_post', 'pk_c_code' => 'JPY', 's_name' => 'Yen', 's_description' => '¥', 'i_decimals' => '0'));
pin('JPY added with 0', 0, decimals_of($admin, 'JPY'));
$r = drive(array('type' => 'add_post', 'pk_c_code' => 'CHF', 's_name' => 'Franc', 's_description' => 'Fr'));
pin('an empty box falls back to the code default (2)', 2, decimals_of($admin, 'CHF'));
$r = drive(array('type' => 'add_post', 'pk_c_code' => 'KWD', 's_name' => 'Dinar', 's_description' => 'KD'));
pin('an empty box on KWD gives 3', 3, decimals_of($admin, 'KWD'));
$r = drive(array('type' => 'edit_post', 'pk_c_code' => 'CHF', 's_name' => 'Franc', 's_description' => 'Fr', 'i_decimals' => '3'));
pin('editing to 3 stores 3', 3, decimals_of($admin, 'CHF'));
pin('the save is CSRF-checked', true, $r['csrf'] !== array());

harness_section('a bad value is refused with a message');

$r = drive(array('type' => 'edit_post', 'pk_c_code' => 'CHF', 's_name' => 'Franc', 's_description' => 'Fr', 'i_decimals' => '9'));
pin('9 on edit leaves the stored 3', 3, decimals_of($admin, 'CHF'));
pin('9 on edit says why', array(array('error', 'Decimals must be between 0 and 4.')), $r['flashes']);
$r = drive(array('type' => 'add_post', 'pk_c_code' => 'SEK', 's_name' => 'Krona', 's_description' => 'kr', 'i_decimals' => '9'));
pin('9 on add stores nothing', null, decimals_of($admin, 'SEK'));
pin('9 on add says why', array(array('error', 'Decimals must be between 0 and 4.')), $r['flashes']);

harness_section('the edit form and the list show it');

$r = drive(array(), array('type' => 'edit', 'code' => 'CHF'));
pin('edit carries the stored 3', 3, (int) $r['currency']['i_decimals']);
$r = drive();
check('the list has a Decimals column', str_contains($r['drawn'], '<th scope="col">Decimals</th>'));
check('and the JPY row shows 0', (bool) preg_match('/data-col-name="Decimals"[^>]*>\s*0\s*</', $r['drawn']), $r['drawn']);

exit(harness_result());
