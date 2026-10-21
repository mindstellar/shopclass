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
 * One poster for tests/listing-counters-after-commit.php: reads in a transaction, then counts a
 * listing in. Prints "ok", or "threw" with the error.
 *
 * Usage:  php tests/lib/listing-counter-child.php <database> <user id> <category id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $userId, $categoryId] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

use mindstellar\database\Db;
use mindstellar\listing\ListingStats;

try {
    Db::transaction(static function () use ($userId, $categoryId) {
        Db::select('SELECT s_name FROM ' . DB_TABLE_PREFIX . 't_user WHERE pk_i_id = ?', [(int) $userId]);
        ListingStats::increase([
            'fk_i_user_id'      => (int) $userId,
            'fk_i_category_id'  => (int) $categoryId,
            'fk_c_country_code' => '',
            'fk_i_region_id'    => '',
            'fk_i_city_id'      => '',
        ]);
    });
    echo "ok\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
