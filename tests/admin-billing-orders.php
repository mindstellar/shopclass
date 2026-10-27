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
 * The admin order screen for goods orders: it lists lines, address and buyer, shows the
 * oversold pill with the short lines, and ships, delivers and cancels. Each post checks
 * the CSRF token before it touches an order and refuses the wrong status; a tracking link has to be https; the
 * "To ship" list shows paid goods orders only; titles are escaped on the page.
 * Usage: php tests/admin-billing-orders.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_admin_billing_orders');
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

$m       = static fn (float $units): int => (int) round($units * 1000000);
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];
$make    = static function (string $gateway = 'offline', string $title = 'Tee') use ($m, $tom, $address): Order {
    $order = OrderStore::create($tom, $gateway, $m(1447), 'USD', 0, [
        '_kind'     => Order::KIND_GOODS,
        '_label'    => $title . ' × 2',
        '_address'  => $address,
        '_shipping' => ['method' => 'standard', 'label' => 'Standard delivery'],
        '_buyer'    => ['email' => 'tom@example.test', 'name' => 'Tom', 'phone' => '5550100'],
    ]);
    OrderLineStore::add($order->getId(), [
        ['kind' => 'item', 'item_id' => 1, 'variant_id' => 1, 'title' => $title, 'options' => ['Size' => 'M'], 'sku' => 'TEE-M', 'qty' => 2, 'unit' => $m(699), 'amount' => $m(1398)],
        ['kind' => 'shipping', 'title' => 'Standard delivery', 'amount' => $m(49)],
    ]);

    return $order;
};
$status = static fn (int $id): string => OrderStore::find($id)->getStatus();

harness_section('the order page');
$order = $make('offline', 'Tee <script>x</script>');
$id    = $order->getId();
$page  = drive('order', [], ['id' => $id]);
$html  = $page['drawn'];
foreach (['TEE-M', 'Size: M', 'Standard delivery', '1 Main St', 'Austin', 'tom@example.test', '5550100'] as $word) {
    check("shows $word", str_contains($html, $word));
}
check('a seller title is escaped', str_contains($html, 'Tee &lt;script&gt;x&lt;/script&gt;') && !str_contains($html, '<script>x'));
check('a pending goods order offers mark paid and cancel, not shipping', str_contains($html, 'value="order_paid"') && str_contains($html, 'order-cancel-dialog') && str_contains($html, 'order_cancel') && !str_contains($html, 'value="order_ship"'));
check('no credit ledger for goods', !str_contains($html, 'Credit movements'));
check('no oversold pill yet', !str_contains($html, 'Oversold'));

harness_section('cancel');
$page = drive('order_cancel', ['id' => $id]);
pin('cancels the pending order', ['cancelled', true], [$status($id), str_contains(implode(' ', $page['flashes']), 'is cancelled')]);
$page = drive('order_cancel', ['id' => $id]);
pin('not twice', ['cancelled', true], [$status($id), str_contains(implode(' ', $page['flashes']), 'warning:')]);
pin('a missing order is not found', true, str_contains(implode(' ', drive('order_cancel', ['id' => 999999])['flashes']), 'no longer exists'));
$cr = OrderStore::create($tom, 'offline', $m(1), 'USD', 5);
$page = drive('order', [], ['id' => $cr->getId()]);
check('a pending credits order offers cancel too', str_contains($page['drawn'], 'order-cancel-dialog') && !str_contains($page['drawn'], 'Stock held'));
$page = drive('order_cancel', ['id' => $cr->getId()]);
pin('and it cancels', ['cancelled', true], [$status($cr->getId()), str_contains(implode(' ', $page['flashes']), 'is cancelled')]);

