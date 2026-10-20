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
 * Helpers inside migrations 0001 and 0071 that the old-server fixes rely on: rebuilding an index
 * under a new name, and raising an AUTO_INCREMENT counter without ever lowering it.
 *
 * Usage:  php tests/migration-helpers.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_migration_helpers');
$conn  = Connection::getInstance();
$t     = DB_TABLE_PREFIX . 't_mig_helper';
$call  = static function (object $migration, string $method, array $args) {
    $m = new ReflectionMethod($migration, $method);
    $m->setAccessible(true);

    return $m->invoke($migration, ...$args);
};
$migrations = ABS_PATH . 'oc-includes/osclass/installer/migrations/';

harness_section('0001: indexDefinition');

$admin->query("DROP TABLE IF EXISTS $t");
$admin->query("CREATE TABLE $t (pk_i_id INT AUTO_INCREMENT PRIMARY KEY, s_code VARCHAR(100), s_name VARCHAR(100), s_body TEXT,"
    . " UNIQUE KEY uq_code (s_code(20), s_name), KEY idx_name (s_name) COMMENT 'by name', FULLTEXT KEY ft_body (s_body)) DEFAULT CHARSET=utf8mb4");
$m0001 = require $migrations . '0001_utf8mb4.php';
pin('a unique index keeps UNIQUE and its prefix length', 'UNIQUE INDEX `renamed` (`s_code`(20), `s_name`)', $call($m0001, 'indexDefinition', array($conn, $t, 'uq_code', 'renamed')));
pin('a plain index keeps its comment', "INDEX `renamed` (`s_name`) COMMENT 'by name'", $call($m0001, 'indexDefinition', array($conn, $t, 'idx_name', 'renamed')));
pin('a fulltext index stays fulltext', 'FULLTEXT INDEX `renamed` (`s_body`)', $call($m0001, 'indexDefinition', array($conn, $t, 'ft_body', 'renamed')));
pin('an index that is gone gives nothing to add', null, $call($m0001, 'indexDefinition', array($conn, $t, 'no_such_index', 'renamed')));

harness_section('0071: raiseAutoIncrement');

$m0071   = require $migrations . '0071_item_photos_to_resource.php';
$counter = static function () use ($admin, $t): int {
    try {
        $admin->query('SET SESSION information_schema_stats_expiry = 0');
    } catch (mysqli_sql_exception $e) {
        // MariaDB has no such variable and reads the live counter anyway.
    }

    return (int) $admin->query("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t'")->fetch_row()[0];
};
$admin->query("INSERT INTO $t (pk_i_id) VALUES (5)");
$admin->query("ALTER TABLE $t AUTO_INCREMENT = 1000");
$call($m0071, 'raiseAutoIncrement', array($conn, $t));
pin('a counter already above the highest id is not lowered', 1000, $counter());
$admin->query("INSERT INTO $t (pk_i_id) VALUES (2000)");
$call($m0071, 'raiseAutoIncrement', array($conn, $t));
check('a counter below a moved id is raised past it', $counter() > 2000, (string) $counter());

$admin->query("DROP TABLE IF EXISTS $t");

exit(harness_result());
