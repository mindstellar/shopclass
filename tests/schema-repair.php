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
 * Schema repair is opt-in: upgradeDB() runs migrations only, and db:repair runs the reconciler.
 * Also pins how the reconciler handles a primary key.
 *
 * Needs a database (the scratch container by default). Usage:  php tests/schema-repair.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\database\SchemaReconciler;
use mindstellar\migration\MigrationRunner;

$scratch = 'osc_schema_repair_' . getmypid();
$admin   = scratchdb_session($scratch);

if (!function_exists('__')) {
    function __($s, $d = 'core')
    {
        return $s;
    }
}
if (!function_exists('osc_lib_path')) {
    function osc_lib_path()
    {
        return LIB_PATH;
    }
}

$conn = Connection::instance();

/** Whether $table has an index named $name. */
$hasIndex = static function (string $table, string $name) use ($admin): bool {
    $res = $admin->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $admin->real_escape_string($name) . "'");

    return $res !== false && $res->num_rows > 0;
};
/** Primary key columns of $table, in order. */
$primary = static function (string $table) use ($admin): array {
    $cols = array();
    $res  = $admin->query("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'");
    while ($res && ($row = $res->fetch_assoc())) {
        $cols[(int) $row['Seq_in_index']] = $row['Column_name'];
    }
    ksort($cols);

    return array_values($cols);
};
$reconcile = static function (string $sql) use ($conn): array {
    return (new SchemaReconciler($conn))->reconcile($sql);
};
$flat = static function (array $queries): string {
    return implode(' | ', array_map(static fn ($q) => preg_replace('/\s+/', ' ', trim((string) $q)), $queries));
};

harness_section('Reconciler: primary keys');

$admin->query('CREATE TABLE oc_t_pk_single (s_code CHAR(2) NOT NULL, s_name VARCHAR(20) NOT NULL) ENGINE=InnoDB');
$admin->query('CREATE TABLE oc_t_pk_pair (i_a INT NOT NULL, i_b INT NOT NULL) ENGINE=InnoDB');
$admin->query('CREATE TABLE oc_t_pk_moved (i_a INT NOT NULL, i_b INT NOT NULL, PRIMARY KEY (i_a)) ENGINE=InnoDB');

$struct = "CREATE TABLE oc_t_pk_single (\n    s_code CHAR(2) NOT NULL,\n    s_name VARCHAR(20) NOT NULL,\n\n"
    . "        PRIMARY KEY (s_code)\n) ENGINE=InnoDB;\n"
    . "CREATE TABLE oc_t_pk_pair (\n    i_a INT NOT NULL,\n    i_b INT NOT NULL,\n\n"
    . "        PRIMARY KEY (i_a, i_b)\n) ENGINE=InnoDB;\n"
    . "CREATE TABLE oc_t_pk_moved (\n    i_a INT NOT NULL,\n    i_b INT NOT NULL,\n\n"
    . "        PRIMARY KEY (i_a, i_b)\n) ENGINE=InnoDB;\n";

// Only the three test tables are declared, so nothing else in the schema is touched.
list($ok, $ran, $failed) = $reconcile($struct);
check('every statement succeeded', $ok && $failed === array(), $flat($failed));
check('no DROP PRIMARY KEY on a table that has none', strpos($flat($ran), 'oc_t_pk_pair DROP PRIMARY KEY') === false, $flat($ran));
pin('a single-column primary key is added', array('s_code'), $primary('oc_t_pk_single'));
pin('a composite primary key is added', array('i_a', 'i_b'), $primary('oc_t_pk_pair'));
pin('a primary key on other columns is replaced', array('i_a', 'i_b'), $primary('oc_t_pk_moved'));

list($ok, $ran) = $reconcile($struct);
pin('a second pass has nothing to do', '', $flat($ran));

// A type change on a column that already carries the key must not declare the key again.
$admin->query('ALTER TABLE oc_t_pk_single MODIFY s_code CHAR(3) NOT NULL');
list($ok, $ran, $failed) = $reconcile($struct);
check('changing the key column type succeeds', $ok && $failed === array(), $flat($failed));
check('...without re-declaring PRIMARY KEY', strpos($flat($ran), 'PRIMARY KEY') === false, $flat($ran));
pin('...and the key is still there', array('s_code'), $primary('oc_t_pk_single'));

foreach (array('oc_t_pk_single', 'oc_t_pk_pair', 'oc_t_pk_moved') as $t) {
    $admin->query("DROP TABLE `$t`");
}

harness_section('A fresh schema has nothing to repair');

$repaired = (new SchemaReconciler($conn))->repair();
pin('repair() on a fresh install runs nothing', array('ran' => array(), 'failed' => array()), $repaired);

harness_section('upgradeDB() runs migrations only');

