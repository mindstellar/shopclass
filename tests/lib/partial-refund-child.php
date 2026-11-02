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
 * One refund of an order, for tests/billing-partial-refund-race.php. "part" refunds <amount>
 * micros and prints "refunded" or "refused"; "full" records the whole refund, as a webhook does,
 * and prints "refunded" or "kept". Any error prints "threw".
 *
 * Usage:  php tests/lib/partial-refund-child.php <database> <order id> part <amount>
 *         php tests/lib/partial-refund-child.php <database> <order id> full
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id, $action] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\OrderStore;

try {
    $order = OrderStore::find((int) $id);
    if ($action === 'part') {
        $result = Billing::refundPart($order, (int) $argv[4], array(), 'race', false, false);
        echo $result->getOutcome() === CallbackResult::OUTCOME_REFUNDED ? "refunded\n" : "refused " . $result->getMessage() . "\n";
    } else {
        echo Billing::refund($order) ? "refunded\n" : "kept\n";
    }
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
