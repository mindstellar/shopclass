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
 * Refunds of one order at once. Two part refunds that do not both fit: one is refused and the
 * notes never pass the paid amount. A part refund racing a full refund webhook: the notes add up to
 * the paid amount exactly, with one remainder note, and the order ends refunded.
 * Usage: php tests/billing-partial-refund-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\billing\Billing;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;

$admin = api_admin_boot('osc_billing_partial_refund_race');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$sam = seed_user($admin, 'sam', 'sam@example.test');
foreach (['billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_receipt_email' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
Receipts::$mailer = static fn (array $params): bool => true;
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$child  = __DIR__ . '/lib/partial-refund-child.php';
$m      = static fn (float $units): int => (int) round($units * 1_000_000);

$paidOrder = static function () use ($sam, $m): Order {
    $order = OrderStore::create($sam, 'offline', $m(40), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee × 4']);
    OrderLineStore::add($order->getId(), [['kind' => 'item', 'item_id' => 1, 'title' => 'Tee', 'qty' => 4, 'unit' => $m(10), 'amount' => $m(40)]]);
    Billing::markPaid($order, 'ref-' . $order->getId());

    return OrderStore::find($order->getId());
};
$lockSql = static fn (Order $o): string => "SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = {$o->getId()} FOR UPDATE";
$credit  = static fn (Order $o): int => count(array_filter(DocumentStore::forOrder($o->getId()), static fn ($d): bool => $d->isCreditNote()));

harness_section('two part refunds that do not both fit');
$order = $paidOrder();
check('it has an invoice', DocumentStore::invoiceOf($order->getId()) !== null);
race_hold($admin, $lockSql($order));
$kids = [
    race_run($child, $dbName, [$order->getId(), 'part', $m(25)]),
    race_run($child, $dbName, [$order->getId(), 'part', $m(25)]),
];
$onRow  = race_wait_for($dbName, "%{$p}t_billing_order%FOR UPDATE%", 1);
$onLock = race_wait_for($dbName, '%GET_LOCK%', 1);
$out    = implode('', race_release($admin, $kids));
pin('one waited on the order row, one on the refund lock', [1, 1], [$onRow, $onLock]);
pin('refunded, refused, threw', [1, 1, 0], [substr_count($out, 'refunded'), substr_count($out, 'refused'), substr_count($out, 'threw')]);
pin('one note of 25.00', [1, $m(25)], [$credit($order), DocumentStore::refundedTotal($order->getId())]);
pin('the order stays paid', Order::STATUS_PAID, OrderStore::find($order->getId())->getStatus());

harness_section('a part refund racing the full refund webhook');
$order = $paidOrder();
race_hold($admin, $lockSql($order));
$kids = [
    race_run($child, $dbName, [$order->getId(), 'part', $m(15)]),
    race_run($child, $dbName, [$order->getId(), 'full']),
];
$waiting = race_wait_for($dbName, "%{$p}t_billing_order%FOR UPDATE%", 2);
$out     = implode('', race_release($admin, $kids));
pin('both waited on the order row', 2, $waiting);
pin('nothing threw', 0, substr_count($out, 'threw'));
pin('the notes add up to the paid amount exactly', $m(40), DocumentStore::refundedTotal($order->getId()));
pin('the order is refunded', Order::STATUS_REFUNDED, OrderStore::find($order->getId())->getStatus());
check('one or two notes, never a second remainder', in_array($credit($order), [1, 2], true));
check('a replayed webhook adds nothing', !Billing::refund(OrderStore::find($order->getId())));
pin('the total is still the paid amount', $m(40), DocumentStore::refundedTotal($order->getId()));

exit(harness_result());
