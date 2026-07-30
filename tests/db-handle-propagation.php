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
 * Empirical spike: do the NEW parameterized mindstellar\database\Connection and
 * the LEGACY DBCommandClass share mysqli side-effect state (insert_id /
 * affected_rows) on the singleton connection handle?
 *
 * This is decision-relevant for a future write-path migration. Existing code
 * pervasively does an insert/update through one path and then reads the result
 * through the OTHER on the same $this->dao — e.g. AlertsStats-style code reads
 * $this->dao->insertedId() / affectedRows() (which read $conn->insert_id /
 * $conn->affected_rows on the shared handle) AFTER a write. If a value written
 * via Connection's prepared statement is NOT visible through the legacy getter
 * once the statement has been executed and closed, that interleaving pattern
 * would silently break when a write is migrated to the prepared-statement path.
 *
 * Both APIs resolve to the same mysqli via DBConnectionClass::newInstance()
 * (a singleton). This script proves — or disproves — the shared side-effect
 * state empirically, and prints a permanent verdict.
 *
 * Scratch table + fixtures are seeded with RAW mysqli; the script creates and
 * drops its own scratch database.
 *
 * Usage:  php tests/db-handle-propagation.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (fall back to 127.0.0.1:33061 root/root for the throwaway container)
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('error_log', tempnam(sys_get_temp_dir(), 'prop_'));

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');
define('OSCLASS_VERSION', '5.3.0.dev');
define('OSC_DEBUG_DB', false);
define('OSC_DEBUG_DB_EXPLAIN', false);
define('OSC_DEBUG_DB_LOG', false);
define('DB_TABLE_PREFIX', 'oc_');

$host = getenv('DRIFT_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DRIFT_DB_PORT') ?: 33061);
$user = getenv('DRIFT_DB_USER') ?: 'root';
$pass = getenv('DRIFT_DB_PASS');
if ($pass === false) {
    $pass = 'root';
}

ini_set('mysqli.default_port', (string) $port);

$scratch = 'osc_dao_propagation';

define('DB_HOST', $host);
define('DB_USER', $user);
define('DB_PASSWORD', $pass);
define('DB_NAME', $scratch);

require ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\database\Connection;

/* ---- assertion harness ---------------------------------------------------- */
// NB: deliberately NOT named 'pass'/'fail' — at top-level script scope
// $GLOBALS['pass'] IS the $pass (DB password) variable, so those names collide.
$GLOBALS['okCount']  = 0;
$GLOBALS['failCount'] = 0;

/**
 * Sanity assertion: validates the spike's own setup (Connection returned the
 * expected id/count, same-DB visibility, etc). The cross-handle propagation
 * itself is MEASURED separately, not asserted, because either outcome is a
 * valid finding.
 */
function pin(string $label, $expected, $actual): void
{
    $ok = gettype($expected) === gettype($actual) && $expected === $actual;
    if ($ok) {
        $GLOBALS['okCount']++;
        echo "PASS  $label\n";
    } else {
        $GLOBALS['failCount']++;
        echo "FAIL  $label\n        expected: " . d($expected) . "\n        actual:   " . d($actual) . "\n";
    }
}

function d($v): string
{
    if (is_int($v)) {
        return 'int(' . $v . ')';
    }
    if (is_string($v)) {
        return 'string("' . $v . '")';
    }
    if (is_bool($v)) {
        return 'bool(' . ($v ? 'true' : 'false') . ')';
    }

    return gettype($v);
}

/* ---- scratch database + table (RAW mysqli) -------------------------------- */
mysqli_report(MYSQLI_REPORT_OFF);
$admin = new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_errno) {
    fwrite(STDERR, 'admin connect failed: ' . $admin->connect_error . "\n");
    exit(2);
}
$admin->query("DROP DATABASE IF EXISTS `$scratch`");
if (!$admin->query("CREATE DATABASE `$scratch` DEFAULT CHARACTER SET utf8mb4")) {
    fwrite(STDERR, 'cannot create scratch db: ' . $admin->error . "\n");
    exit(2);
}
$admin->select_db($scratch);
$admin->query(
    'CREATE TABLE prop_test (' .
    ' id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,' .
    ' grp INT NOT NULL DEFAULT 0,' .
    ' s_val VARCHAR(50) NOT NULL' .
    ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4'
);

