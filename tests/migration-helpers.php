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
 * under a new name, and raising an AUTO_INCREMENT counter without ever lowering it. Also
 * pins that 0001 re-run on a utf8mb4 schema converts only what is still utf8.
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

harness_section('0001: re-run on a utf8mb4 schema');

$collation = static function (string $table, string $column) use ($admin): string {
    $row = $admin->query('SELECT COLLATION_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . " AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'")->fetch_row();

    return $row[0] . ' ' . $row[1];
};
$kv     = DB_TABLE_PREFIX . 't_key_value';
$parent = DB_TABLE_PREFIX . 't_mig_legacy_parent';
$child  = DB_TABLE_PREFIX . 't_mig_legacy_child';
$admin->query("DROP TABLE IF EXISTS $child, $parent");
$admin->query("CREATE TABLE $parent (s_code VARCHAR(10) NOT NULL PRIMARY KEY, s_body TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
$admin->query("CREATE TABLE $child (pk_i_id INT AUTO_INCREMENT PRIMARY KEY, fk_s_code VARCHAR(10) NOT NULL, KEY fk_s_code (fk_s_code),"
    . " CONSTRAINT fk_mig_legacy FOREIGN KEY (fk_s_code) REFERENCES $parent (s_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
$m0001->up($conn);
pin('an ascii_bin column on a utf8mb4 table is left as it is', 'ascii_bin varchar', $collation($kv, 's_group'));
pin('a utf8mb4_bin column on a utf8mb4 table is left as it is', 'utf8mb4_bin varchar', $collation($kv, 's_key'));
pin('a utf8 table is converted', 'utf8mb4_general_ci varchar', $collation($parent, 's_code'));
pin('a converted TEXT column keeps its type', 'utf8mb4_general_ci text', $collation($parent, 's_body'));
pin(
    'the char foreign key between converted tables is back',
    'fk_mig_legacy',
    $admin->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '$child'")->fetch_row()[0] ?? null
);
pin('the snapshot row is cleared', 0, (int) $admin->query('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . "t_preference WHERE s_name = 'utf8mb4_fk_backup'")->fetch_row()[0]);
$admin->query("DROP TABLE IF EXISTS $child, $parent");

harness_section('0001: retry after an interrupted run');

$admin->query("CREATE TABLE $parent (s_code VARCHAR(10) NOT NULL PRIMARY KEY, s_body TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
$admin->query("CREATE TABLE $child (pk_i_id INT AUTO_INCREMENT PRIMARY KEY, fk_s_code VARCHAR(10) NOT NULL, KEY fk_s_code (fk_s_code),"
    . " CONSTRAINT fk_mig_legacy FOREIGN KEY (fk_s_code) REFERENCES $parent (s_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
$legacy = array($parent, $child);
$call($m0001, 'saveBackup', array($conn, array(
    'fks'   => $call($m0001, 'computeCharForeignKeys', array($conn, $call($m0001, 'installTables', array($conn)), $legacy)),
    'texts' => $call($m0001, 'textColumns', array($conn, $legacy)),
)));
$admin->query("ALTER TABLE $child DROP FOREIGN KEY fk_mig_legacy");
$admin->query("ALTER TABLE $parent CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$m0001->up($conn);
pin('the table the first run missed is converted', 'utf8mb4_general_ci varchar', $collation($child, 'fk_s_code'));
pin('the TEXT column the first run widened is restored', 'utf8mb4_general_ci text', $collation($parent, 's_body'));
pin(
    'the foreign key the first run dropped is back',
    'fk_mig_legacy',
    $admin->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '$child'")->fetch_row()[0] ?? null
);
pin('the snapshot row is cleared after the retry', 0, (int) $admin->query('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . "t_preference WHERE s_name = 'utf8mb4_fk_backup'")->fetch_row()[0]);
$admin->query("DROP TABLE IF EXISTS $child, $parent");

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
