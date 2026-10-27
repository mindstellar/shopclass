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
 * Migration 0088 stops with a plain message when the language table is not utf8mb4, and
 * builds the table against whatever utf8mb4 collation that table uses.
 *
 * Usage:  php tests/migration-location-names.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_migration_location_names');
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;
$mig   = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0088_location_names.php';

$admin->query("DROP TABLE IF EXISTS {$p}t_location_name");
$admin->query("ALTER TABLE {$p}t_locale MODIFY pk_c_code CHAR(5) CHARACTER SET latin1 NOT NULL");
$msg = '';
try {
    $mig->up($conn);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
pin('a latin1 language table is refused in plain words', true, str_contains($msg, 'utf8mb4') && str_contains($msg, 't_locale'));
pin('and nothing is created', 0, (int) $admin->query("SHOW TABLES LIKE '{$p}t_location_name'")->num_rows);

$admin->query("ALTER TABLE {$p}t_locale MODIFY pk_c_code CHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
$mig->up($conn);
pin('a utf8mb4_unicode_ci language table gets a matching table', 1, (int) $admin->query("SHOW TABLES LIKE '{$p}t_location_name'")->num_rows);

exit(harness_result());