// Mark every migration applied, as a fresh install does, so the upgrade has no migration to run.
$runner = new MigrationRunner($conn, LIB_PATH . 'osclass/installer/migrations');
$runner->ensureLedger();
$runner->baseline();

$admin->query('ALTER TABLE oc_t_country DROP INDEX idx_s_name');
check('fixture: the index is gone', !$hasIndex('oc_t_country', 'idx_s_name'));

$result = json_decode((string) \mindstellar\upgrade\Osclass::upgradeDB(), true);
pin('upgradeDB() succeeds', 0, (int) ($result['error'] ?? -1));
check('upgradeDB() leaves the dropped index missing', !$hasIndex('oc_t_country', 'idx_s_name'));
check('upgradeDB() no longer reports repairs', is_array($result) && !array_key_exists('repairs', $result));

harness_section('MigrationRunner: one run at a time');

$tmpDir = sys_get_temp_dir() . '/osc_runner_lock_' . getmypid();
@mkdir($tmpDir);
file_put_contents($tmpDir . '/0001_lock_probe.sql', 'CREATE TABLE /*TABLE_PREFIX*/t_lock_probe (i INT) ENGINE=InnoDB;');
$probeRunner = new MigrationRunner($conn, $tmpDir);
$probeRunner->ensureLedger();

// Another upgrade holds the lock on its own connection.
$admin->query("SELECT GET_LOCK('" . $admin->real_escape_string($probeRunner->lockName()) . "', 0)");
$busy = $probeRunner->run();
pin('run() is refused while another run holds the lock', array(false, true, array(), null), array($busy['ok'], $busy['busy'] ?? false, $busy['applied'], $busy['failed']));
check('...and applies nothing', !in_array('0001_lock_probe.sql', $probeRunner->applied(), true)
    && $admin->query("SHOW TABLES LIKE 'oc_t_lock_probe'")->num_rows === 0);
$result = json_decode((string) \mindstellar\upgrade\Osclass::upgradeDB(), true);
pin('upgradeDB() reports the other upgrade', array(3, 'Another upgrade is already running. Wait for it to finish, then try again.'), array((int) ($result['error'] ?? 0), $result['message'] ?? ''));

$admin->query("SELECT RELEASE_LOCK('" . $admin->real_escape_string($probeRunner->lockName()) . "')");
$done = $probeRunner->run();
pin('run() applies it once the lock is free', array(true, array('0001_lock_probe.sql')), array($done['ok'], $done['applied']));
pin('...and releases the lock afterwards', '1', (string) $admin->query("SELECT IS_FREE_LOCK('" . $admin->real_escape_string($probeRunner->lockName()) . "')")->fetch_row()[0]);
$admin->query('DROP TABLE oc_t_lock_probe');
$admin->query("DELETE FROM oc_t_migration WHERE s_migration = '0001_lock_probe.sql'");
unlink($tmpDir . '/0001_lock_probe.sql');
rmdir($tmpDir);

harness_section('db:repair');

pin('db:repair --dry-run exits 0', 0, \mindstellar\cli\Cli::run(array('db:repair', '--dry-run')));
check('...and changes nothing', !$hasIndex('oc_t_country', 'idx_s_name'));

pin('db:repair exits 0', 0, \mindstellar\cli\Cli::run(array('db:repair')));
check('db:repair restores the dropped index', $hasIndex('oc_t_country', 'idx_s_name'));

pin('db:doctor is clean afterwards', 0, \mindstellar\cli\Cli::run(array('db:doctor')));

harness_section('repair() and an index under another name');

$admin->query('ALTER TABLE oc_t_item DROP INDEX idx_expiration, ADD INDEX dt_expiration (dt_expiration)');
$admin->query('ALTER TABLE oc_t_alerts DROP INDEX idx_email, ADD INDEX s_email_prefix (s_email(10))');
$repaired = (new SchemaReconciler($conn))->repair();
check('a same-column index is not duplicated', !$hasIndex('oc_t_item', 'idx_expiration'), $flat($repaired['ran']));
check('a prefix index does not stand in for the full-column one', $hasIndex('oc_t_alerts', 'idx_email'), $flat($repaired['ran']));
pin('...which is all repair() ran', array('ALTER TABLE oc_t_alerts ADD INDEX idx_email (s_email)'), array_map(static fn ($q) => preg_replace('/\s+/', ' ', trim((string) $q)), $repaired['ran']));
$admin->query('ALTER TABLE oc_t_item DROP INDEX dt_expiration, ADD INDEX idx_expiration (dt_expiration)');
$admin->query('ALTER TABLE oc_t_alerts DROP INDEX s_email_prefix');

exit(harness_result());

/* file end: ./tests/schema-repair.php */
