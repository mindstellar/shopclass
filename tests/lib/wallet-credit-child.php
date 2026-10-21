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
 * One side of a race to credit a wallet, for tests/billing-wallet-race.php. Prints "credited",
 * or "threw" followed by the error.
 *
 * Usage:  php tests/lib/wallet-credit-child.php <database> <user id> <amount>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $userId, $amount] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\Wallet;

try {
    echo Wallet::credit((int) $userId, (int) $amount, Wallet::REASON_GRANT) ? "credited\n" : "refused\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
