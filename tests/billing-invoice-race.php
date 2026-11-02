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
 * One invoice per order under two processes: a webhook and the retry job issuing the invoice of
 * the same paid order at once get the same number, and the order holds one invoice.
 * Usage: php tests/billing-invoice-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_billing_invoice_race');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$sam = seed_user($admin, 'sam', 'sam@example.test');
foreach (['currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_receipt_email' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$child  = __DIR__ . '/lib/invoice-issue-child.php';
$order  = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS, '_label' => 'Tee'));
OrderStore::settle($order->getId(), Order::STATUS_PAID, 'bank-1');
$id = $order->getId();

harness_section('two issuers of one invoice');
pin('the order is paid with no invoice yet', 0, count(DocumentStore::forOrder($id)));
[$waiting, $out] = race(
    $admin,
    "SELECT pk_i_id FROM {$p}t_billing_order WHERE pk_i_id = {$id} FOR UPDATE",
    $dbName,
    array(array($child, array($id)), array($child, array($id))),
    "%{$p}t_billing_order%FOR UPDATE%"
);
$printed = array_map('trim', $out);
pin('both waited on the order', 2, $waiting);
pin('neither threw', 0, substr_count(implode('', $out), 'threw'));
pin('both got the same number', array('INV-' . date('Y') . '-00001', 'INV-' . date('Y') . '-00001'), $printed);
pin('the order holds one invoice', 1, count(array_filter(DocumentStore::forOrder($id), static fn (Document $d): bool => $d->type === Document::INVOICE)));

exit(harness_result());
