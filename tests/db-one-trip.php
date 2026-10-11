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
 * Connection sends values escaped inside the query, in one trip, unless OSC_DB_REAL_PREPARES is on.
 * Each hostile value must come back exactly as stored and never change the query.
 *
 * Usage:  php tests/db-one-trip.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\database\DbException;

$admin = scratchdb_session('osc_one_trip');
$db    = Connection::getInstance();
$t     = DB_TABLE_PREFIX . 't_one_trip';

$admin->query("DROP TABLE IF EXISTS $t");
$admin->query("CREATE TABLE $t (id INT AUTO_INCREMENT PRIMARY KEY, s MEDIUMTEXT NULL, n INT NULL, f DOUBLE NULL) DEFAULT CHARSET=utf8mb4");

$prepares = static fn (): int => (int) $admin->query("SHOW GLOBAL STATUS LIKE 'Com_stmt_prepare'")->fetch_row()[1];

harness_section('one trip, no prepared statement');

$before = $prepares();
$id     = $db->insertGetId("INSERT INTO $t (s, n, f) VALUES (?, ?, ?)", array('plain', 5, 1.25));
$row    = $db->selectOne("SELECT s, n, f FROM $t WHERE id = ?", array($id));
pin('an insert and a read with values prepare nothing', 0, $prepares() - $before);
pin('every column comes back as text, as a query without values does', array('plain', '5', '1.25'), array_values($row));
pin('execute reports the rows it changed', 1, $db->execute("UPDATE $t SET n = ? WHERE id = ?", array(6, $id)));

harness_section('hostile values are stored as they are');

$hostile = array(
    "it's",
    'back\\slash',
    "trailing\\",
    "'; DROP TABLE $t; --",
    "\" OR 1=1 --",
    "line\nbreak\r\n\0nul\x1a",
    'percent % and _ under',
    'a ? in a value',
    "emoji 😀 and अक्षर",
    "é' a quote after a multi-byte letter",
    str_repeat('x', 70000),
);
foreach ($hostile as $i => $value) {
    $id   = $db->insertGetId("INSERT INTO $t (s) VALUES (?)", array($value));
    $back = $db->scalar("SELECT s FROM $t WHERE id = ? AND s = ?", array($id, $value));
    check('value ' . $i . ' comes back byte for byte', $back === $value, var_export(substr((string) $back, 0, 40), true));
}
$rows = (int) $db->scalar("SELECT COUNT(*) FROM $t");
try {
    $db->insertGetId("INSERT INTO $t (s) VALUES (?)", array("\xbf' OR 1=1 -- "));
} catch (DbException $e) {
    // Refusing it is fine; running it is not.
}
pin('the table is still there after a byte that is not UTF-8', 1, (int) $admin->query("SHOW TABLES LIKE '$t'")->num_rows);
check('and at most the one row tried was added', (int) $db->scalar("SELECT COUNT(*) FROM $t") <= $rows + 1);

harness_section('a ? that is not a placeholder is kept');

pin('inside single quotes', 'a?b', $db->scalar("SELECT 'a?b' FROM $t WHERE id = ?", array($id)));
pin('inside double quotes', 'c?d', $db->scalar("SELECT \"c?d\" FROM $t WHERE id = ?", array($id)));
pin('after an escaped quote', "e'?", $db->scalar("SELECT 'e\\'?' FROM $t WHERE id = ?", array($id)));
pin('after a doubled quote', "f'?", $db->scalar("SELECT 'f''?' FROM $t WHERE id = ?", array($id)));
pin('inside a quoted name', '1', $db->scalar("SELECT 1 AS `odd?name` FROM $t WHERE id = ?", array($id)));
pin('inside a block comment', '2', $db->scalar("SELECT /* ? */ 2 FROM $t WHERE id = ?", array($id)));
pin('inside a line comment', '3', $db->scalar("SELECT 3 FROM $t -- ?\nWHERE id = ?", array($id)));
pin('inside a hash comment', '4', $db->scalar("SELECT 4 FROM $t # ?\nWHERE id = ?", array($id)));

harness_section('values of each type');

pin('null, true, false', array(null, '1', '0'), array_values($db->selectOne('SELECT ? AS a, ? AS b, ? AS c', array(null, true, false))));
pin('a float keeps its digits', '0.1', $db->scalar('SELECT ?', array(0.1)));
pin('a negative int', '-42', $db->scalar('SELECT ?', array(-42)));
$db->execute("INSERT INTO $t (s) VALUES (?)", array('007'));
pin('a numeric string is compared as text', array('007'), array_column($db->select("SELECT s FROM $t WHERE s = ?", array('007')), 's'));

$fails = static function (callable $fn): bool {
    try {
        $fn();
    } catch (DbException $e) {
        return true;
    }

    return false;
};
check('an array value is refused', $fails(static fn () => @$db->select('SELECT ?', array(array(1)))));
check('an object value is refused', $fails(static fn () => @$db->select('SELECT ?', array(new stdClass()))));
check('an infinite float is refused', $fails(static fn () => $db->select('SELECT ?', array(INF))));
check('too few values are refused', $fails(static fn () => $db->select('SELECT ?, ?', array(1))));
check('too many values are refused', $fails(static fn () => $db->select('SELECT ?', array(1, 2))));
check('a numeric string in LIMIT is refused, not run', $fails(static fn () => $db->select("SELECT id FROM $t LIMIT ?", array('1'))));

harness_section('SQL the scanner cannot be sure of goes as a real prepared statement');

$noPwn = static function (callable $fn): bool {
    try {
        $row = $fn();
    } catch (DbException $e) {
        return true;
    }

    return !is_array($row) || !array_key_exists('pwn', $row);
};
$payload = ', (SELECT USER()) AS pwn, ';
$before  = $prepares();
check('a backslash in quotes, default mode', $noPwn(static fn () => $db->selectOne("SELECT 'a\\' AS x, ' ? ' AS c", array($payload))));
check('so it was prepared', $prepares() > $before);
check('a ? in a /*! comment', $noPwn(static fn () => $db->selectOne("SELECT 1 /*! , ? AS c */", array($payload))));
check('-- then a tab', $noPwn(static fn () => $db->selectOne("SELECT 1 AS a --\t?\n", array($payload))));

harness_section('with NO_BACKSLASH_ESCAPES');

$db->execute("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')");
foreach (array("it's", 'back\\slash', "x\\' OR 1=1 -- ") as $i => $value) {
    $id   = $db->insertGetId("INSERT INTO $t (s) VALUES (?)", array($value));
    $back = $db->scalar("SELECT s FROM $t WHERE id = ? AND s = ?", array($id, $value));
    check('value ' . $i . ' comes back byte for byte', $back === $value, var_export($back, true));
}
check('a backslash in quotes cannot turn a value into SQL', $noPwn(static fn () => $db->selectOne("SELECT 'a\\' AS x, ' ? ' AS c", array($payload))));
check('-- then a tab cannot either', $noPwn(static fn () => $db->selectOne("SELECT 1 AS a --\t?\n", array($payload))));
$db->execute("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', '')");

harness_section('with ANSI_QUOTES');

$db->execute("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ANSI_QUOTES')");
check('a backslash in double quotes cannot turn a value into SQL', $noPwn(static fn () => $db->selectOne("SELECT 1 AS \"a\\\", '\" ? ' AS c", array($payload))));
pin('a plain value still works', '5', (string) $db->scalar('SELECT ?', array(5)));
$db->execute("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'ANSI_QUOTES', '')");

$admin->query("DROP TABLE IF EXISTS $t");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
