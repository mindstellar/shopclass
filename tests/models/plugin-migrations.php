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
 * A plugin's migrations share the ledger with core's under a `<folder>/` prefix: a file with
 * the same name as a core one still runs, and forget() clears only that plugin's rows.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\migration\MigrationRunner;

$admin  = scratchdb_session('osc_models_plugin_migrations');
$pref   = DB_TABLE_PREFIX . 't_preference';
$ledger = DB_TABLE_PREFIX . 't_migration';
$conn   = Connection::getInstance();

$dir = sys_get_temp_dir() . '/osc-plugin-migrations-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/0001_create.php', "<?php\n\nreturn new class {\n    public function up(\$conn): void\n    {\n"
    . "        \$conn->execute('UPDATE ' . DB_TABLE_PREFIX . \"t_preference SET s_value = s_value + 1 WHERE s_section = 'plugin_probe' AND s_name = 'runs'\");\n"
    . "    }\n};\n");
$admin->query("DELETE FROM $pref WHERE s_section = 'plugin_probe'");
$admin->query("INSERT INTO $pref VALUES ('plugin_probe', 'runs', '0', 'INTEGER')");
$runs = static fn () => (int) $admin->query("SELECT s_value FROM $pref WHERE s_section = 'plugin_probe' AND s_name = 'runs'")->fetch_row()[0];

$core = new MigrationRunner($conn, $dir);
$core->ensureLedger();
$admin->query("DELETE FROM $ledger WHERE s_migration IN ('0001_create.php', 'demo/0001_create.php', 'dem_/0001_create.php')");
$admin->query("INSERT INTO $ledger (s_migration, dt_applied) VALUES ('0001_create.php', NOW())");

harness_section('a plugin runner');
$plugin = new MigrationRunner($conn, $dir, 'demo');
pin('a core file of the same name does not hide it', array('0001_create.php'), $plugin->pending());
pin('it runs', array('0001_create.php'), $plugin->run()['applied']);
pin('once', 1, $runs());
pin('the ledger holds it under the folder', 1, (int) $admin->query("SELECT COUNT(*) FROM $ledger WHERE s_migration = 'demo/0001_create.php'")->fetch_row()[0]);
pin('it reads back without the folder', array('0001_create.php'), $plugin->applied());
pin('nothing is left', array(), $plugin->pending());

harness_section('another plugin');
$other = new MigrationRunner($conn, $dir, 'dem_');
pin('an underscore in its folder does not match the first plugin\'s rows', array('0001_create.php'), $other->pending());
$other->run();

harness_section('forget');
$plugin->forget();
pin('the plugin is pending again', array('0001_create.php'), $plugin->pending());
pin('the other plugin keeps its row', array('0001_create.php'), $other->applied());
pin('core keeps its row', true, in_array('0001_create.php', $core->applied(), true));
$threw = false;
try {
    $core->forget();
} catch (LogicException $e) {
    $threw = true;
}
pin('core cannot forget', true, $threw);

harness_section('folder names');
$threw = false;
try {
    new MigrationRunner($conn, $dir, '../core');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
pin('a path is refused', true, $threw);

$admin->query("DELETE FROM $ledger WHERE s_migration IN ('0001_create.php', 'demo/0001_create.php', 'dem_/0001_create.php')");
$admin->query("DELETE FROM $pref WHERE s_section = 'plugin_probe'");
unlink($dir . '/0001_create.php');
@rmdir($dir);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
