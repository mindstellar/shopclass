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
 * One writer of an order, for tests/billing-order-history-race.php. "move" moves the order to
 * <to> from any status as admin <admin> and prints "moved" or "kept"; "pay" settles it paid and
 * prints "paid" or "unpaid"; "meta" stores a plugin value and prints "noted" or "lost". Any
 * error prints "threw".
 *
 * Usage:  php tests/lib/order-history-child.php <database> <order id> move <to> <admin id>
 *         php tests/lib/order-history-child.php <database> <order id> pay|meta
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id, $action] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderHistory;
use mindstellar\billing\OrderStore;

try {
    $id = (int) $id;
    if ($action === 'move') {
        OrderHistory::actAs(OrderHistory::BY_ADMIN, (int) $argv[5]);
        echo OrderStore::transition($id, Order::STATUSES, (string) $argv[4], static fn (array $meta): array => $meta) !== null ? "moved\n" : "kept\n";
    } elseif ($action === 'pay') {
        $order = OrderStore::find($id);
        echo $order !== null && Billing::markPaid($order, 'race-' . $id) ? "paid\n" : "unpaid\n";
    } else {
        echo OrderStore::setMeta($id, 'note', 'kept') ? "noted\n" : "lost\n";
    }
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
