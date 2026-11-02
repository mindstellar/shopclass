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
 * One issuer of an order's invoice, for tests/billing-invoice-race.php. Calls Invoices::issue()
 * for <order id> and prints the invoice number; no invoice prints "none", an error "threw".
 *
 * Usage:  php tests/lib/invoice-issue-child.php <database> <order id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Invoices;
use mindstellar\billing\OrderStore;

try {
    $order = OrderStore::find((int) $id);
    echo $order === null ? 'none' : (Invoices::issue($order)?->number ?? 'none'), "\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
