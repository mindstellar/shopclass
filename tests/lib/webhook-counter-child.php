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
 * One of several processes counting webhook failures on the same endpoint at once, for
 * tests/models/api-webhooks.php. Prints "paused" for each write of its own that paused the
 * endpoint, then "done".
 *
 * Usage:  php tests/lib/webhook-counter-child.php <database> <endpoint id> <failures>
 */

require_once __DIR__ . '/scratchdb.php';

[, $database, $id, $count] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);

use mindstellar\webhook\Endpoint;
use mindstellar\webhook\WebhookEndpointStore;

$store = new WebhookEndpointStore();
for ($i = 0; $i < (int) $count; $i++) {
    $changed = $store->change($id, static fn (Endpoint $e): Endpoint => $e->withFailure('HTTP 500', time()));
    if ($changed !== null && $changed[0]->enabled() && $changed[1]->paused()) {
        echo "paused\n";
    }
}
echo "done\n";
