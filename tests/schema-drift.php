<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Schema drift check.
 *
 * Proves that the two ways an install arrives at its schema converge:
 *
 *   FRESH    empty DB  ->  import current struct.sql
 *   UPGRADE  empty DB  ->  import BASELINE struct.sql (last release)
 *                          ->  updateDB(current struct.sql)   (additive reconcile)
 *                          ->  MigrationRunner::run()         (everything additive can't do)
 *
 * If the normalised schemas differ, the current struct.sql was changed in a way the
 * upgrade path does not reproduce — i.e. a migration is owed (or struct.sql is wrong).
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
    if (!$admin->query("CREATE DATABASE `$db` DEFAULT CHARACTER SET utf8")) {
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

// ---- FRESH ---------------------------------------------------------------
$fresh = comm_for($freshDb);
$fresh->importSQL($currentStruct);

// ---- UPGRADE -------------------------------------------------------------
$upgrade = comm_for($upgradeDb);
$upgrade->importSQL($baselineStruct);
$reconciled = str_replace('/*TABLE_PREFIX*/', DB_TABLE_PREFIX, $currentStruct);
$result = $upgrade->updateDB($reconciled);
if (!$result[0]) {
    fwrite(STDERR, "updateDB reported failing queries:\n" . implode("\n", $result[2]) . "\n");
    exit(2);
}
$runner = new MigrationRunner($upgrade, $migrationsDir);
$runner->ensureLedger();
$migrated = $runner->run();
if (!$migrated['ok']) {
    fwrite(STDERR, 'migration failed: ' . $migrated['failed'] . ' — ' . $migrated['error'] . "\n");
    exit(2);
}

// ---- COMPARE -------------------------------------------------------------
$a = dump_schema($host, $user, $pass, $freshDb);
$b = dump_schema($host, $user, $pass, $upgradeDb);

$admin->query("DROP DATABASE IF EXISTS `$freshDb`");
$admin->query("DROP DATABASE IF EXISTS `$upgradeDb`");

if ($a === $b) {
    echo "OK — fresh install and upgrade path produce identical schemas.\n";
    exit(0);
}

fwrite(STDERR, "SCHEMA DRIFT DETECTED — fresh install and upgrade path diverge.\n");
fwrite(STDERR, "A migration is owed for the change in struct.sql (or struct.sql is wrong).\n\n");
fwrite(STDERR, unified_diff($a, $b) . "\n");
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
            $lines[] = $line;
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
