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
 * The admin order page for fulfilment: start processing, send a parcel of chosen quantities,
 * correct one parcel's tracking, the packed label, and the Shipments and History panels. Every
 * post checks the CSRF token and the order's status.
 * Usage: php tests/admin-order-fulfilment.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_admin_order_fulfilment');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'site_mode' => 'shop'] as $k => $v) {
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

$m = static fn (float $units): int => (int) round($units * 1000000);

/** A goods order: Tee x3, Mug x1 and a shipping line; paid unless $pay is false. */
$make = static function (string $gateway = 'offline', bool $pay = true) use ($m, $tom): array {
    $order = OrderStore::create($tom, $gateway, $m(60), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee x3']);
    OrderLineStore::add($order->getId(), [
        ['kind' => 'item', 'item_id' => 1, 'title' => 'Tee', 'qty' => 3, 'unit' => $m(10), 'amount' => $m(30)],
        ['kind' => 'item', 'item_id' => 2, 'title' => 'Mug <b>', 'qty' => 1, 'unit' => $m(15), 'amount' => $m(15)],
        ['kind' => 'shipping', 'title' => 'Post', 'amount' => $m(5)],
    ]);
    if ($pay) {
        Billing::markPaid($order, null);
    }
    $ids = array_map(static fn (array $l): int => (int) $l['id'], OrderLineStore::ofOrder($order->getId()));

    return [$order->getId(), $ids[0], $ids[1], $ids[2]];
};
$status = static fn (int $id): string => OrderStore::find($id)->getStatus();
$said   = static fn (array $page, string $word): bool => str_contains(implode(' ', $page['flashes']), $word);

harness_section('start processing');
[$id, $tee, $mug, $post] = $make();
$page = drive('order', [], ['id' => $id]);
check('a paid order offers Start processing and the shipment form with the open quantity', str_contains($page['drawn'], 'value="order_processing"') && str_contains($page['drawn'], 'value="order_shipment"') && str_contains($page['drawn'], 'name="qty[' . $tee . ']" value="3"') && str_contains($page['drawn'], 'name="qty[' . $mug . ']" value="1"') && !str_contains($page['drawn'], 'name="qty[' . $post . ']"'));
check('an item title is escaped', str_contains($page['drawn'], 'Mug &lt;b&gt;') && !str_contains($page['drawn'], 'Mug <b>'));
$old = OrderStore::create($tom, 'offline', $m(5), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Old']);
$admin->query("UPDATE {$p}t_billing_order SET s_status = 'paid' WHERE pk_i_id = " . $old->getId());
check('an order with no history says so, quietly', str_contains(drive('order', [], ['id' => $old->getId()])['drawn'], 'No history kept before 7.1') && !str_contains($page['drawn'], 'No history kept before 7.1'));
$page = drive('order_processing', ['id' => $id]);
pin('processing starts', ['processing', true], [$status($id), $said($page, 'is being processed')]);
check('the history holds the move, by an admin', (static function () use ($id): bool {
    $h = OrderStore::find($id)->history();

    return $h !== [] && end($h)['to'] === 'processing';
})());
$page = drive('order_processing', ['id' => $id]);
pin('not twice', ['processing', true], [$status($id), $said($page, 'warning:')]);
$page = drive('order', [], ['id' => $id]);
check('the page shows the status, no Start processing, and still the shipment form', str_contains($page['drawn'], 'status-processing') && !str_contains($page['drawn'], 'value="order_processing"') && str_contains($page['drawn'], 'value="order_shipment"'));
$list = drive('index', [], ['status' => 'processing']);
check('Processing is a list filter and the order is in it', str_contains($list['drawn'], 'value="processing"') && str_contains($list['drawn'], 'Tee x3'));
[$pend] = $make('offline', false);
$page = drive('order_processing', ['id' => $pend]);
pin('a pending bank order cannot start processing', ['pending', true], [$status($pend), $said($page, 'warning:')]);
[$cod] = $make('cod', false);
drive('order_processing', ['id' => $cod]);
pin('an unpaid cash on delivery order can', 'processing', $status($cod));
$page = drive('order', [], ['id' => $cod]);
check('and may still be cancelled', str_contains($page['drawn'], 'order-cancel-dialog'));
drive('order_cancel', ['id' => $cod]);
pin('a refused cash on delivery parcel is cancelled', 'cancelled', $status($cod));

harness_section('parcels');
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DHL', 'qty' => [$tee => '0', $mug => '0']]);
pin('nothing chosen sends nothing', [1, true], [count(OrderStore::find($id)->shipments()) + 1, $said($page, 'Choose how many')]);
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DHL', 'qty' => [$tee => '4']]);
pin('more than ordered is refused', [0, true], [count(OrderStore::find($id)->shipments()), $said($page, 'warning:')]);
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DHL', 'qty' => [$post => '1']]);
pin('a shipping line is refused', 0, count(OrderStore::find($id)->shipments()));
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DHL', 'trackingUrl' => 'http://bad.test', 'qty' => [$tee => '2']]);
pin('a link that is not https redraws the form and sends nothing', [0, true], [count(OrderStore::find($id)->shipments()), str_contains($page['drawn'], 'https://')]);
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DHL', 'tracking' => 'P1', 'trackingUrl' => 'https://t.test/P1', 'qty' => [$tee => '2', $mug => '0']]);
$o = OrderStore::find($id);
pin('the first parcel is sent and the order stays processing', ['processing', 1, [$tee => 2], true], [$o->getStatus(), count($o->shipments()), $o->shippedQty(), $said($page, 'Parcel 1')]);
$page = drive('order', [], ['id' => $id]);
check('the page lists the parcel with its tracking link and edit form, and offers the rest', str_contains($page['drawn'], 'href="https://t.test/P1"') && str_contains($page['drawn'], 'value="order_shipment_tracking"') && str_contains($page['drawn'], 'name="qty[' . $tee . ']" value="1"') && str_contains($page['drawn'], 'Send another parcel'));
$page = drive('order_shipment_tracking', ['id' => $id, 'n' => 1, 'carrier' => 'UPS', 'tracking' => 'P1b', 'trackingUrl' => 'https://ups.test/P1b']);
$s = OrderStore::find($id)->shipments();
pin('one parcel\'s tracking is corrected', ['UPS', 'P1b', 'https://ups.test/P1b'], [$s[0]['carrier'], $s[0]['tracking'], $s[0]['url']]);
$page = drive('order_shipment_tracking', ['id' => $id, 'n' => 9, 'carrier' => 'UPS']);
pin('an unknown parcel is refused', true, $said($page, 'could not be found'));
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DPD', 'qty' => [$tee => '1', $mug => '1']]);
$o = OrderStore::find($id);
pin('the parcel with the last open units ships the order', ['shipped', 2, true], [$o->getStatus(), count($o->shipments()), $said($page, 'marked shipped')]);
$page = drive('order_shipment', ['id' => $id, 'carrier' => 'DPD', 'qty' => [$tee => '1']]);
pin('a shipped order takes no more parcels', 2, count(OrderStore::find($id)->shipments()));
$page = drive('order', [], ['id' => $id]);
check('the shipped page offers delivery and no shipment form', str_contains($page['drawn'], 'value="order_delivered"') && !str_contains($page['drawn'], 'value="order_shipment"'));
$page = drive('order_delivered', ['id' => $id]);
pin('then it is delivered', 'delivered', $status($id));
$hist = OrderStore::find($id)->history();
pin('the history kept every move in order', ['paid', 'processing', 'shipped', 'delivered'], array_column($hist, 'to'));
$page = drive('order', [], ['id' => $id]);
check('the History panel lists the moves, newest first', strpos($page['drawn'], 'Shipped to Delivered') !== false && strpos($page['drawn'], 'Shipped to Delivered') < strpos($page['drawn'], 'Paid to Processing'));

harness_section('an order with no item lines');
$bare = OrderStore::create($tom, 'offline', $m(5), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Old']);
Billing::markPaid($bare, null);
$page = drive('order', [], ['id' => $bare->getId()]);
check('the form has no quantity boxes', str_contains($page['drawn'], 'value="order_shipment"') && !str_contains($page['drawn'], 'name="qty['));
drive('order_shipment', ['id' => $bare->getId(), 'carrier' => 'DHL']);
pin('it ships whole', 'shipped', $status($bare->getId()));

harness_section('packed');
[$pk] = $make();
$page = drive('order', [], ['id' => $pk]);
check('an order that can ship offers Mark packed', str_contains($page['drawn'], 'value="order_packed"') && str_contains($page['drawn'], 'Mark packed'));
$page = drive('order_packed', ['id' => $pk, 'packed' => 1]);
$packed = OrderStore::find($pk)->packed();
pin('it is marked, without a status change', ['paid', true, true], [$status($pk), $packed !== null, $said($page, 'marked packed')]);
check('the page shows Packed and offers to clear it', str_contains(drive('order', [], ['id' => $pk])['drawn'], 'Clear packed mark'));
drive('order_packed', ['id' => $pk, 'packed' => 0]);
pin('and it clears', null, OrderStore::find($pk)->packed());
pin('a delivered order cannot be marked packed', true, $said(drive('order_packed', ['id' => $id, 'packed' => 1]), 'warning:'));

harness_section('guards');
foreach (['order_processing', 'order_shipment', 'order_shipment_tracking', 'order_packed'] as $action) {
    $page   = drive($action, ['id' => 999999]);
    check("$action on a missing order is not found", $said($page, 'no longer exists'));
}
$credits = OrderStore::create($tom, 'offline', $m(1), 'USD', 5);
Billing::markPaid($credits, null);
check('a credits order is refused by every fulfilment post', (static function () use ($credits, $said): bool {
    foreach (['order_processing', 'order_shipment', 'order_shipment_tracking', 'order_packed'] as $action) {
        if (!$said(drive($action, ['id' => $credits->getId()]), 'no longer exists')) {
            return false;
        }
    }

    return true;
})());
$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminBilling.php');
foreach (['orderProcessingPost', 'orderShipmentPost', 'orderShipmentTrackingPost', 'orderPackedPost'] as $method) {
    $at = strpos($src, 'private function ' . $method);
    check("$method checks the CSRF token first", $at !== false && str_contains(substr($src, $at, 120), 'osc_csrf_check()'));
}
exit(harness_result());
