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
 * Two admins ship parts of an order at once. Both parcels are kept when there is room for both,
 * one is refused when there is not, and the shipped quantity never goes over the ordered one.
 * Usage: php tests/billing-shipments-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Shipments;

$admin = api_admin_boot('osc_billing_shipments_race');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$sam    = seed_user($admin, 'sam', 'sam@example.test');
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$child  = __DIR__ . '/lib/shipments-child.php';

// Ordered, shipped before, each admin's parcel, then parcels stored and refused, and the status.
$cases = array(
    'from processing, one unit left'       => array(2, 1, 1, 1, 1, Order::STATUS_SHIPPED),
    'from paid, the only unit'             => array(1, 0, 1, 1, 1, Order::STATUS_SHIPPED),
    'two parcels that leave it processing' => array(3, 0, 1, 2, 0, Order::STATUS_PROCESSING),
    'room for one parcel of two'           => array(3, 0, 2, 1, 1, Order::STATUS_PROCESSING),
);
foreach ($cases as $title => [$ordered, $before, $each, $stored, $refused, $status]) {
    harness_section($title);
    $order = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
    OrderLineStore::add($order->getId(), array(array('kind' => 'item', 'item_id' => 1, 'title' => 'Tee', 'qty' => $ordered)));
    $line = OrderLineStore::ofOrder($order->getId())[0]['id'];
    OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ref-' . $order->getId());
    if ($before > 0) {
        Shipments::add($order->getId(), array($line => $before), 'DHL', 'first', '');
    }

    [$waiting, $out] = race($admin, "SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = {$order->getId()} FOR UPDATE", $dbName, array(
        array($child, array($order->getId(), $line, 1, $each)),
        array($child, array($order->getId(), $line, 2, $each)),
    ), "%{$p}t_billing_order%");
    $printed = implode('', $out);
    pin('both waited on the order row', 2, $waiting);
    pin('shipped, refused, threw', array($stored, $refused, 0), array(substr_count($printed, 'shipped'), substr_count($printed, 'refused'), substr_count($printed, 'threw')));
    $now = OrderStore::find($order->getId());
    pin('the shipped quantity', array($line => $before + $stored * $each), $now->shippedQty());
    pin('the parcels', ($before > 0 ? 1 : 0) + $stored, count($now->shipments()));
    pin('the status', $status, $now->getStatus());
}

exit(harness_result());
