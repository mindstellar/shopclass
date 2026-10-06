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
 * A pending migration runs on every path into and out of an edge build: the admin gate sees
 * the code version above the stored one, and the ledger applies what it lacks.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\migration\MigrationRunner;
use mindstellar\utility\Utils;

$admin = scratchdb_session('osc_models_edgeupgrade');
$pref  = DB_TABLE_PREFIX . 't_preference';
$conn  = Connection::getInstance();

$dir = sys_get_temp_dir() . '/osc-edge-migrations-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/9001_edge_probe.php', "<?php\n\nreturn new class {\n    public function up(\$conn): void\n    {\n"
    . "        \$conn->execute('UPDATE ' . DB_TABLE_PREFIX . \"t_preference SET s_value = s_value + 1 WHERE s_section = 'edge_probe' AND s_name = 'runs'\");\n"
    . "    }\n};\n");
$admin->query("DELETE FROM $pref WHERE s_section = 'edge_probe' OR (s_section = 'osclass' AND s_name = 'version')");
$admin->query("INSERT INTO $pref VALUES ('edge_probe', 'runs', '0', 'INTEGER'), ('osclass', 'version', '', 'STRING')");

$runs   = static fn () => (int) $admin->query("SELECT s_value FROM $pref WHERE s_section = 'edge_probe' AND s_name = 'runs'")->fetch_row()[0];
$runner = new MigrationRunner($conn, $dir);
$runner->ensureLedger();

foreach (array(
    'rc to edge'                 => array('6.4.0.rc6', '6.4.0.rc6.202610022159'),
    'edge to a later edge'       => array('6.4.0.rc6.202610022159', '6.4.0.rc6.202610030200'),
    'edge to the next rc'        => array('6.4.0.rc6.202610030200', '6.4.0.rc7'),
    'edge to its stable release' => array('6.4.0.rc6.202610030200', '6.4.0'),
    'stable to edge'             => array('6.4.0', '6.4.0.202610030200'),
) as $label => [$stored, $code]) {
    harness_section($label);
    $admin->query("DELETE FROM " . DB_TABLE_PREFIX . "t_migration WHERE s_migration = '9001_edge_probe.php'");
    $admin->query("UPDATE $pref SET s_value = '$stored' WHERE s_section = 'osclass' AND s_name = 'version'");
    $before = $runs();

    $stored = (string) $admin->query("SELECT s_value FROM $pref WHERE s_section = 'osclass' AND s_name = 'version'")->fetch_row()[0];
    pin('the admin is sent to the upgrade', true, Utils::versionCompare($code, $stored, 'gt'));
    pin('the migration is pending', array('9001_edge_probe.php'), $runner->pending());
    pin('the upgrade applies it', array('9001_edge_probe.php'), $runner->run()['applied']);
    pin('it ran once', $before + 1, $runs());
    pin('nothing is left', array(), $runner->pending());
}

$admin->query("DELETE FROM " . DB_TABLE_PREFIX . "t_migration WHERE s_migration = '9001_edge_probe.php'");
$admin->query("DELETE FROM $pref WHERE s_section = 'edge_probe'");
unlink($dir . '/9001_edge_probe.php');
@rmdir($dir);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
