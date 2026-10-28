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
 * The shopper account in shop mode: the seller pages send a shopper to the dashboard, orders and
 * receipts stay open, the dashboard lists orders; an admin session and classifieds mode see
 * every page as before.
 *
 * Usage:  php tests/models/shopper-account.php       (standalone, own scratch database)
 *         php tests/run-models.php shopper-account   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

// URL helpers carry their arguments, so a redirect target or a receipt link can be pinned.
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = [])
    {
        return WEB_PATH . 'route/' . $name . ($args ? '?' . http_build_query($args) : '');
    }
}
if (!function_exists('osc_locate_template')) {
    function osc_locate_template($names, $fallback = '')
    {
        return $fallback;
    }
}

$_SESSION = [];
$admin    = api_admin_boot('osc_models_shopper_account');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hMessages.php';

// The account nav's links and page tests, from helpers a model test does not load.
foreach (['osc_user_dashboard_url' => 'user_dashboard', 'osc_user_list_items_url' => 'user_items', 'osc_user_alerts_url' => 'user_alerts', 'osc_user_profile_url' => 'user_profile', 'osc_change_user_email_url' => 'user_change_email', 'osc_user_logout_url' => 'user_logout', 'osc_item_post_url_in_category' => 'item_add'] as $fn => $route) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { return osc_core_url("' . $route . '"); }');
    }
}
foreach (['osc_is_user_dashboard', 'osc_is_list_items', 'osc_is_list_alerts', 'osc_is_user_profile', 'osc_is_change_email_page', 'osc_is_change_password_page', 'osc_is_change_username_page', 'osc_is_messages_page', 'osc_is_current_page', 'osc_get_osclass_location', 'osc_show_flash_message', 'osc_gui_listing_list'] as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { return false; }');
    }
}
if (!function_exists('osc_count_items')) {
    function osc_count_items()
    {
        return count((array) View::getInstance()->_get('items'));
    }
}
if (!function_exists('_mn')) {
    function _mn($single, $plural, $count)
    {
        return $count === 1 ? $single : $plural;
    }
}

use mindstellar\billing\Billing;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;

$p  = DB_TABLE_PREFIX;
$en = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Goods', null, $en, 1, 30);
$amy = seed_user($admin, 'amy', 'amy@example.test');
$adm = api_admin_seed_admin($admin, 'shopadmin');
seed_item($admin, $cat, $amy, 'Amy listing');
$held = seed_item($admin, $cat, $amy, 'Amy held');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$held}");
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('site_mode', 'shop', 'osclass', 'STRING');
osc_set_preference('api_enabled', '1', 'api', 'BOOLEAN');
osc_reset_preferences();

$orderIds = [];
foreach ([1_000_000, 2_500_000] as $amount) {
    $order = OrderStore::create($amy, 'fake', $amount, 'USD', 5);
    Billing::markPaid($order, 'ref-' . $amount);
    $orderIds[] = $order->getId();
}
sort($orderIds);

/** Thrown in place of the exit() a real redirect ends the request with. */
final class ShopperRedirect extends RuntimeException
{
}

/** Records the view and the redirect instead of taking them. */
trait ShopperStandIn
{
    public function doView($file)
    {
        $GLOBALS['view'] = $file;
    }

    public function redirectTo($url, $code = null)
    {
        throw new ShopperRedirect((string) $url);
    }

    public function do404()
    {
        throw new ShopperRedirect('404');
    }
}

final class TestShopperUser extends CWebUser
{
    use ShopperStandIn;
}

final class TestShopperPublic extends CWebUserNonSecure
{
    use ShopperStandIn;
}

final class TestShopperBilling extends CWebBilling
{
    use ShopperStandIn;
}

