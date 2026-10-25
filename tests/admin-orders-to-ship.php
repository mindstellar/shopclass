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
 * Orders waiting to ship: the count (paid goods orders and unpaid cash on delivery ones, not
 * credit packages, shipped or cancelled), its short cache cleared when an order moves, the
 * badge on the Billing and Orders menu entries (hidden at zero), and the dashboard card
 * listing the oldest first.
 * Usage: php tests/admin-orders-to-ship.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_admin_orders_to_ship');
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
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';

require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';

$m    = static fn (float $units): int => (int) round($units * 1000000);
$goods = static function (string $gateway, float $units = 10) use ($m, $tom): Order {
    return OrderStore::create($tom, $gateway, $m($units), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee']);
};
if (!function_exists('osc_is_moderator')) {
    function osc_is_moderator()
    {
        return false;
    }
}
$cache = \mindstellar\cache\CacheManager::getInstance();

/** The sidebar entries Billing and Orders, as the menu holds them. */
$menu = static function (): array {
    $m = new AdminMenu();
    $m->init();
    $all = $m->get_array_menu();

    return array($all['billing'][0] ?? '', $all['billing']['sub']['billing_orders'][0] ?? '', $all['billing']['sub']['billing_orders'][1] ?? '');
};

harness_section('the count');
$cache->delete(OrderStore::TO_SHIP_CACHE_KEY);
pin('nothing waits at first', 0, OrderStore::toShipCount());
$paid = $goods('offline');
Billing::markPaid($paid, null);
$cod  = $goods('cod');
$bank = $goods('offline');
$pack = OrderStore::create($tom, 'offline', $m(5), 'USD', 5);
Billing::markPaid($pack, null);
$sent = $goods('offline');
Billing::markPaid($sent, null);
OrderStore::ship($sent->getId(), 'DHL', 'JD1', '');
$gone = $goods('cod');
OrderStore::cancel($gone->getId());
pin('paid goods and unpaid cash on delivery count; a pending bank order, a credit package, a shipped and a cancelled one do not', 2, OrderStore::toShipCount());

harness_section('the cache');
$cache->delete(OrderStore::TO_SHIP_CACHE_KEY);
OrderStore::toShipCount();
$found = false;
pin('the count is kept', 2, $cache->get(OrderStore::TO_SHIP_CACHE_KEY, $found));
OrderStore::ship($paid->getId(), 'DHL', 'JD2', '');
$cache->get(OrderStore::TO_SHIP_CACHE_KEY, $found);
check('shipping clears it', !$found);
pin('and the next count is fresh', 1, OrderStore::toShipCount());
Billing::markPaid($bank, null);
pin('paying an order adds it at once', 2, OrderStore::toShipCount());

harness_section('the menu badge');
[$billing, $orders, $url] = $menu();
check('Billing and Orders carry the number', str_contains($billing, '>2<') && str_contains($orders, '>2<'));
check('Orders opens the To ship list', str_contains($url, 'toShip=1'));
foreach ([$bank, $cod] as $o) {
    $o->getGateway() === 'cod' ? OrderStore::ship($o->getId(), 'DHL', 'JD3', '') : OrderStore::ship($o->getId(), 'DHL', 'JD4', '');
}
pin('nothing waits now', 0, OrderStore::toShipCount());
[$billing, $orders, $url] = $menu();
check('at zero there is no badge and Orders opens the plain list', !str_contains($billing, 'osc-count') && !str_contains($orders, 'osc-count') && !str_contains($url, 'toShip'));

harness_section('the dashboard card');
Preference::getInstance()->set('site_mode', 'shop');
scratchdb_forget_cache();
osc_reset_preferences();
$first  = $goods('offline', 11);
$second = $goods('offline', 12);
$third  = $goods('offline', 13);
foreach ([$third, $first, $second] as $i => $o) {
    Billing::markPaid($o, null);
    $admin->query("UPDATE {$p}t_billing_order SET dt_date = '2026-01-0" . ($i + 1) . " 10:00:00' WHERE pk_i_id = " . $o->getId());
}
pin('the oldest come first', [$third->getId(), $first->getId(), $second->getId()], array_map(static fn (Order $o): int => $o->getId(), OrderStore::oldestToShip(5)));
pin('and the list is capped', 2, count(OrderStore::oldestToShip(2)));
exit(harness_result());