harness_section('ship');
$paid = $make('offline');
$pid  = $paid->getId();
$page = drive('order_ship', ['id' => $pid, 'carrier' => 'DHL']);
pin('a pending order cannot ship', ['pending', true], [$status($pid), str_contains(implode(' ', $page['flashes']), 'Only a paid order')]);
Billing::markPaid(OrderStore::find($pid), null);
$page = drive('order', [], ['id' => $pid]);
check('a paid goods order offers shipping with the three fields', str_contains($page['drawn'], 'value="order_ship"') && str_contains($page['drawn'], 'name="carrier"') && str_contains($page['drawn'], 'name="tracking"') && str_contains($page['drawn'], 'name="trackingUrl"'));
foreach (['http://track.test/1', 'javascript:alert(1)', 'ftp://x.test/1', 'https://a.test/"x"'] as $bad) {
    $page = drive('order_ship', ['id' => $pid, 'carrier' => 'DHL', 'trackingUrl' => $bad]);
    pin("the link $bad is refused", ['paid', true], [$status($pid), str_contains($page['drawn'], 'full https:// address')]);
}
$page = drive('order_ship', ['id' => $pid, 'carrier' => 'DHL', 'tracking' => 'JD01', 'trackingUrl' => 'http://bad.test']);
check('a refused link keeps the carrier and number, and names the link', str_contains($page['drawn'], 'value="DHL"') && str_contains($page['drawn'], 'value="JD01"') && str_contains($page['drawn'], 'value="http://bad.test"') && str_contains($page['drawn'], 'aria-invalid="true"') && $page['redirects'] === array());
$page = drive('order_ship', ['id' => $pid, 'carrier' => "DHL\n", 'tracking' => 'JD01', 'trackingUrl' => 'https://track.test/JD01']);
$ful  = OrderStore::find($pid)->fulfilment();
pin('a good one ships', ['shipped', 'DHL', 'JD01', 'https://track.test/JD01'], [$status($pid), $ful['carrier'], $ful['tracking'], $ful['tracking_url']]);
$page = drive('order', [], ['id' => $pid]);
check('the page shows the tracking link and offers delivery', str_contains($page['drawn'], 'href="https://track.test/JD01"') && str_contains($page['drawn'], 'value="order_delivered"') && !str_contains($page['drawn'], 'value="order_ship"'));
pin('shipping twice does nothing', 'shipped', (static function () use ($pid, $status) {
    drive('order_ship', ['id' => $pid, 'carrier' => 'Other']);

    return $status($pid);
})());
pin('and keeps the first carrier', 'DHL', OrderStore::find($pid)->fulfilment()['carrier']);

harness_section('deliver');
$page = drive('order_delivered', ['id' => $pid]);
pin('delivers a shipped order', 'delivered', $status($pid));
$page = drive('order_delivered', ['id' => $pid]);
pin('not twice', true, str_contains(implode(' ', $page['flashes']), 'Only a shipped order'));
$fresh = $make();
$page  = drive('order_delivered', ['id' => $fresh->getId()]);
pin('and not from pending', 'pending', $status($fresh->getId()));

harness_section('cash on delivery');
$cod  = $make('cod');
$cid  = $cod->getId();
$page = drive('order', [], ['id' => $cid]);
check('a pending cash on delivery order offers shipping', str_contains($page['drawn'], 'value="order_ship"'));
$list = drive('orders', [], ['toShip' => 1]);
check('and is in To ship', str_contains($list['drawn'], 'id=' . $cid . '"') || str_contains($list['drawn'], 'id=' . $cid . '&'));
drive('order_ship', ['id' => $cid, 'carrier' => 'DHL']);
$page = drive('order', [], ['id' => $cid]);
pin('it ships unpaid, and can still be cancelled as a refused parcel', ['shipped', true, true], [$status($cid), str_contains($page['drawn'], 'collected the cash'), str_contains($page['drawn'], 'order-cancel-dialog')]);
drive('order_delivered', ['id' => $cid]);
pin('delivered, it is paid', ['delivered', true], [$status($cid), OrderStore::find($cid)->isPaid()]);

harness_section('the token');
foreach (['orderShipPost', 'orderTrackingPost', 'orderDeliveredPost', 'orderCancelPost'] as $method) {
    $source = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminBilling.php', $method);
    $csrf   = strpos($source, 'osc_csrf_check()');
    $writes = array_filter([strpos($source, 'OrderStore::'), strpos($source, 'Billing::')], static fn ($x) => $x !== false);
    $write  = $writes === [] ? false : min($writes);
    check("scan: $method checks the token before it touches an order", $csrf !== false && $write !== false && $csrf < $write);
}

harness_section('oversold');
$short = $make('fake');
$lines = OrderLineStore::ofOrder($short->getId());
$meta  = $short->getMeta();
$meta['_short'] = [(string) $lines[0]['id'] => 1];
$admin->query("UPDATE {$p}t_billing_order SET s_meta = '" . $admin->real_escape_string(json_encode($meta)) . "' WHERE pk_i_id = " . $short->getId());
$page = drive('order', [], ['id' => $short->getId()]);
check('the pill and the short line show', str_contains($page['drawn'], 'Oversold') && str_contains($page['drawn'], 'Tee (1 short)'));

