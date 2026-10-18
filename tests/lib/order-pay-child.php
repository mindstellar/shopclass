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
 * One of two paid callbacks settling two orders for the same last unit, for tests/billing-stock.php.
 * Prints "taken" when the order got its stock, "short" when it was marked oversold, "threw" on an error.
 *
 * Usage:  php tests/lib/order-pay-child.php <database> <order id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Billing;
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;

try {
    $order = OrderStore::find((int) $id);
    $paid  = $order !== null && Billing::markPaid($order, 'race-' . $id);
    $now   = OrderStore::find((int) $id);
    echo $paid && $now !== null ? ($now->meta(OrderStock::SHORT) === null ? "taken\n" : "short\n") : "unpaid\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
