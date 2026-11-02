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
 * One admin shipping units of an order line, for tests/billing-shipments-race.php. Prints
 * "shipped" or "refused"; any error prints "threw".
 *
 * Usage:  php tests/lib/shipments-child.php <database> <order id> <line id> <admin id> [<qty>]
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id, $line, $adminId] = $argv;
$qty = (int) ($argv[5] ?? 1);
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\OrderHistory;
use mindstellar\billing\Shipments;

try {
    OrderHistory::actAs(OrderHistory::BY_ADMIN, (int) $adminId);
    echo Shipments::add((int) $id, array((int) $line => $qty), 'DHL', 'race-' . $adminId, '') !== null ? "shipped\n" : "refused\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
