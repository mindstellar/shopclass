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
 * One side of a race for tests/billing-features-race.php. "grant" grants a 5 day highlight on an
 * item; "schedule" reads the item (as a purchase does) and then queues the end of a premium at
 * a given time inside a transaction. Prints "ok", or "threw <message>".
 *
 * Usage:  php tests/lib/billing-features-child.php <database> grant|schedule <item id> [<end time>]
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $mode, $id] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\billing\ItemUpgradeStore;
use mindstellar\billing\Premium;
use mindstellar\database\Db;

try {
    if ($mode === 'grant') {
        ItemUpgradeStore::grant((int) $id, 'item.highlight', 5);
    } else {
        Premium::registerJobs();
        Db::transaction(static function () use ($id, $argv): void {
            Db::scalar('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ?', array((int) $id));
            Premium::scheduleEnd($argv[4]);
        });
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo 'threw ' . $e->getMessage() . "\n";
}
