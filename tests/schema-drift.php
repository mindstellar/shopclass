<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Schema drift check.
 *
 * Proves that the two ways an install arrives at its schema converge:
 *
 *   FRESH    empty DB  ->  import current struct.sql
 *   UPGRADE  empty DB  ->  import BASELINE struct.sql (last release)
 *                          ->  MigrationRunner::run()
 *
 * The upgrade path deliberately does NOT run the schema reconciler. Migrations alone
 * have to reproduce struct.sql, and this is what holds them to it.
 *
 * That matters because the reconciler derives its work from struct.sql by inspection,
 * so it silently absorbs any additive change nobody wrote a migration for. With it in
 * this path the check passed either way, and there was no way to tell a schema the
 * migrations actually build from one the reconciler was quietly repairing on every
 * upgrade. Keeping it out draws the line: struct.sql changes, a migration is owed.
 *
 * A second assertion follows the comparison. Once the migrations have run, the
 * reconciler is asked what it would still do, and the answer must be nothing. That is
 * the same property stated from the other side, and it fails with the exact ALTER
 * statements the missing migration should contain rather than with a schema diff.
 *
 * The reconciler itself is unchanged and still runs on a real upgrade, where it stays
 * useful for repairing an install that has drifted by other means -- a hand-edited
 * column, a plugin's leftovers, an upgrade interrupted half way. What it no longer is
 * is load-bearing.
 *
 * Usage:  php tests/schema-drift.php <baseline-struct.sql>
 * Env:    DRIFT_DB_HOST DRIFT_DB_USER DRIFT_DB_PASS  (DB must allow CREATE/DROP DATABASE)
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('error_log', tempnam(sys_get_temp_dir(), 'drift_')); // swallow updateDB()'s error_log noise

if ($argc < 2) {
    fwrite(STDERR, "usage: php tests/schema-drift.php <baseline-struct.sql>\n");
    exit(2);
}
$baselineFile = $argv[1];
if (!is_file($baselineFile)) {
    fwrite(STDERR, "baseline file not found: $baselineFile\n");
    exit(2);
}

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');
define('OSCLASS_VERSION', '5.3.0.dev');
define('OSC_DEBUG_DB', false);
define('OSC_DEBUG_DB_EXPLAIN', false);
define('OSC_DEBUG_DB_LOG', false);
define('DB_TABLE_PREFIX', 'oc_');

$host = getenv('DRIFT_DB_HOST') ?: '127.0.0.1';
$user = getenv('DRIFT_DB_USER') ?: 'root';
$pass = getenv('DRIFT_DB_PASS');
$pass = ($pass === false) ? '' : $pass;

$freshDb   = 'osc_drift_fresh';
$upgradeDb = 'osc_drift_upgrade';

// These are only needed so the DB classes have their default-arg constants defined;
// every connection below passes host/user/pass/db explicitly.
define('DB_HOST', $host);
define('DB_USER', $user);
define('DB_PASSWORD', $pass);
define('DB_NAME', $freshDb);

require ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\database\Connection;
use mindstellar\migration\MigrationRunner;

$currentStruct = file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
$baselineStruct = file_get_contents($baselineFile);
$migrationsDir  = ABS_PATH . 'oc-includes/osclass/installer/migrations';

$admin = new mysqli($host, $user, $pass);
if ($admin->connect_errno) {
    fwrite(STDERR, 'DB connect failed: ' . $admin->connect_error . "\n");
    exit(2);
}
foreach (array($freshDb, $upgradeDb) as $db) {
    $admin->query("DROP DATABASE IF EXISTS `$db`");
    if (!$admin->query("CREATE DATABASE `$db` DEFAULT CHARACTER SET utf8mb4")) {
        fwrite(STDERR, "cannot create $db: " . $admin->error . "\n");
        exit(2);
    }
}

/**
 * @param string $db
 *
 * @return DBCommandClass
 */
function comm_for($db)
{
    $conn = new DBConnectionClass(DB_HOST, DB_USER, DB_PASSWORD, $db);
    return new DBCommandClass($conn->getOsclassDb());
}

/**
 * A parameterized Connection bound to $db rather than to the configured database.
 *
 * @param string $db
 *
 * @return Connection
 */
function connection_for($db)
{
    $conn = new DBConnectionClass(DB_HOST, DB_USER, DB_PASSWORD, $db);
    return new Connection($conn->getOsclassDb());
}

// ---- FRESH ---------------------------------------------------------------
$fresh = comm_for($freshDb);
$fresh->importSQL($currentStruct);

// ---- UPGRADE (migrations only — the reconciler is deliberately not run) ----
$upgrade = comm_for($upgradeDb);
$upgrade->importSQL($baselineStruct);
$runner = new MigrationRunner(connection_for($upgradeDb), $migrationsDir);
$runner->ensureLedger();
$migrated = $runner->run();
if (!$migrated['ok']) {
    fwrite(STDERR, 'migration failed: ' . $migrated['failed'] . ' — ' . $migrated['error'] . "\n");
    exit(2);
}

