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
 * One side of a race on orders, for tests/billing-stock.php. "pay" prints "taken" when the order
 * got its stock, "short" when it was marked oversold; "cancel" prints "cancelled" or "kept";
 * "release" prints "released" or "none". "place" orders one unit of a variant (the id in place of
 * the order id) for a buyer through a gateway and prints "placed". Any error prints "threw".
 *
 * Usage:  php tests/lib/order-pay-child.php <database> <order id> [pay|cancel|release]
 *         php tests/lib/order-pay-child.php <database> <variant id> place <user id> <gateway>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id] = $argv;
$action = $argv[3] ?? 'pay';
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Billing;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;

try {
    if ($action === 'place') {
        // The same in-memory settings tests/billing-stock.php runs with.
        foreach ([
            'billing_enabled'          => '1',
            'billing_currency'         => 'USD',
            'currency'                 => 'USD',
            'billing_receipt_email'    => '0',
            'billing_cod_enabled'      => '1',
            'billing_cod_fee'          => '2000000',
            'billing_open_hold_orders' => '0',
        ] as $k => $v) {
            Preference::getInstance()->set($k, $v);
        }
        scratchdb_forget_cache();
        osc_reset_preferences();
        $address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];
        (new GoodsCheckout())->place((int) $argv[4], [['variant' => (int) $id, 'qty' => 1]], $address, 'standard', (string) $argv[5]);
        echo "placed\n";
        exit(0);
    }
    if ($action === 'cancel') {
        echo OrderStore::cancel((int) $id) ? "cancelled\n" : "kept\n";
        exit(0);
    }
    if ($action === 'release') {
        echo OrderStock::release((int) $id) ? "released\n" : "none\n";
        exit(0);
    }
    $order = OrderStore::find((int) $id);
    $paid  = $order !== null && Billing::markPaid($order, 'race-' . $id);
    $now   = OrderStore::find((int) $id);
    echo $paid && $now !== null ? ($now->meta(OrderStock::SHORT) === null ? "taken\n" : "short\n") : "unpaid\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
