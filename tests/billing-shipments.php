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
 * Partial shipments: a parcel takes open item lines only, never more than is open; the first
 * part moves the order to processing and the last to shipped; `_shipping` mirrors the newest
 * parcel; tracking per parcel; the packed label; the hooks each step fires.
 * Usage: php tests/billing-shipments.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\gateway\CashOnDeliveryGateway;
use mindstellar\billing\Order;
use mindstellar\billing\OrderHistory;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Shipments;

$admin = api_admin_boot('osc_billing_shipments');

seed_locale($admin);
seed_currency($admin);
foreach (array('enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD', 'billing_receipt_email' => '0') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$sam = seed_user($admin, 'sam', 'sam@example.test');

/** A goods order with two item lines (Tee x3, Mug x1) and a shipping line, paid unless COD. */
$order = static function (string $gateway = 'offline') use ($sam): array {
    $o = OrderStore::create($sam, $gateway, 50_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
    OrderLineStore::add($o->getId(), array(
        array('kind' => 'item', 'item_id' => 1, 'title' => 'Tee', 'qty' => 3, 'unit' => 10_000_000, 'amount' => 30_000_000),
        array('kind' => 'item', 'item_id' => 2, 'title' => 'Mug', 'qty' => 1, 'unit' => 15_000_000, 'amount' => 15_000_000),
        array('kind' => 'shipping', 'title' => 'Post', 'unit' => 5_000_000, 'amount' => 5_000_000),
    ));
    if ($gateway !== CashOnDeliveryGateway::ID) {
        OrderStore::settle($o->getId(), Order::STATUS_PAID, 'ref-' . $o->getId());
    }
    $ids = array_map(static fn (array $l): int => $l['id'], OrderLineStore::ofOrder($o->getId()));

    return array($o->getId(), $ids[0], $ids[1], $ids[2]);
};
$status = static fn (int $id): string => OrderStore::find($id)->getStatus();

$heard = array();
foreach (array('billing_order_processing', 'billing_order_shipped') as $hook) {
    osc_add_hook($hook, static function (int $orderId) use (&$heard, $hook): void {
        $heard[] = array($hook, $orderId);
    });
}
osc_add_hook('billing_shipment_add_after', static function (int $orderId, int $userId, int $n) use (&$heard): void {
    $heard[] = array('billing_shipment_add_after', $orderId, $n);
});

OrderHistory::actAs(OrderHistory::BY_ADMIN, 3);

harness_section('refused parcels');
[$id, $tee, $mug, $post] = $order();
pin('an empty parcel', null, Shipments::add($id, array(), 'DHL', 'E1', ''));
pin('zero of every line', null, Shipments::add($id, array($tee => 0), 'DHL', 'E2', ''));
pin('more than was ordered', null, Shipments::add($id, array($tee => 4), 'DHL', 'E3', ''));
pin('a shipping line', null, Shipments::add($id, array($post => 1), 'DHL', 'E4', ''));
pin('a line of another order', null, Shipments::add($id, array($tee + 1000 => 1), 'DHL', 'E5', ''));
pin('a negative quantity', null, Shipments::add($id, array($tee => -1, $mug => 1), 'DHL', 'E6', ''));
pin('a quantity that is not a number', null, Shipments::add($id, array($tee => '1x'), 'DHL', 'E7', ''));
pin('nothing was written', array('paid', array(), array()), array($status($id), OrderStore::find($id)->shipments(), $heard));

$card = OrderStore::create($sam, 'card', 1_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
OrderLineStore::add($card->getId(), array(array('kind' => 'item', 'title' => 'Cap', 'qty' => 1)));
$cardLine = OrderLineStore::ofOrder($card->getId())[0]['id'];
pin('a pending card order', null, Shipments::add($card->getId(), array($cardLine => 1), 'DHL', 'E8', ''));

harness_section('two parcels');
$heard = array();
pin('the first parcel is number 1', 1, Shipments::add($id, array($tee => 2), ' DHL ', ' P1 ', 'https://track.example.test/P1'));
$now = OrderStore::find($id);
pin('the order moves to processing', 'processing', $now->getStatus());
pin('the move is in the history', array('paid', 'processing', 'admin', 3), array_values(array_intersect_key(end($now->history()) ?: array(), array_flip(array('from', 'to', 'by', 'id')))));
$first = $now->shipments()[0];
pin('the parcel is stored', array(1, 'admin', 3, array(array('line' => $tee, 'qty' => 2)), 'DHL', 'P1', 'https://track.example.test/P1'), array($first['n'], $first['by'], $first['id'], $first['lines'], $first['carrier'], $first['tracking'], $first['url']));
pin('shipped so far', array($tee => 2), $now->shippedQty());
pin('the fulfilment shows the parcel', array('DHL', 'P1', $first['at']), array($now->fulfilment()['carrier'], $now->fulfilment()['tracking'], $now->fulfilment()['shipped_at']));
pin('processing then the parcel was heard, not shipped', array(array('billing_order_processing', $id), array('billing_shipment_add_after', $id, 1)), $heard);

pin('two more tees is over what is open', null, Shipments::add($id, array($tee => 2, $mug => 1), 'DHL', 'P2', ''));
$heard = array();
pin('the rest is parcel 2', 2, Shipments::add($id, array($tee => 1, $mug => '1'), 'UPS', 'P2', 'https://ups.example.test/P2'));
$now = OrderStore::find($id);
pin('the order is shipped', 'shipped', $now->getStatus());
pin('the newest parcel is the order\'s tracking', array('UPS', 'P2'), array($now->fulfilment()['carrier'], $now->fulfilment()['tracking']));
pin('the parcel and the shipped order were heard', array(array('billing_shipment_add_after', $id, 2), array('billing_order_shipped', $id)), $heard);
pin('one history entry per move', array(array('pending', 'paid'), array('paid', 'processing'), array('processing', 'shipped')), array_map(static fn (array $e): array => array($e['from'], $e['to']), $now->history()));
pin('no parcel after it shipped', null, Shipments::add($id, array($tee => 1), 'DHL', 'P3', ''));

harness_section('a line ships in parts');
[$part, $pTee, $pMug] = $order();
Shipments::add($part, array($pTee => 1, $pMug => 1), 'DHL', 'Q1', '');
pin('one of three tees and the mug leave it processing', 'processing', $status($part));
Shipments::add($part, array($pTee => 1), 'DHL', 'Q2', '');
pin('two of three tees still leave it processing', 'processing', $status($part));
Shipments::add($part, array($pTee => 1), 'DHL', 'Q3', '');
pin('the last tee ships it', 'shipped', $status($part));

[$half, $hTee] = $order();
Shipments::add($half, array($hTee => 3), 'DHL', 'H1', '');
pin('every tee but no mug leaves it processing', 'processing', $status($half));

harness_section('tracking per parcel');
check('parcel 1 can be corrected', Shipments::setTracking($id, 1, 'DHL', 'P1b', ''));
$now = OrderStore::find($id);
pin('only parcel 1 changes', array('P1b', 'P2', 'P2'), array($now->shipments()[0]['tracking'], $now->shipments()[1]['tracking'], $now->fulfilment()['tracking']));
check('the newest parcel mirrors to the order', Shipments::setTracking($id, 2, 'UPS', 'P2b', 'https://ups.example.test/P2b'));
pin('both read it', array('P2b', 'P2b'), array(OrderStore::find($id)->shipments()[1]['tracking'], OrderStore::find($id)->fulfilment()['tracking']));
pin('a parcel that does not exist', false, Shipments::setTracking($id, 3, 'UPS', 'X', ''));
check('the old setTracking changes the newest parcel', OrderStore::setTracking($id, 'FedEx', 'F1', ''));
pin('parcel 2 and the order read it', array('FedEx', 'F1', 'FedEx'), array(OrderStore::find($id)->shipments()[1]['carrier'], OrderStore::find($id)->shipments()[1]['tracking'], OrderStore::find($id)->fulfilment()['carrier']));
pin('the status stays', 'shipped', $status($id));

harness_section('the old ship()');
[$whole, $wTee, $wMug] = $order();
$heard = array();
check('it ships everything in one parcel', OrderStore::ship($whole, 'DHL', 'W1', ''));
$now = OrderStore::find($whole);
pin('as parcel 1 with every item line', array('shipped', array(array('line' => $wTee, 'qty' => 3), array('line' => $wMug, 'qty' => 1))), array($now->getStatus(), $now->shipments()[0]['lines']));
pin('it goes from paid straight to shipped', array(array('pending', 'paid'), array('paid', 'shipped')), array_map(static fn (array $e): array => array($e['from'], $e['to']), $now->history()));
pin('the parcel and the shipped order were heard', array(array('billing_shipment_add_after', $whole, 1), array('billing_order_shipped', $whole)), $heard);

[$rest, $rTee] = $order();
Shipments::add($rest, array($rTee => 1), 'DHL', 'R1', '');
check('after a part it ships the rest', OrderStore::ship($rest, 'DHL', 'R2', ''));
pin('parcel 2 holds what was open', array(array('line' => $rTee, 'qty' => 2), array('line' => $rTee + 1, 'qty' => 1)), OrderStore::find($rest)->shipments()[1]['lines']);

harness_section('cash on delivery');
[$cod, $cTee] = $order(CashOnDeliveryGateway::ID);
pin('a pending COD order ships a part', 1, Shipments::add($cod, array($cTee => 1), 'DHL', 'C1', ''));
$now = OrderStore::find($cod);
pin('it is processing and waits for the cash', array('processing', true), array($now->getStatus(), $now->awaitsCash()));
pin('it cannot be cancelled with a part out', array(false, 'processing'), array(OrderStore::cancel($cod), $status($cod)));
[$codNone] = $order(CashOnDeliveryGateway::ID);
check('a COD order with nothing out can be cancelled', OrderStore::cancel($codNone));

harness_section('packed');
[$pack] = $order();
check('a paid order is packed', Shipments::markPacked($pack, true));
$packed = OrderStore::find($pack)->packed();
pin('by the admin', array('admin', 3), array($packed['by'], $packed['id']));
check('packing again keeps the first mark', Shipments::markPacked($pack, true) && OrderStore::find($pack)->packed() === $packed);
pin('the status stays and no history is written', array('paid', 1), array($status($pack), count(OrderStore::find($pack)->history())));
check('the mark is cleared', Shipments::markPacked($pack, false) && OrderStore::find($pack)->packed() === null);
pin('a shipped order cannot be packed', false, Shipments::markPacked($id, true));

OrderHistory::actAs(null);

exit(harness_result());
