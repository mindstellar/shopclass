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
// MariaDB refuses to retype a column other tables point at, even with checks off, so this scratch copy drops those keys.
$refs = $admin->query("SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '{$p}t_locale'");
foreach ($refs->fetch_all(MYSQLI_ASSOC) as $ref) {
    $admin->query("ALTER TABLE `{$ref['TABLE_NAME']}` DROP FOREIGN KEY `{$ref['CONSTRAINT_NAME']}`");
}
$admin->query("ALTER TABLE {$p}t_locale MODIFY pk_c_code CHAR(5) CHARACTER SET latin1 NOT NULL");
$msg = '';
try {
    $mig->up($conn);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
$created = (int) $admin->query("SHOW TABLES LIKE '{$p}t_location_name'")->num_rows;
$admin->query("ALTER TABLE {$p}t_locale MODIFY pk_c_code CHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
pin('a latin1 language table is refused in plain words', true, str_contains($msg, 'utf8mb4') && str_contains($msg, 't_locale'));
pin('and nothing is created', 0, $created);

$mig->up($conn);
pin('a utf8mb4_unicode_ci language table gets a matching table', 1, (int) $admin->query("SHOW TABLES LIKE '{$p}t_location_name'")->num_rows);

exit(harness_result());