/* ---- bootstrap the DAO singleton and grab a LEGACY DBCommandClass on it ----
 * DBConnectionClass::newInstance() sets mysqli_report to STRICT and is a
 * singleton; the legacy DBCommandClass and Connection below share its mysqli. */
$conn   = DBConnectionClass::newInstance();
$legacy = new DBCommandClass($conn->getOsclassDb()); // reads insert_id/affected_rows on the shared handle

$clientInfo    = mysqli_get_client_info();
$clientVersion = $conn->getOsclassDb()->client_version ?? 'n/a';
$serverInfo    = $conn->getOsclassDb()->server_info ?? 'n/a';

echo "mysqli client info:    $clientInfo\n";
echo "mysqli client version: $clientVersion\n";
echo "server:                $serverInfo\n\n";

/* ==========================================================================
 * A. insert_id propagation: NEW prepared INSERT -> LEGACY insertedId()
 * ======================================================================== */
echo "== A. Connection::insertGetId(prepared)  ->  DBCommandClass::insertedId() ==\n";
$idA = Connection::instance()->insertGetId(
    'INSERT INTO prop_test (grp, s_val) VALUES (?, ?)',
    array(1, 'first')
);
// The prepared statement has already executed AND been closed inside
// withStatement() by the time we read the legacy connection-level getter.
$legacyIdA = $legacy->insertedId();
echo "  Connection returned id={$idA}; legacy insertedId()={$legacyIdA}\n";
pin('insertGetId() returned a positive int', true, is_int($idA) && $idA > 0);
// MEASUREMENT (not a test failure either way): does the legacy connection-level
// getter observe the id written by the new prepared-statement path?
$measure_idA = ($legacyIdA === $idA);
echo '  [measure] insert_id propagates to legacy handle (A): ' . ($measure_idA ? 'YES' : 'NO') . "\n";

/* ==========================================================================
 * B. affected_rows propagation: NEW prepared UPDATE -> LEGACY affectedRows()
 * ======================================================================== */
echo "\n== B. Connection::execute(prepared UPDATE)  ->  DBCommandClass::affectedRows() ==\n";
// Seed 3 rows in group 7, all with a value that WILL change on update.
foreach (array('a', 'b', 'c') as $v) {
    $admin->query("INSERT INTO prop_test (grp, s_val) VALUES (7, '$v')");
}
$nB = Connection::instance()->execute('UPDATE prop_test SET s_val = ? WHERE grp = ?', array('updated', 7));
$legacyAffB = $legacy->affectedRows();
echo "  Connection execute() reported {$nB} rows; legacy affectedRows()={$legacyAffB}\n";
pin('Connection execute() changed exactly 3 rows', 3, $nB);
// MEASUREMENT: does conn->affected_rows (read by the legacy getter) observe the
// row count from the new prepared UPDATE after the statement was closed?
$measure_affB = ($legacyAffB === $nB);
echo '  [measure] affected_rows propagates to legacy handle (B): ' . ($measure_affB ? 'YES' : "NO (conn->affected_rows={$legacyAffB})") . "\n";

/* ==========================================================================
 * C. Second insert + reverse/interleaved ordering.
 * ======================================================================== */
echo "\n== C1. second Connection insert  ->  legacy insertedId() ==\n";
$idC = Connection::instance()->insertGetId('INSERT INTO prop_test (grp, s_val) VALUES (?, ?)', array(1, 'second'));
$legacyIdC = $legacy->insertedId();
echo "  Connection returned id={$idC}; legacy insertedId()={$legacyIdC}\n";
pin('AUTO_INCREMENT advanced (id2 > id1)', true, $idC > $idA);
$measure_idC = ($legacyIdC === $idC);
echo '  [measure] insert_id propagates to legacy handle (C1): ' . ($measure_idC ? 'YES' : 'NO') . "\n";

