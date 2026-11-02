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
 * Order history under concurrent writers: two admins moving one order both keep their entry,
 * the second reading the first's; a webhook settle racing a plugin's meta write is never lost
 * and neither is the meta.
 * Usage: php tests/billing-order-history-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_billing_order_history_race');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$sam    = seed_user($admin, 'sam', 'sam@example.test');
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$child  = __DIR__ . '/lib/order-history-child.php';
$lock   = static fn (Order $o): string => "SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = {$o->getId()} FOR UPDATE";

harness_section('two admins move one order at once');
$order = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ref-r1');
[$waiting, $out] = race($admin, $lock($order), $dbName, array(
    array($child, array($order->getId(), 'move', Order::STATUS_PROCESSING, 1)),
    array($child, array($order->getId(), 'move', Order::STATUS_SHIPPED, 2)),
), "%{$p}t_billing_order%");
$printed = implode('', $out);
pin('both waited on the order row', 2, $waiting);
pin('both moved, neither threw', array(2, 0), array(substr_count($printed, 'moved'), substr_count($printed, 'threw')));
$history = OrderStore::find($order->getId())->history();
pin('both entries are kept after the settle', 3, count($history));
pin('the second starts where the first ended', $history[1]['to'], $history[2]['from']);
$admins = array_map(static fn (array $e): int => (int) $e['id'], array_slice($history, 1));
sort($admins);
pin('one entry per admin', array(1, 2), $admins);
pin('the order ends in the last move', $history[2]['to'], OrderStore::find($order->getId())->getStatus());

harness_section('a webhook settle and a meta write at once');
$order = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
[$waiting, $out] = race($admin, $lock($order), $dbName, array(
    array($child, array($order->getId(), 'pay')),
    array($child, array($order->getId(), 'meta')),
), "%{$p}t_billing_order%");
$printed = implode('', $out);
pin('both waited on the order row', 2, $waiting);
pin('the settle and the meta write both land', array(1, 1, 0), array(count(array_keys($out, "paid\n", true)), count(array_keys($out, "noted\n", true)), substr_count($printed, 'threw')));
$now = OrderStore::find($order->getId());
pin('the order is paid with its reference', array(Order::STATUS_PAID, 'race-' . $order->getId()), array($now->getStatus(), $now->getExternalRef()));
pin('the meta value is kept', 'kept', $now->meta('note'));
pin('the settle wrote its entry', array(array('pending', 'paid')), array_map(static fn (array $e): array => array($e['from'], $e['to']), $now->history()));

exit(harness_result());
