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
 * One side of a race between the delete of an unpaid listing and its payment, for
 * tests/models/listing-hold.php. Runs the due delete job (expire) or the daily sweep (sweep),
 * then prints "kept" or "deleted", or "threw" followed by the error.
 *
 * Usage:  php tests/lib/listing-expire-child.php <database> <listing id> <expire|sweep>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $itemId, $mode] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\job\JobWorker;
use mindstellar\listing\ListingHoldJobs;
use mindstellar\listing\ListingStore;

try {
    if ($mode === 'sweep') {
        ListingHoldJobs::sweep();
    } else {
        JobWorker::resetRegistration();
        JobWorker::run(10);
    }
    echo ListingStore::find((int) $itemId, array('pk_i_id')) === null ? "deleted\n" : "kept\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