echo "\n== C2. reverse direction: LEGACY write  ->  NEW Connection reads it on shared handle ==\n";
// Write through the legacy builder, then read the row back through the new API.
$legacy->from('prop_test');
$legacy->set(array('grp' => 2, 's_val' => 'legacy'));
$okLegacyInsert = $legacy->insert();
$legId = $legacy->insertedId();
$back  = Connection::instance()->selectOne('SELECT id, s_val FROM prop_test WHERE id = ?', array($legId));
echo "  legacy insert id={$legId}; Connection selectOne read s_val=" . var_export($back['s_val'] ?? null, true) . "\n";
pin('legacy builder insert() succeeded', true, $okLegacyInsert === true);
pin('NEW Connection reads the row the LEGACY path just wrote', 'legacy', $back['s_val'] ?? null, true);

echo "\n== C3. interleaved: extra group-2 rows, NEW update, LEGACY affectedRows() ==\n";
$admin->query("INSERT INTO prop_test (grp, s_val) VALUES (2, 'y')");
$admin->query("INSERT INTO prop_test (grp, s_val) VALUES (2, 'y')");
// group 2 now: the 'legacy' row + 2 'y' rows = 3 rows, all change to 'batch2'.
$nC = Connection::instance()->execute('UPDATE prop_test SET s_val = ? WHERE grp = ?', array('batch2', 2));
$legacyAffC = $legacy->affectedRows();
echo "  Connection execute() reported {$nC} rows; legacy affectedRows()={$legacyAffC}\n";
pin('interleaved update changed exactly 3 rows', 3, $nC);
$measure_affC = ($legacyAffC === $nC);
echo '  [measure] affected_rows propagates to legacy handle (C3): ' . ($measure_affC ? 'YES' : "NO (conn->affected_rows={$legacyAffC})") . "\n";

/* ---- teardown ------------------------------------------------------------- */
$admin->query("DROP DATABASE IF EXISTS `$scratch`");
$admin->close();

/* ---- verdict --------------------------------------------------------------
 * The two side-effect channels behave DIFFERENTLY, which is the whole finding:
 *   - insert_id      is copied onto the connection by mysqli, so it survives the
 *                    prepared statement being closed and the legacy getter reads it.
 *   - affected_rows  for a prepared statement lives on the mysqli_stmt; once the
 *                    statement is closed, conn->affected_rows reports -1 ("unknown"),
 *                    so the legacy getter can NEVER see it.
 * ------------------------------------------------------------------------- */
$insertIdWorks = $measure_idA && $measure_idC;
$affectedWorks = $measure_affB && $measure_affC;

echo "\n----------------------------------------\n";
echo "sanity assertions: {$GLOBALS['okCount']} passed, {$GLOBALS['failCount']} failed\n\n";
echo 'SHARED-HANDLE PROPAGATION (insert_id):     ' . ($insertIdWorks ? 'WORKS' : 'DOES NOT WORK') . "\n";
echo 'SHARED-HANDLE PROPAGATION (affected_rows): ' . ($affectedWorks ? 'WORKS' : 'DOES NOT WORK') . "\n\n";

if ($insertIdWorks && $affectedWorks) {
    echo "SHARED-HANDLE PROPAGATION: WORKS\n";
    echo "  => Both insertedId() and affectedRows() read correctly on the shared\n";
    echo "     handle after a Connection prepared-statement write; the legacy\n";
    echo "     interleaving pattern survives a write-path migration.\n";
} elseif (!$insertIdWorks && !$affectedWorks) {
    echo "SHARED-HANDLE PROPAGATION: DOES NOT WORK\n";
    echo "  => Neither getter observes a Connection prepared-statement write.\n";
} else {
    // The measured reality on this platform.
    echo "SHARED-HANDLE PROPAGATION: DOES NOT WORK (partial)\n";
    echo "  => insert_id DOES propagate, but affected_rows DOES NOT: after a\n";
    echo "     prepared UPDATE is executed and closed, conn->affected_rows is -1,\n";
    echo "     so the legacy \$this->dao->affectedRows() reads -1, not the count.\n";
    echo "  => Migrating a write to Connection's prepared path is SAFE for code that\n";
    echo "     reads insertedId() afterwards, but BREAKS code that reads\n";
    echo "     affectedRows() afterwards. Such call sites must take the count from\n";
    echo "     Connection::execute()'s return value instead of the legacy getter.\n";
}

// A spike EXIT reflects whether the measurement ran cleanly (its own sanity
// checks held), NOT which way propagation went — either direction is a valid,
// successful determination.
exit($GLOBALS['failCount'] === 0 ? 0 : 1);
