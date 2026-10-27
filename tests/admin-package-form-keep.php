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
 * A refused package save draws the form again with what was typed and names each refused
 * field, instead of redirecting with nothing kept.
 * Usage: php tests/admin-package-form-keep.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_admin_package_keep');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_e' => 'echo $key;', '_n' => 'return $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $plural = "", $n = 0) { ' . $body . ' }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
    }
}
if (!function_exists('osc_current_admin_locale')) {
    function osc_current_admin_locale()
    {
        return 'en_US';
    }
}
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

/** The base controller, stubbed: redirects are recorded, and the view is drawn into the output. */
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

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }

    public function doView($view)
    {
        include ABS_PATH . 'oc-admin/themes/modern/' . $view;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminBilling.php';

/** One request to the screen. */
function drive(string $action, array $fields = array(), array $query = array()): array
{
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = $query + array('page' => 'billing', 'action' => $action);
    $_POST = $fields + array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue());
    Params::init();
    $GLOBALS['flashes']   = array();
    $GLOBALS['redirects'] = array();

    ob_start();
    (new CAdminBilling())->doModel();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'drawn'     => (string) ob_get_clean(),
    );
}


use mindstellar\billing\PackageStore;

harness_section('a refused save');
$page = drive('package_post', ['name' => '', 'amount' => 'abc', 'currency' => 'USD', 'credits' => '0', 'position' => '2']);
$html = $page['drawn'];
pin('no redirect', 0, count($page['redirects']));
check('the form is drawn again', str_contains($html, 'name="action" value="package_post"'));
check('the typed currency and position are kept', str_contains($html, 'value="USD"') && str_contains($html, 'value="2"'));
check('the typed amount is kept', str_contains($html, 'value="abc"'));
foreach (['Enter a name for this package', 'Enter a valid amount, 0 or more', 'Credits must be at least 1'] as $msg) {
    check("names the field: $msg", str_contains($html, $msg));
}
check('the fields are marked invalid', substr_count($html, 'aria-invalid="true"') === 3);

harness_section('a refused edit keeps the package id');
$id = PackageStore::create(['s_name' => 'Starter', 'i_amount' => 5000000, 's_currency' => 'USD', 'i_credits' => 10, 'i_position' => 0, 'b_enabled' => 1]);
$id = is_int($id) ? $id : (int) ($id['pk_i_id'] ?? $id);
$page = drive('package_post', ['id' => $id, 'name' => 'Starter plus', 'amount' => '-1', 'currency' => 'USD', 'credits' => '10']);
check('the name typed is shown, not the stored one', str_contains($page['drawn'], 'value="Starter plus"') && !str_contains($page['drawn'], 'value="Starter"'));
check('the id is posted again', str_contains($page['drawn'], 'name="id" value="' . $id . '"'));
pin('nothing was saved', 'Starter', PackageStore::find($id)['s_name']);

harness_section('a good save');
$page = drive('package_post', ['id' => $id, 'name' => 'Starter plus', 'amount' => '6', 'currency' => 'USD', 'credits' => '12']);
pin('saves and redirects', ['Starter plus', 1], [PackageStore::find($id)['s_name'], count($page['redirects'])]);

exit(harness_result());