// ---- COMPARE -------------------------------------------------------------
$a = dump_schema($host, $user, $pass, $freshDb);
$b = dump_schema($host, $user, $pass, $upgradeDb);

// ---- WHAT THE RECONCILER WOULD STILL DO ----------------------------------
// Runs after both schemas have been read, because it writes to the upgraded one.
// struct.sql carries no INSERT or UPDATE, so on a schema the migrations have
// fully built this returns nothing at all; anything it does return is a change
// present in struct.sql that no migration reproduces.
$reconciled = str_replace('/*TABLE_PREFIX*/', DB_TABLE_PREFIX, $currentStruct);
$leftover   = $upgrade->updateDB($reconciled);
$pending    = array_values($leftover[1]);

$admin->query("DROP DATABASE IF EXISTS `$freshDb`");
$admin->query("DROP DATABASE IF EXISTS `$upgradeDb`");

if ($a === $b && $pending === array()) {
    echo "OK — migrations alone reproduce struct.sql, and the reconciler has nothing left to do.\n";
    exit(0);
}

fwrite(STDERR, "SCHEMA DRIFT DETECTED — migrations do not reproduce struct.sql.\n");
fwrite(STDERR, "A migration is owed for the change in struct.sql (or struct.sql is wrong).\n\n");

if ($pending !== array()) {
    fwrite(STDERR, "The reconciler would still run these — this is the migration you owe:\n");
    foreach ($pending as $query) {
        fwrite(STDERR, '  ' . preg_replace('/\s+/', ' ', trim($query)) . "\n");
    }
    fwrite(STDERR, "\n");
}

if ($a !== $b) {
    fwrite(STDERR, unified_diff($a, $b) . "\n");
}

exit(1);

/**
 * Dump a normalised, order-insensitive schema for a database. Each table's CREATE is
 * reduced to sorted body lines with AUTO_INCREMENT counters stripped, so benign
 * column-append ordering does not read as drift while type/key/default changes do.
 *
 * @return string
 */
function dump_schema($host, $user, $pass, $db)
{
    $m = new mysqli($host, $user, $pass, $db);
    $tables = array();
    $res = $m->query('SHOW TABLES');
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        $tables[] = $row[0];
    }
    sort($tables, SORT_STRING);

    $out = array();
    foreach ($tables as $table) {
        $row = $m->query("SHOW CREATE TABLE `$table`")->fetch_array(MYSQLI_NUM);
        $out[] = normalise_ddl($row[1]);
    }
    $m->close();

    return implode("\n\n", $out);
}

/**
 * @param string $ddl SHOW CREATE TABLE output
 *
 * @return string
 */
function normalise_ddl($ddl)
{
    $open  = strpos($ddl, '(');
    $close = strrpos($ddl, ')');
    $head  = trim(substr($ddl, 0, $open));
    $body  = substr($ddl, $open + 1, $close - $open - 1);
    $tail  = substr($ddl, $close + 1);

    $lines = array();
    foreach (explode("\n", $body) as $line) {
        $line = trim(rtrim(trim($line), ','));
        if ($line !== '') {
            // struct.sql declares its foreign keys anonymously, so MySQL names them
            // `<table>_ibfk_<n>` by creation order. A migration that rebuilds a key
            // drops it and adds it back, which takes the next free number -- so the
            // same key is _ibfk_1 on a fresh install and _ibfk_6 on an upgraded one.
            // The number records the order the keys happened to be created in, not
            // anything about the schema, and no code refers to it (constraints are
            // resolved through information_schema), so it is flattened rather than
            // reported as drift. A difference that matters -- a key present on one
            // path only, or pointing somewhere else, or carrying a different ON
            // DELETE rule -- still shows, because the rest of the line is kept.
            //
            // Flattened BEFORE the sort, or the lines sort by a number that is about
            // to be erased and two identical schemas come out in different orders.
            $lines[] = preg_replace('/_ibfk_\d+`/', '_ibfk_N`', $line);
        }
    }
    sort($lines, SORT_STRING);

    // Table-level AUTO_INCREMENT counter is install-specific; drop it.
    $tail = preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $tail);

    return $head . " (\n  " . implode("\n  ", $lines) . "\n)" . rtrim($tail);
}

/**
 * @return string
 */
function unified_diff($a, $b)
{
    $al = explode("\n", $a);
    $bl = explode("\n", $b);
    $set = array();
    foreach ($bl as $l) {
        $set[$l] = true;
    }
    $diff = array();
    foreach ($al as $l) {
        if (!isset($set[$l])) {
            $diff[] = '- (fresh)   ' . $l;
        }
    }
    $set = array();
    foreach ($al as $l) {
        $set[$l] = true;
    }
    foreach ($bl as $l) {
        if (!isset($set[$l])) {
            $diff[] = '+ (upgrade) ' . $l;
        }
    }

    return implode("\n", $diff);
}
