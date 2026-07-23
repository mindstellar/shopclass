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
 * DAO contract characterization ("golden") tests.
 *
 * Pins the CURRENT, observable behaviour of the DAO base methods
 * (classes/database/DAO.php on top of DBCommandClass) so that any future change
 * to their return TYPE or VALUE is caught as a regression. These are not
 * aspirational tests — several pinned contracts are quirky or dangerous on
 * purpose; the point is that they are load-bearing for existing models and
 * third-party plugins and must not drift silently.
 *
 * Models are chosen to exercise the *base* methods with no relevant override:
 *   - Region       (t_region, PK pk_i_id AUTO_INCREMENT): findByPrimaryKey,
 *                  exists*, insert, update, updateByPrimaryKey, delete, listAll,
 *                  count. Region overrides ONLY deleteByPrimaryKey (cascade), so
 *                  the base deleteByPrimaryKey shape is pinned via Currency.
 *   - Currency     (t_currency, PK pk_c_code; incoming FK from t_item): the
 *                  FK-violation (1451) delete and the base deleteByPrimaryKey.
 *   - AlertsStats  (t_alerts_sent, PK d_date): the duplicate-key (1062) insert
 *                  that AlertsStats::increase() relies on via getErrorLevel().
 *
 * NOTE (contradicts the design-review assumption): Currency is NOT override-free
 * for findByPrimaryKey — it overrides it with a process-lifetime static cache
 * (self::$_currencies). The genuine base findByPrimaryKey is therefore pinned via
 * Region; Currency's cached override is pinned separately and explicitly below.
 *
 * All fixtures are seeded with RAW mysqli (admin connection) — never through the
 * code under test — and this script creates and drops its own scratch database.
 *
 * Usage:  php tests/dao-contract.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (fall back to 127.0.0.1:33061 root/root for the throwaway container)
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('error_log', tempnam(sys_get_temp_dir(), 'daoc_')); // swallow error_log noise

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
    $pass = 'root'; // throwaway-container default; DRIFT_DB_PASS='' still means empty
}

// DBConnectionClass builds its mysqli() without a port argument, so point the
// default mysqli TCP port at our container. Raw admin connections pass $port too.
ini_set('mysqli.default_port', (string) $port);

$scratch = 'osc_dao_contract';

// Default-arg constants for the DB classes; every DAO connection resolves to
// the singleton built from these.
define('DB_HOST', $host);
define('DB_USER', $user);
define('DB_PASSWORD', $pass);
define('DB_NAME', $scratch);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
// Composer's autoloader covers classes only. Models whose bodies have moved to
// the parameterized query layer call the osc_db_* helpers, which oc-load.php
// would normally have loaded, so a standalone script has to require them itself.
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDatabase.php';

use mindstellar\database\Connection;

/* ----------------------------------------------------------------------------
 * Tiny assertion harness (no PHPUnit).
 * ------------------------------------------------------------------------- */
// NB: deliberately NOT named 'pass'/'fail' — at top-level script scope
// $GLOBALS['pass'] IS the $pass (DB password) variable, so those names collide.
$GLOBALS['okCount']  = 0;
$GLOBALS['failCount'] = 0;

/**
 * Pin an actual value with a strict === comparison, reporting type + value.
 */
function pin(string $label, $expected, $actual): void
{
    $okType = gettype($expected) === gettype($actual);
    $ok     = $okType && $expected === $actual;
    report($label, $ok, describe($expected), describe($actual));
}

/**
 * Assert a boolean condition, with an optional detail string on failure.
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    report($label, $ok, 'true', $ok ? 'true' : ('false' . ($detail ? " ($detail)" : '')));
}

function report(string $label, bool $ok, string $expected, string $actual): void
{
    if ($ok) {
        $GLOBALS['okCount']++;
        echo "PASS  $label\n";
    } else {
        $GLOBALS['failCount']++;
        echo "FAIL  $label\n        expected: $expected\n        actual:   $actual\n";
    }
}

function describe($v): string
{
    if (is_bool($v)) {
        return 'bool(' . ($v ? 'true' : 'false') . ')';
    }
    if (is_int($v)) {
        return 'int(' . $v . ')';
    }
    if (is_string($v)) {
        return 'string("' . $v . '")';
    }
    if (is_array($v)) {
        return 'array(' . count($v) . ')';
    }
    if ($v === null) {
        return 'null';
    }

    return gettype($v);
}

/** Every value in an assoc row must be a string (or null) under legacy mysqli. */
function all_values_string(array $row): bool
{
    foreach ($row as $v) {
        if ($v !== null && !is_string($v)) {
            return false;
        }
    }

    return true;
}

