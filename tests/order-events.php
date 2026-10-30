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
 * Pins the order events: each status event reaches OrderEvent listeners and its own hook name with
 * the old arguments, typed and old callbacks share one priority order, and the tracking, receipt
 * and return-URL events keep their rules whether a listener is typed or an old filter.
 *
 * DB-free.  Usage: php tests/order-events.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\billing\event\CarriersListing;
use mindstellar\billing\event\OrderCancelled;
use mindstellar\billing\event\OrderDelivered;
use mindstellar\billing\event\OrderEvent;
use mindstellar\billing\event\OrderFailed;
use mindstellar\billing\event\OrderPaid;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\billing\event\OrderReturnUrlResolving;
use mindstellar\billing\event\OrderShipped;
use mindstellar\billing\event\ReceiptComposing;
use mindstellar\billing\event\TrackingUrlResolving;
use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Order;
use mindstellar\container\Container;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\HookBridge;

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}

// Core's listing-order listeners read the database, which this test has none of.
$fresh = static function (): DispatcherInterface {
    harness_reset_container();
    $events = Container::root()->get(DispatcherInterface::class);
    $events->remove(OrderPaid::class, [ListingCheckout::class, 'onOrderPaid']);
    $events->remove(OrderReturnUrlResolving::class, [ListingCheckout::class, 'onReturnUrl']);

    return $events;
};

harness_section('Core listeners');

harness_reset_container();
$core = Container::root()->get(DispatcherInterface::class);
pin('core listens for paid listing orders and their return URL', [true, true], [
    $core->remove(OrderPaid::class, [ListingCheckout::class, 'onOrderPaid']),
    $core->remove(OrderReturnUrlResolving::class, [ListingCheckout::class, 'onReturnUrl']),
]);

harness_section('Status events');

$events = $fresh();
$seen   = [];
Plugins::addHook('billing_order_placed', static function ($orderId, $userId) use (&$seen): void {
    $seen[] = ['old placed', $orderId, $userId];
});
Plugins::addHook('billing_order_paid', static function ($orderId, $userId, $credits) use (&$seen): void {
    $seen[] = ['old paid', $orderId, $userId, $credits];
});
$events->listen(OrderEvent::class, static function (OrderEvent $e) use (&$seen): void {
    $seen[] = [get_class($e), $e->orderId(), $e->userId()];
});
foreach ([new OrderPlaced(7, 3), new OrderPaid(7, 3, 50), new OrderFailed(8, 3), new OrderShipped(9, 4), new OrderDelivered(9, 4), new OrderCancelled(10, 4)] as $event) {
    $events->dispatch($event);
}
pin('an old callback gets the old arguments; an OrderEvent listener hears every status', [
    ['old placed', 7, 3], [OrderPlaced::class, 7, 3],
    ['old paid', 7, 3, 50], [OrderPaid::class, 7, 3],
    [OrderFailed::class, 8, 3], [OrderShipped::class, 9, 4], [OrderDelivered::class, 9, 4], [OrderCancelled::class, 10, 4],
], $seen);
pin('each status event owns one name', [
    'billing_order_placed', 'billing_order_paid', 'billing_order_failed', 'billing_order_shipped', 'billing_order_delivered', 'billing_order_cancelled',
], array_map(static fn (string $c): string => $c::legacyNames()[0], [OrderPlaced::class, OrderPaid::class, OrderFailed::class, OrderShipped::class, OrderDelivered::class, OrderCancelled::class]));
pin('OrderPaid carries the credits', 50, (new OrderPaid(1, 2, 50))->credits());

$events = $fresh();
$seen   = [];
Plugins::addHook('billing_order_shipped', static function (OrderShipped $e) use (&$seen): void {
    $seen[] = 'typed:' . $e->orderId();
}, 10);
Plugins::addHook('billing_order_shipped', static function ($id) use (&$seen): void {
    $seen[] = 'old:' . $id;
}, 1);
$events->dispatch(new OrderShipped(5, 1));
pin('typed and old callbacks on one name run by priority', ['old:5', 'typed:5'], $seen);

harness_section('Tracking URL');

$events = $fresh();
Plugins::addHook('billing_tracking_url', static function ($url, $carrier, $tracking) {
    return 'http://insecure.example/' . $tracking;
}, 5);
$e = $events->dispatch(new TrackingUrlResolving('DHL', 'AB1'));
pin('an old filter returning http leaves it empty', '', $e->url());
Plugins::addHook('billing_tracking_url', static function (TrackingUrlResolving $e): void {
    $e->setUrl('https://track.example/' . $e->carrier() . '/' . $e->tracking());
}, 10);
$e = $events->dispatch(new TrackingUrlResolving('DHL', 'AB1'));
pin('a typed listener sets an https page', 'https://track.example/DHL/AB1', $e->url());

