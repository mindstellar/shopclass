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
 * One issuer of invoices, for tests/billing-document-race.php. Issues <count> invoices for
 * <order id> dated <date> and prints each number on its own line; an error prints "threw".
 *
 * Usage:  php tests/lib/document-number-child.php <database> <order id> <count> <date>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $id, $count, $date] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\OrderStore;

try {
    $order = OrderStore::find((int) $id);
    for ($i = 0; $i < (int) $count; $i++) {
        echo DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), $date)->number, "\n";
    }
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