/** Sign in as a user (or nobody), with an admin session or not. */
$signIn = static function (?int $userId, ?int $adminId = null): void {
    $view = View::getInstance();
    $view->_erase('_loggedUser');
    $session = Session::getInstance();
    foreach (['userId', 'userEmail', 'userName', 'adminId', 'adminStamp'] as $k) {
        $session->_drop($k);
        $session->_dropEphemeral($k);
    }
    if ($userId !== null) {
        $view->_exportVariableToView('_loggedUser', User::getInstance()->findByPrimaryKey($userId));
        osc_is_web_user_logged_in();
    }
    if ($adminId !== null) {
        $session->_set('adminId', $adminId);
        $session->_set('adminStamp', 0);
    }
};

/** One request: where it redirected, which view it drew and what it printed. */
$drive = static function (string $class, string $page, string $action, array $get = [], bool $post = false): array {
    $_GET = $_REQUEST = ['page' => $page, 'action' => $action] + $get;
    $_POST = [];
    if ($post) {
        $csrf  = new \mindstellar\security\Csrf();
        $_POST = ['CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()];
    }
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    Params::init();
    $GLOBALS['view'] = null;
    foreach (['items', 'orders'] as $key) {
        View::getInstance()->_erase($key);
    }

    $redirect = null;
    ob_start();
    try {
        // Without the constructor: it checks the sign-in and boots the theme.
        $web = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['page' => $page, 'action' => $action] as $name => $value) {
            $prop = new ReflectionProperty('BaseModel', $name);
            $prop->setAccessible(true);
            $prop->setValue($web, $value);
        }
        $web->doModel();
    } catch (ShopperRedirect $e) {
        $redirect = $e->getMessage();
    } catch (Throwable $e) {
        $redirect = 'error: ' . $e->getMessage();
    }

    return ['redirect' => $redirect, 'view' => $GLOBALS['view'], 'drawn' => (string) ob_get_clean()];
};

$dashboard = osc_core_url('user_dashboard');
$hidden    = [
    'items'           => ['TestShopperUser', 'user', 'items', [], false],
    'alerts'          => ['TestShopperUser', 'user', 'alerts', [], false],
    'api_access'      => ['TestShopperUser', 'user', 'api_access', [], false],
    'api_access_post' => ['TestShopperUser', 'user', 'api_access_post', [], true],
    'wallet'          => ['TestShopperBilling', 'billing', '', [], false],
    'buy'             => ['TestShopperBilling', 'billing', 'buy', [], false],
    'checkout'        => ['TestShopperBilling', 'billing', 'checkout', [], true],
    'upgrade'         => ['TestShopperBilling', 'billing', 'upgrade', [], true],
    'account_upgrade' => ['TestShopperBilling', 'billing', 'account_upgrade', [], true],
    'publish'         => ['TestShopperBilling', 'billing', 'publish', ['itemId' => (string) $held], false],
    'publish (POST)'  => ['TestShopperBilling', 'billing', 'publish', ['itemId' => (string) $held], true],
    'pub_profile'     => ['TestShopperPublic', 'user', 'pub_profile', ['id' => (string) $amy], false],
];
$views = [
    'items'       => 'user-items',
    'alerts'      => 'user-alerts',
    'api_access'  => 'user-api_access',
    'wallet'      => 'user-billing-wallet.php',
    'buy'         => 'user-billing-buy.php',
    'publish'     => 'user-billing-publish.php',
    'pub_profile' => 'user-public-profile',
];

harness_section('shop mode, a shopper');
$signIn($amy);
check('Amy is a shopper', osc_is_shopper());
foreach ($hidden as $name => [$class, $page, $action, $get, $post]) {
    // A shop has no credits pages and no public profile, so those are 404 rather than a redirect.
    $billing = $class === 'TestShopperBilling' || $action === 'pub_profile';
    pin($billing ? "$name is 404" : "$name sends her to the dashboard", $billing ? '404' : $dashboard, $drive($class, $page, $action, $get, $post)['redirect']);
}
pin('the held listing is untouched', null, $admin->query("SELECT dt_first_pub_date FROM {$p}t_item WHERE pk_i_id = {$held}")->fetch_row()[0]);
$orders = $drive('TestShopperBilling', 'billing', 'orders');
pin('orders renders', [null, 'user-billing-orders.php'], [$orders['redirect'], $orders['view']]);
$receipt = $drive('TestShopperBilling', 'billing', 'receipt', ['id' => (string) $orderIds[0]]);
check('a receipt renders', $receipt['redirect'] === null && str_contains($receipt['drawn'], osc_billing_order_number($orderIds[0])));