harness_section('Carriers');

$events = $fresh();
Plugins::addHook('admin_billing_carriers', static fn ($names) => array_merge($names, ['Blue Dart', ' ', 'Blue Dart', 7]), 5);
Plugins::addHook('admin_billing_carriers', static function (CarriersListing $e): void {
    $e->add('Delhivery');
    $e->add('<b>India Post</b>');
}, 10);
pin('old and typed callbacks add carrier names, each once, blanks and non-text left out', ['Blue Dart', 'Delhivery', '<b>India Post</b>'], $events->dispatch(new CarriersListing())->names());

harness_section('Return URL');

$events = $fresh();
Plugins::addHook('billing_order_return_url', static fn ($url, $orderId) => $url . '?old=' . $orderId, 5);
$e = $events->dispatch(new OrderReturnUrlResolving(12, 'https://shop.example/orders'));
pin('an old filter folds into the url', 'https://shop.example/orders?old=12', $e->url());
Plugins::addHook('billing_order_return_url', static function (OrderReturnUrlResolving $e): void {
    $e->setUrl('https://shop.example/placed/' . $e->orderId());
}, 10);
$e = $events->dispatch(new OrderReturnUrlResolving(12, 'https://shop.example/orders'));
pin('a typed listener replaces it', 'https://shop.example/placed/12', $e->url());

harness_section('Receipt');

$order   = new Order(1, 2, 'cod', null, 1000, 'USD', 0, 'pending', [], '', null);
$details = ['lines' => [], 'total' => '$10', 'title' => 'Receipt', 'extra' => [], 'summary' => [], 'footer' => ''];

$events = $fresh();
Plugins::addHook('billing_receipt_details', static function ($d, $order) {
    $d['title']     = "Tax\ninvoice";
    $d['extra'][]   = ['label' => 'GSTIN', 'value' => '22AAAAA0000A1Z5'];
    $d['extra'][]   = ['label' => '', 'value' => 'dropped'];
    $d['extra'][]   = 'not a row';
    $d['summary'][] = ['label' => 'Saved', 'value' => '$2'];
    $d['footer']    = str_repeat('x', 1200);

    return $d;
}, 5);
$e = $events->dispatch(new ReceiptComposing($order, $details));
pin('an old filter is folded with today\'s rules', ['Tax invoice', [['label' => 'GSTIN', 'value' => '22AAAAA0000A1Z5']], [['label' => 'Saved', 'value' => '$2']], 1000], [$e->title(), $e->extraRows(), $e->summaryRows(), strlen($e->footer())]);

Plugins::addHook('billing_receipt_details', static function (ReceiptComposing $e): void {
    $e->addExtraRow('PAN', 'ABCDE1234F');
    $e->setFooter('Thanks');
    $e->setTitle('');
}, 10);
$e = $events->dispatch(new ReceiptComposing($order, $details));
pin('a typed listener adds rows; an empty title falls back to the default', ['Receipt', 2, 'Thanks'], [$e->title(), count($e->extraRows()), $e->footer()]);

$events = $fresh();
Plugins::addHook('billing_receipt_details', static fn ($d, $order) => 'nothing', 5);
$e = $events->dispatch(new ReceiptComposing($order, $details));
pin('an old filter returning a non-array clears the four fields, as before', ['Receipt', [], [], ''], [$e->title(), $e->extraRows(), $e->summaryRows(), $e->footer()]);

$events = $fresh();
for ($i = 0; $i < 40; $i++) {
    Plugins::addHook('billing_receipt_details', static function (ReceiptComposing $e) use ($i): void {
        $e->addSummaryRow('row' . $i, 'v');
    }, 5);
}
pin('rows stop at 30', 30, count($events->dispatch(new ReceiptComposing($order, $details))->summaryRows()));

harness_section('Ownership');

$bridge = HookBridge::active();
$owners = $bridge === null ? [] : $bridge->owners();
foreach (['billing_order_placed', 'billing_order_paid', 'billing_order_failed', 'billing_order_shipped', 'billing_order_delivered', 'billing_order_cancelled', 'billing_tracking_url', 'admin_billing_carriers', 'billing_receipt_details', 'billing_order_return_url'] as $name) {
    check($name . ' is owned by an event', isset($owners[$name]));
}
check('billing_order_refunded stays an old hook', !isset($owners['billing_order_refunded']));

exit(harness_result());