/* ----------------------------------------------------------------------------
 * Scratch database + schema (RAW mysqli).
 * ------------------------------------------------------------------------- */
mysqli_report(MYSQLI_REPORT_OFF); // manage admin errors by hand
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

$struct = file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
$struct = str_replace(
    array('/*TABLE_PREFIX*/', '/*OSCLASS_VERSION*/'),
    array(DB_TABLE_PREFIX, OSCLASS_VERSION),
    $struct
);
$struct = preg_replace('#/\*(?:[^*]*(?:\*(?!/))*)*\*/#', '', $struct); // strip block comments
foreach (explode(';', $struct) as $q) {
    $q = trim($q);
    if ($q !== '' && !$admin->query($q)) {
        fwrite(STDERR, "schema import failed: {$admin->error}\n  on: " . substr($q, 0, 120) . "\n");
        exit(2);
    }
}

// Seed fixtures with FK checks off so ordering/parent rows are irrelevant. This
// only loosens the ADMIN session; the DAO singleton uses its own connection with
// FK enforcement intact (that is what the 1451 delete test depends on).
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
$admin->query("INSERT INTO oc_t_country (pk_c_code, s_name, s_slug) VALUES ('US', 'United States', 'united-states')");
$admin->query("INSERT INTO oc_t_currency (pk_c_code, s_name, s_description, b_enabled) VALUES ('USD', 'US Dollar', 'Dollar', 1)");