$dash = $drive('TestShopperUser', 'user', 'dashboard');
pin('the dashboard renders', [null, 'user-dashboard'], [$dash['redirect'], $dash['view']]);
$exported = View::getInstance()->_get('orders');
$ids      = array_map(static fn ($o) => $o->getId(), is_array($exported) ? $exported : []);
sort($ids);
pin('it exports her two orders', $orderIds, $ids);
pin('and no listings', [], View::getInstance()->_get('items'));
ob_start();
require ABS_PATH . 'oc-includes/osclass/gui/account/user-dashboard-content.php';
$html = (string) ob_get_clean();
check('the partial says Your recent orders', str_contains($html, 'Your recent orders'));
foreach ($orderIds as $id) {
    check("it links order $id's receipt", str_contains($html, htmlspecialchars(Receipts::url($id))));
}
check('it offers no Publish a listing', !str_contains($html, 'Publish a listing'));
check('it links all orders', str_contains($html, 'See all of your orders'));
check('the nav has Your orders and no Your listings', str_contains($html, 'opt_billing_orders') && !str_contains($html, 'opt_items'));

harness_section('a guest in shop mode');
$signIn(null);
pin('a public profile is 404 for a guest', '404', $drive('TestShopperPublic', 'user', 'pub_profile', ['id' => (string) $amy])['redirect']);

harness_section('shop mode, with an admin session');
$signIn($amy, $adm);
check('Amy with an admin session is no shopper', !osc_is_shopper());
$r = $drive('TestShopperUser', 'user', 'items');
pin('items renders', [null, 'user-items'], [$r['redirect'], $r['view']]);
$r = $drive('TestShopperPublic', 'user', 'pub_profile', ['id' => (string) $amy]);
pin('pub_profile is 404 for a shopper with an admin session', '404', $r['redirect']);
$signIn(null, $adm);
$r = $drive('TestShopperPublic', 'user', 'pub_profile', ['id' => (string) $amy]);
pin('an admin alone gets a 404 for the public profile', '404', $r['redirect']);

harness_section('classifieds mode');
osc_set_preference('site_mode', 'classifieds', 'osclass', 'STRING');
osc_reset_preferences();
$signIn($amy);
check('Amy is no shopper', !osc_is_shopper());
foreach ($views as $name => $view) {
    [$class, $page, $action, $get, $post] = $hidden[$name];
    $r = $drive($class, $page, $action, $get, $post);
    pin("$name renders", [null, $view], [$r['redirect'], $r['view']]);
}
$dash = $drive('TestShopperUser', 'user', 'dashboard');
pin('the dashboard renders', [null, 'user-dashboard'], [$dash['redirect'], $dash['view']]);
pin('it exports her listings, no orders', [1, false], [count((array) View::getInstance()->_get('items')), View::getInstance()->_exists('orders')]);
$signIn(null);
$r = $drive('TestShopperPublic', 'user', 'pub_profile', ['id' => (string) $amy]);
pin('a guest sees the public profile', [null, 'user-public-profile'], [$r['redirect'], $r['view']]);

harness_section('one list of seller pages');
$listed = [];
foreach ($hidden as [$class, $page, $action]) {
    $listed[] = osc_shopper_page_hidden($page, $action);
}
pin('every page above is on it', array_fill(0, count($hidden), true), $listed);
pin('dashboard, orders, receipt and other pages are not', [false, false, false, false], [osc_shopper_page_hidden('user', 'dashboard'), osc_shopper_page_hidden('billing', 'orders'), osc_shopper_page_hidden('billing', 'receipt'), osc_shopper_page_hidden('item', 'item_add')]);

exit(harness_result());
