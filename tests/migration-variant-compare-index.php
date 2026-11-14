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
 * Migration 0093 indexes t_item_variant.i_compare_price once: a re-run adds nothing, and an index
 * an admin already added on the column counts. A fresh struct.sql install already has it.
 *
 * Usage:  php tests/migration-variant-compare-index.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_migration_variant_compare_index');
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;
$mig   = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0093_variant_compare_price_index.php';

$indexes = static fn (): array => array_column($admin->query("SHOW INDEX FROM {$p}t_item_variant WHERE Column_name = 'i_compare_price'")->fetch_all(MYSQLI_ASSOC), 'Key_name');
pin('a fresh install has the index', ['idx_compare_price'], $indexes());

$admin->query("ALTER TABLE {$p}t_item_variant DROP INDEX idx_compare_price");
pin('no index before the run', [], $indexes());
$mig->up($conn);
pin('the run adds it', ['idx_compare_price'], $indexes());
$mig->up($conn);
pin('a second run adds nothing', ['idx_compare_price'], $indexes());

$admin->query("ALTER TABLE {$p}t_item_variant DROP INDEX idx_compare_price, ADD INDEX my_sale (i_compare_price)");
$mig->up($conn);
pin('an index already on the column under another name counts', ['my_sale'], $indexes());

exit(harness_result());
