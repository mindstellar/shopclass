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
 * Two admins ship the same last unit of an order at once: one parcel is stored, the other is
 * refused, and the shipped quantity never goes over the ordered one.
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

$cases = array(
    'from processing, one unit left' => 1,
    'from paid, the only unit'       => 0,
);
foreach ($cases as $title => $before) {
    harness_section($title);
    $order = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
    OrderLineStore::add($order->getId(), array(array('kind' => 'item', 'item_id' => 1, 'title' => 'Tee', 'qty' => $before + 1)));
    $line = OrderLineStore::ofOrder($order->getId())[0]['id'];
    OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ref-' . $order->getId());
    if ($before > 0) {
        Shipments::add($order->getId(), array($line => $before), 'DHL', 'first', '');
    }

    [$waiting, $out] = race($admin, "SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = {$order->getId()} FOR UPDATE", $dbName, array(
        array($child, array($order->getId(), $line, 1)),
        array($child, array($order->getId(), $line, 2)),
    ), "%{$p}t_billing_order%");
    $printed = implode('', $out);
    pin('both waited on the order row', 2, $waiting);
    pin('one shipped, one was refused, none threw', array(1, 1, 0), array(substr_count($printed, 'shipped'), substr_count($printed, 'refused'), substr_count($printed, 'threw')));
    $now = OrderStore::find($order->getId());
    pin('the quantity never goes over', array($line => $before + 1), $now->shippedQty());
    pin('one parcel more', $before + 1, count($now->shipments()));
    pin('the order is shipped', Order::STATUS_SHIPPED, $now->getStatus());
}

exit(harness_result());