harness_section('the list');
$toShip = $make('fake', 'Ship me');
Billing::markPaid(OrderStore::find($toShip->getId()), 'ext-s');
$credits = OrderStore::create($tom, 'fake', $m(5), 'USD', 50);
Billing::markPaid($credits, 'ext-c');
$page = drive('index', [], []);
check('the full list shows goods labels', str_contains($page['drawn'], 'Ship me × 2') && str_contains($page['drawn'], 'To ship'));
$page = drive('index', [], ['toShip' => '1']);
check('To ship lists paid goods orders only', str_contains($page['drawn'], 'Ship me × 2') && !str_contains($page['drawn'], 'billing-order-link" href="https://example.test/oc-admin/index.php?page=billing&amp;action=order&amp;id=' . $credits->getId() . '"') && !str_contains($page['drawn'], 'Tee × 2'));
Preference::getInstance()->set('site_mode', 'classifieds');
scratchdb_forget_cache();
osc_reset_preferences();
$page = drive('index', [], ['toShip' => '1']);
check('a classifieds site has no To ship button and ignores the filter', !str_contains($page['drawn'], 'To ship') && str_contains($page['drawn'], 'Ship me'));
Preference::getInstance()->set('site_mode', 'shop');
scratchdb_forget_cache();
osc_reset_preferences();
$page = drive('index', [], ['status' => 'shipped']);
check('shipped and delivered are filters', str_contains($page['drawn'], 'value="shipped"') && str_contains($page['drawn'], 'value="delivered"'));

harness_section('the summary');
$sum = OrderStore::summary(['status' => 'shipped', 'goods' => false]);
pin('a shipped filter reports no paid or pending', [0, 0], [$sum['paid'], $sum['pending']]);
$sum = OrderStore::summary(['status' => '']);
check('no filter still counts both', $sum['paid'] >= 1 && $sum['count'] >= $sum['paid']);
$page = drive('index', [], ['status' => 'shipped']);
check('the shipped list shows one count, not paid and awaiting', str_contains($page['drawn'], 'billing-summary') && !str_contains($page['drawn'], 'awaiting payment'));
$page = drive('index', [], []);
check('the full list shows all three', str_contains($page['drawn'], 'awaiting payment'));

harness_section('a listing order');
$cat  = seed_category($admin);
$item = seed_item($admin, $cat, $tom, 'Red car');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL WHERE pk_i_id = {$item}");
$lo = OrderStore::create($tom, 'fake', $m(3), 'USD', 3, ['_kind' => Order::KIND_LISTING, '_item' => $item, '_label' => 'Listing: Red car']);
$page = drive('order', [], ['id' => $lo->getId()]);
check('shows the label and the editor link', str_contains($page['drawn'], 'Listing: Red car') && str_contains($page['drawn'], 'action=item_edit&amp;id=' . $item));
check('no warning before a failure', !str_contains($page['drawn'], 'could not be published'));
OrderStore::notePublishFailed($lo->getId(), 'bad <b>reason</b>');
$page = drive('order', [], ['id' => $lo->getId()]);
check('an unknown reason reads as an error, never the raw note', str_contains($page['drawn'], 'could not be published: an error stopped it') && !str_contains($page['drawn'], 'reason'));
OrderStore::notePublishFailed($lo->getId(), 'insufficient_credits');
$page = drive('order', [], ['id' => $lo->getId()]);
check('a known reason reads in words, not as its code', str_contains($page['drawn'], 'the price went up before the payment arrived') && !str_contains($page['drawn'], 'insufficient_credits'));
$page = drive('index', [], []);
check('the list shows the label and the unpublished word', str_contains($page['drawn'], 'Listing: Red car') && str_contains($page['drawn'], 'listing not published'));
$admin->query("UPDATE {$p}t_item SET b_enabled = 1, b_active = 1, dt_first_pub_date = NOW() WHERE pk_i_id = {$item}");
$page = drive('order', [], ['id' => $lo->getId()]);
check('live later reads as published later', str_contains($page['drawn'], 'It was published later'));
$page = drive('index', [], []);
check('the list drops the unpublished word once live', !str_contains($page['drawn'], 'listing not published'));
$page = drive('order', [], ['id' => $credits->getId()]);
check('a credits order shows none of it', !str_contains($page['drawn'], 'Listing: ') && !str_contains($page['drawn'], 'could not be published'));

harness_section('gateway refund pending');
final class PendingRefundGateway implements \mindstellar\billing\RefundableGateway
{
    public ?\mindstellar\billing\CallbackResult $answer = null;

    public function getId(): string
    {
        return 'pendingpay';
    }

    public function getName(): string
    {
        return 'Pending pay';
    }

