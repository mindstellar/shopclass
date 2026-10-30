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
 * A site that switched from shop to classifieds still knows cash on delivery, so old orders
 * keep their method and can still be viewed, cancelled and shipped, but checkout and credit
 * purchases no longer offer it.
 * Usage: php tests/billing-cod-classifieds.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGatewayRegistry;

$admin = api_admin_boot('osc_billing_cod_classifieds');
seed_locale($admin);
seed_currency($admin);
$tom = seed_user($admin, 'tom', 'tom@example.test');
$setMode = static function (string $mode): void {
    foreach (['site_mode' => $mode, 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_cod_enabled' => '1', 'billing_offline_enabled' => '1', 'billing_offline_instructions' => 'Pay to account 123'] as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};

$setMode('shop');
$codOrder = static fn (): Order => OrderStore::create($tom, 'cod', 10000000, 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee']);
$toShip   = $codOrder();
$toCancel = $codOrder();

$setMode('classifieds');
$registry = PaymentGatewayRegistry::getInstance();
harness_section('classifieds mode');
pin('an old cash on delivery order still finds its gateway', true, $registry->get('cod') !== null);
pin('so it keeps its method name', true, $registry->get('cod')?->getName() !== null);
pin('checkout does not offer it', false, isset($registry->available('USD')['cod']));
pin('credit purchases do not offer it', false, isset(osc_billing_gateways()['cod']));
pin('bank transfer is still offered', true, isset(osc_billing_gateways()['offline']));

harness_section('an old cash on delivery order in classifieds mode');
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
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
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

/** One request to the admin billing screen; returns its flashes and what it drew. */
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

    return array('flashes' => implode(' ', $GLOBALS['flashes']), 'drawn' => (string) ob_get_clean());
}

pin('the order list names its method', 'Cash on delivery', osc_billing_gateway_name('cod'));
$page = drive('order', [], ['id' => $toShip->getId()]);
check('the order page names its method', str_contains($page['drawn'], 'Cash on delivery'), $page['flashes']);
check('and does not call the plugin inactive', !str_contains($page['drawn'], 'is not active'));
$page = drive('order_ship', ['id' => $toShip->getId(), 'carrier' => 'DHL', 'tracking' => 'JD1']);
pin('it can still be shipped', ['shipped', true], [OrderStore::find($toShip->getId())->getStatus(), str_contains($page['flashes'], 'marked shipped')]);
$page = drive('order_cancel', ['id' => $toCancel->getId()]);
pin('it can still be cancelled', ['cancelled', true], [OrderStore::find($toCancel->getId())->getStatus(), str_contains($page['flashes'], 'is cancelled')]);

harness_section('shop mode');
$setMode('shop');
pin('checkout offers it again', true, isset($registry->available('USD')['cod']));
pin('a cart in another currency can pay by bank transfer or cash', [true, true], [isset($registry->available('INR')['offline']), isset($registry->available('INR')['cod'])]);
pin('credit purchases still pay in the billing currency only', ['USD'], $registry->get('offline')->getSupportedCurrencies());

exit(harness_result());
