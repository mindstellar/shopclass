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
 * One side of a race to publish a held listing, for tests/models/listing-checkout.php. Prints
 * "published", the refusal reason, or "threw" followed by the error.
 *
 * Usage:  php tests/lib/listing-publish-child.php <database> <listing id> <user id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $itemId, $userId] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\auth\Actor;
use mindstellar\billing\ListingCheckout;

try {
    osc_register_billing_slot();
    $result = ListingCheckout::publish((int) $itemId, Actor::user((int) $userId));
    echo $result['published'] ? "published\n" : ($result['reason'] . "\n");
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