    public function getSupportedCurrencies(): array
    {
        return array('USD');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): \mindstellar\billing\CheckoutIntent
    {
        return \mindstellar\billing\CheckoutIntent::redirect('https://example.test/pay');
    }

    public function handleCallback(array $request): \mindstellar\billing\CallbackResult
    {
        return \mindstellar\billing\CallbackResult::ignored();
    }

    public function refund(Order $order): \mindstellar\billing\CallbackResult
    {
        return $this->answer;
    }
}
$pg = new PendingRefundGateway();
\mindstellar\billing\PaymentGatewayRegistry::getInstance()->register($pg);
$_SERVER['REQUEST_METHOD'] = 'POST';
$refundAnswer = static function (callable $answer) use ($pg, $make, $status): array {
    $o = $make('pendingpay');
    Billing::markPaid(OrderStore::find($o->getId()), 'ext_' . $o->getId());
    $pg->answer = $answer($o->getId());
    $page = drive('order_refund_gateway', ['id' => $o->getId()]);

    return [implode(' | ', $page['flashes']), $status($o->getId())];
};
[$flash, $st] = $refundAnswer(static fn (int $id) => \mindstellar\billing\CallbackResult::refundPending($id, 'Razorpay is processing it.'));
pin('a pending refund shows an info flash with the provider note', 'info:The refund was sent. Razorpay is processing it.', $flash);
pin('and leaves the order paid', 'paid', $st);
[$flash] = $refundAnswer(static fn (int $id) => \mindstellar\billing\CallbackResult::refundPending($id));
check('without a note it uses the core words', str_starts_with($flash, 'info:The refund was sent. The order changes to refunded'));
[$flash] = $refundAnswer(static fn (int $id) => \mindstellar\billing\CallbackResult::ignored('card expired'));
pin('a refusal is still an error', 'error:The refund was not made: card expired', $flash);

harness_section('refund flashes');
$goodsPaid = $make('offline');
Billing::markPaid(OrderStore::find($goodsPaid->getId()), null);
$page = drive('order_refund', ['id' => $goodsPaid->getId()]);
$flash = implode(' ', $page['flashes']);
check('a goods refund does not mention credits', str_contains($flash, 'is recorded as refunded') && !str_contains($flash, 'credits'));
$creditsPaid = OrderStore::create($tom, 'fake', $m(5), 'USD', 50);
Billing::markPaid($creditsPaid, 'ext-r');
$page = drive('order_refund', ['id' => $creditsPaid->getId()]);
check('a credits refund still does', str_contains(implode(' ', $page['flashes']), 'credits have been taken back'));

harness_section('tracking after shipping');
$tr = $make('fake');
Billing::markPaid(OrderStore::find($tr->getId()), 'ext-t');
$page = drive('order', [], ['id' => $tr->getId()]);
check('a paid order asks before shipping with empty boxes', str_contains($page['drawn'], 'data-osc-confirm-empty="#order-ship-dialog"') && str_contains($page['drawn'], 'id="order-ship-dialog"') && str_contains($page['drawn'], 'Ship order #' . $tr->getId() . ' without a tracking number?'));
drive('order_ship', ['id' => $tr->getId(), 'carrier' => 'DHL', 'tracking' => 'OLD1']);
$page = drive('order', [], ['id' => $tr->getId()]);
check('a shipped order has an Edit tracking form with the stored values', str_contains($page['drawn'], 'value="order_tracking"') && str_contains($page['drawn'], 'value="OLD1"') && !str_contains($page['drawn'], 'order-ship-dialog'));
$page = drive('order_tracking', ['id' => $tr->getId(), 'carrier' => 'UPS', 'tracking' => 'NEW2', 'trackingUrl' => 'https://ups.test/NEW2']);
$ful = OrderStore::find($tr->getId())->fulfilment();
pin('the tracking is corrected and the status stays', ['shipped', 'UPS', 'NEW2', 'https://ups.test/NEW2'], [$status($tr->getId()), $ful['carrier'], $ful['tracking'], $ful['tracking_url']]);
check('the shipped date is kept', $ful['shipped_at'] !== null);
$page = drive('order_tracking', ['id' => $tr->getId(), 'carrier' => 'UPS', 'trackingUrl' => 'http://x.test']);
pin('a bad link is refused', 'NEW2', OrderStore::find($tr->getId())->fulfilment()['tracking']);
$unshipped = $make('fake');
pin('an unshipped order cannot take tracking', false, OrderStore::setTracking($unshipped->getId(), 'DHL', 'X', ''));

exit(harness_result());