$seedRegions = array(
    array('US', 'Alpha', 'alpha', 1),
    array('US', 'Beta', 'beta', 1),
    array('US', 'Gamma', 'gamma', 0),
);
$regionIds = array();
foreach ($seedRegions as $r) {
    $stmt = $admin->prepare(
        'INSERT INTO oc_t_region (fk_c_country_code, s_name, s_slug, b_active) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('sssi', $r[0], $r[1], $r[2], $r[3]);
    $stmt->execute();
    $regionIds[] = $admin->insert_id;
    $stmt->close();
}
$id1 = $regionIds[0]; // the "Alpha" row, used for findByPrimaryKey/update pinning

// One t_item referencing USD, so deleting USD through the DAO trips FK 1451.
$admin->query(
    "INSERT INTO oc_t_item (fk_i_category_id, dt_pub_date, fk_c_currency_code, s_contact_email, s_ip) " .
    "VALUES (1, NOW(), 'USD', 'x@example.com', '127.0.0.1')"
);
$admin->query('SET FOREIGN_KEY_CHECKS = 1');

/** Live row count of a table, straight from the admin connection. */
function admin_count(mysqli $admin, string $table): int
{
    $res = $admin->query("SELECT COUNT(*) AS n FROM `$table`");
    $row = $res->fetch_assoc();

    return (int) $row['n'];
}

/* ----------------------------------------------------------------------------
 * The models under test share the DAO singleton mysqli handle.
 * ------------------------------------------------------------------------- */
$region   = Region::newInstance();
$currency = Currency::newInstance();
$alerts   = AlertsStats::newInstance();

echo "== 1. findByPrimaryKey ==\n";
// Base method via Region (no override).
$row = $region->findByPrimaryKey($id1);
check('findByPrimaryKey(existing) returns an assoc array', is_array($row) && $row !== array(), describe($row));
if (is_array($row)) {
    check('findByPrimaryKey(existing): every value is_string', all_values_string($row));
    pin('findByPrimaryKey(existing).pk_i_id (== seeded id, as string)', (string) $id1, $row['pk_i_id'] ?? null);
    pin('findByPrimaryKey(existing).fk_c_country_code', 'US', $row['fk_c_country_code'] ?? null);
    pin('findByPrimaryKey(existing).s_name', 'Alpha', $row['s_name'] ?? null);
    pin('findByPrimaryKey(existing).s_slug', 'alpha', $row['s_slug'] ?? null);
    pin('findByPrimaryKey(existing).b_active (TINYINT -> string)', '1', $row['b_active'] ?? null);
}
// Missing PK: the strict contract is === false, NOT null and NOT [].
$miss = $region->findByPrimaryKey(999999);
pin('findByPrimaryKey(missing) === false', false, $miss);
// NOTE: the numRows() !== 1 branch (returns false when the SELECT matches >1
// row) cannot be constructed here — a primary-key lookup is unique by
// definition — so it is documented rather than fixtured.

echo "\n== 1b. Currency::findByPrimaryKey override (cached; documents review mismatch) ==\n";
$cur = $currency->findByPrimaryKey('USD');
check('Currency::findByPrimaryKey(existing) returns assoc array', is_array($cur) && $cur !== array(), describe($cur));
if (is_array($cur)) {
    check('Currency::findByPrimaryKey(existing): every value is_string', all_values_string($cur));
    pin('Currency::findByPrimaryKey(existing).pk_c_code', 'USD', $cur['pk_c_code'] ?? null);
}
// The override caches on the model singleton; a second call must be identical.
check('Currency::findByPrimaryKey: cached second call is identical', $currency->findByPrimaryKey('USD') === $cur);
pin('Currency::findByPrimaryKey(missing) === false', false, $currency->findByPrimaryKey('QQQ'));

echo "\n== 2. existsByPrimaryKey / exists ==\n";
pin('existsByPrimaryKey(existing) === true', true, $region->existsByPrimaryKey($id1));
pin('existsByPrimaryKey(missing) === false', false, $region->existsByPrimaryKey(999999));
pin('exists([field=>val]) matching === true', true, $region->exists(array('s_slug' => 'alpha')));
pin('exists([field=>val]) non-matching === false', false, $region->exists(array('s_slug' => 'nope')));
pin('exists([not_a_field=>1]) === false', false, $region->exists(array('not_a_field' => 1)));

echo "\n== 3. insert ==\n";
$okInsert = $region->insert(array('fk_c_country_code' => 'US', 's_name' => 'Delta', 's_slug' => 'delta', 'b_active' => 1));
pin('insert(valid) === true', true, $okInsert);
$insId = $region->dao->insertedId();
check('after insert: dao->insertedId() is int > 0', is_int($insId) && $insId > 0, describe($insId));
pin("insert(['bad_key'=>1]) === false", false, $region->insert(array('bad_key' => 1)));

// Duplicate-PK insert pins the errno side-channel AlertsStats::increase() relies on.
pin('AlertsStats insert(new) === true', true, $alerts->insert(array('d_date' => '2026-01-01', 'i_num_alerts_sent' => '1')));
pin('AlertsStats insert(duplicate PK) === false', false, $alerts->insert(array('d_date' => '2026-01-01', 'i_num_alerts_sent' => '1')));
pin('AlertsStats getErrorLevel() === 1062 after dup insert', 1062, $alerts->getErrorLevel());

echo "\n== 4. update ==\n";
pin('update(newvalue, where) === 1 (rows changed)', 1, $region->update(array('s_name' => 'AlphaX'), array('pk_i_id' => $id1)));
pin('update(samevalue, where) === 0 (nothing changed)', 0, $region->update(array('s_name' => 'AlphaX'), array('pk_i_id' => $id1)));
pin("update('notarray', []) === false", false, $region->update('notarray', array()));
pin('update(bad-value-keys, where) === false', false, $region->update(array('bad' => 1), array('pk_i_id' => $id1)));
pin('update(values, bad-where-keys) === false', false, $region->update(array('s_name' => 'x'), array('bad' => 1)));
// INTENTIONAL PINNING: update(values, []) is a FULL-TABLE update. An empty $where
// passes checkFieldKeys() and produces UPDATE ... SET ... with NO WHERE clause,
// so it changes EVERY row. This is scary but real and load-bearing; pin it.
$before = admin_count($admin, 'oc_t_region'); // rows that will all get a fresh slug
$affectedAll = $region->update(array('s_slug' => 'ALLSET'), array());
pin('update(values, []) is a full-table update affecting ALL rows', $before, $affectedAll);

echo "\n== 5. delete ==\n";
// Single-row delete via a throwaway region inserted through the DAO.
$region->insert(array('fk_c_country_code' => 'US', 's_name' => 'ToDelete', 's_slug' => 'todelete', 'b_active' => 1));
$delId = $region->dao->insertedId();
pin('delete(where matching one row) === 1 (int affected)', 1, $region->delete(array('pk_i_id' => $delId)));
pin('delete([]) === false (empty where is refused, not a full-table wipe)', false, $region->delete(array()));
pin("delete(['bad'=>1]) === false", false, $region->delete(array('bad' => 1)));
// FK-violating delete: t_item still references USD.
$fkDelete = $currency->delete(array('pk_c_code' => 'USD'));
pin('FK-violating delete returns the legacy value (=== false)', false, $fkDelete);
pin('getErrorLevel() === 1451 after FK-violating delete', 1451, $currency->getErrorLevel());

echo "\n== 6. deleteByPrimaryKey / updateByPrimaryKey ==\n";
// Base deleteByPrimaryKey via Currency (Region overrides it with a cascade).
pin('Currency insert(ZZZ) === true', true, $currency->insert(array('pk_c_code' => 'ZZZ', 's_name' => 'Zeta', 's_description' => 'z', 'b_enabled' => 1)));
pin('deleteByPrimaryKey(existing) === 1 (int)', 1, $currency->deleteByPrimaryKey('ZZZ'));
pin('deleteByPrimaryKey(missing) === 0 (int, query ok / no rows)', 0, $currency->deleteByPrimaryKey('NOPE'));
// updateByPrimaryKey via Region base.
pin('updateByPrimaryKey(change) === 1', 1, $region->updateByPrimaryKey(array('s_name' => 'AlphaY'), $id1));
pin('updateByPrimaryKey(same) === 0', 0, $region->updateByPrimaryKey(array('s_name' => 'AlphaY'), $id1));
pin('updateByPrimaryKey(missing pk) === 0', 0, $region->updateByPrimaryKey(array('s_name' => 'AlphaY'), 999999));

echo "\n== 7. listAll (non-empty) ==\n";
$all = $region->listAll();
$expectN = admin_count($admin, 'oc_t_region');
check('listAll() is an array', is_array($all), describe($all));
pin('listAll() row count matches table', $expectN, is_array($all) ? count($all) : -1);
$rowsAllStrings = true;
foreach ((array) $all as $r) {
    if (!is_array($r) || !all_values_string($r)) {
        $rowsAllStrings = false;
        break;
    }
}
check('listAll(): every row is assoc with all-string values', $rowsAllStrings);

echo "\n== 8. count (non-empty) ==\n";
$cnt = $region->count();
check('count() returns a string', is_string($cnt), describe($cnt));
pin('count() === "N" (string) on a non-empty table', (string) $expectN, $cnt);

echo "\n== 9. empty-table shapes ==\n";
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
$admin->query('TRUNCATE TABLE oc_t_region');
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
pin('listAll() === array() on an emptied table', array(), $region->listAll());
pin('count() === "0" (string, NOT int 0) on an emptied table', '0', $region->count());

/* ----------------------------------------------------------------------------
 * Teardown.
 * ------------------------------------------------------------------------- */
$admin->query("DROP DATABASE IF EXISTS `$scratch`");
$admin->close();

echo "\n----------------------------------------\n";
echo "RESULT: {$GLOBALS['okCount']} passed, {$GLOBALS['failCount']} failed\n";
exit($GLOBALS['failCount'] === 0 ? 0 : 1);
