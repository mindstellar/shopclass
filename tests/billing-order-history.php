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
 * Order status history and the processing status: each move (paid, processing, shipped,
 * delivered, refunded, failed, cancelled) appends one `_history` entry with the right
 * from, to and actor; a replayed settle adds nothing; the list keeps the newest 200;
 * processing counts as settled, can ship, shows in the "To ship" list and fires its hook.
 * Usage: php tests/billing-order-history.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\gateway\CashOnDeliveryGateway;
use mindstellar\billing\Order;
use mindstellar\billing\OrderHistory;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_billing_order_history');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
foreach (array('enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD', 'billing_receipt_email' => '0') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$sam   = seed_user($admin, 'sam', 'sam@example.test');
$goods = static fn (string $gateway = 'offline'): Order => OrderStore::create($sam, $gateway, 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
$moves = static fn (int $id): array => array_map(
    static fn (array $e): array => array($e['from'], $e['to'], $e['by'], $e['id']),
    OrderStore::find($id)->history()
);

$heard = array();
osc_add_hook('billing_order_processing', static function (int $orderId, int $userId) use (&$heard): void {
    $heard[] = array($orderId, $userId);
});

harness_section('a goods order from paid to delivered');
$order = $goods();
pin('a new order has no history', array(), $order->history());
check('settling it as paid works', OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ref-h1'));
pin('a webhook settle is written by the system', array(array('pending', 'paid', 'system', null)), $moves($order->getId()));
$entry = OrderStore::find($order->getId())->history()[0];
check('the entry carries the time', is_string($entry['at']) && strtotime($entry['at']) !== false);
check('a replayed settle is refused', !OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ref-h1'));
pin('and adds nothing', 1, count(OrderStore::find($order->getId())->history()));
$twin = $goods();
check('a ref another order holds is refused, not thrown', !OrderStore::settle($twin->getId(), Order::STATUS_PAID, 'ref-h1'));
pin('and the twin stays pending with no history', array('pending', array()), array(OrderStore::find($twin->getId())->getStatus(), OrderStore::find($twin->getId())->history()));

OrderHistory::actAs(OrderHistory::BY_ADMIN, 4);
check('admin 4 starts processing it', OrderStore::process($order->getId()));
$now = OrderStore::find($order->getId());
pin('it is processing, paid, settled and can ship', array('processing', true, true), array($now->getStatus(), $now->isPaid(), $now->canShip()));
pin('the move is admin 4\'s', array('paid', 'processing', 'admin', 4), $moves($order->getId())[1]);
pin('OrderProcessing reached the old hook once', array(array($order->getId(), $sam)), $heard);
check('processing again is refused', !OrderStore::process($order->getId()));
pin('and adds nothing', 2, count($now->history()));

harness_section('the "To ship" list holds processing orders');
$listed = array_map(static fn (Order $o): int => $o->getId(), OrderStore::search(OrderStore::TO_SHIP));
check('the processing order is listed', in_array($order->getId(), $listed, true));
pin('and counted', count($listed), OrderStore::searchCount(OrderStore::TO_SHIP));
check('and among the oldest to ship', in_array($order->getId(), array_map(static fn (array $r): int => $r['order']->getId(), OrderStore::oldestToShip(10)), true));
pin('the processing filter alone finds it', 1, OrderStore::searchCount(array('status' => Order::STATUS_PROCESSING) + OrderStore::TO_SHIP));

check('it ships', OrderStore::ship($order->getId(), 'DHL', 'T1', 'https://track.example.test/T1'));
check('it is delivered', Billing::deliver(OrderStore::find($order->getId())));
OrderHistory::actAs(null);
check('it is refunded', Billing::refund(OrderStore::find($order->getId())));
pin('each move wrote one entry in order', array(
    array('pending', 'paid', 'system', null),
    array('paid', 'processing', 'admin', 4),
    array('processing', 'shipped', 'admin', 4),
    array('shipped', 'delivered', 'admin', 4),
    array('delivered', 'refunded', 'system', null),
), $moves($order->getId()));

harness_section('the buyer and the system');
$failed = $goods('card');
Session::getInstance()->_setEphemeral('userId', $sam);
check('a failed payment seen by the signed-in buyer', OrderStore::settle($failed->getId(), Order::STATUS_FAILED));
pin('is the buyer\'s', array(array('pending', 'failed', 'buyer', $sam)), $moves($failed->getId()));
$other = seed_user($admin, 'kim', 'kim@example.test');
Session::getInstance()->_setEphemeral('userId', $other);
$cancel = $goods();
check('a cancel while someone else is signed in', OrderStore::cancel($cancel->getId()));
pin('is the system\'s', array(array('pending', 'cancelled', 'system', null)), $moves($cancel->getId()));
Session::getInstance()->_setEphemeral('userId', 0);

harness_section('cash on delivery');
OrderHistory::actAs(OrderHistory::BY_ADMIN, 7);
$cod = $goods(CashOnDeliveryGateway::ID);
check('a pending COD order can process', $cod->canProcess());
check('it moves to processing', OrderStore::process($cod->getId()));
$now = OrderStore::find($cod->getId());
pin('it waits for the cash, is not paid, can ship', array(true, false, true), array($now->awaitsCash(), $now->isPaid(), $now->canShip()));
check('it is in the "To ship" list', in_array($cod->getId(), array_map(static fn (Order $o): int => $o->getId(), OrderStore::search(OrderStore::TO_SHIP)), true));
check('a seller cancel from processing works', OrderStore::cancel($cod->getId()));
pin('both moves are kept', array(array('pending', 'processing', 'admin', 7), array('processing', 'cancelled', 'admin', 7)), $moves($cod->getId()));

$card = $goods('card');
check('a pending card order cannot process', !OrderStore::process($card->getId()));
$credits = OrderStore::create($sam, 'offline', 1_000_000, 'USD', 10, array());
OrderStore::settle($credits->getId(), Order::STATUS_PAID, 'ref-h2');
check('nor a paid credits order', !OrderStore::process($credits->getId()));

harness_section('the newest 200 are kept');
$long = $goods();
$old  = array();
for ($i = 0; $i < OrderHistory::CAP; $i++) {
    $old[] = array('at' => '2020-01-01 00:00:00', 'from' => 'pending', 'to' => 'pending', 'by' => 'system', 'id' => $i);
}
$admin->query("UPDATE {$p}t_billing_order SET s_meta = '" . $admin->real_escape_string(json_encode(array('_kind' => 'goods', '_history' => $old))) . "' WHERE pk_i_id = " . $long->getId());
OrderStore::settle($long->getId(), Order::STATUS_PAID, 'ref-h3');
$history = OrderStore::find($long->getId())->history();
pin('the list stays at 200', 200, count($history));
pin('the oldest is dropped, the new one is last', array(1, array('pending', 'paid', 'admin', 7)), array($history[0]['id'], array($history[199]['from'], $history[199]['to'], $history[199]['by'], $history[199]['id'])));
OrderHistory::actAs(null);

exit(harness_result());
