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
 * A backup is written and restored in streams: a table several times larger than the
 * memory headroom dumps in INSERTs of about 1 MB, restores, and comes back with the same
 * rows. Also: an old one-INSERT backup still restores, foreign keys do not block a restore,
 * a dump with the prefix token restores onto another prefix, a restore replaces tables,
 * Restore and Repair wait for a running upgrade, and a failed statement is logged short.
 *
 * Needs a database (the scratch container by default). Usage:  php tests/backup-restore.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\admin\DatabaseTools;
use mindstellar\database\Connection;

$scratch = 'osc_backup_restore_' . getmypid();
$admin   = scratchdb_session($scratch);
$table   = 'oc_t_backup_probe';
$rows    = 4000;

// Values a naive splitter gets wrong: semicolons, quotes, comment markers, line breaks.
$admin->query("CREATE TABLE `$table` (pk_i_id INT NOT NULL PRIMARY KEY, s_text TEXT NOT NULL, d_when DATE NULL) ENGINE=InnoDB");
$filler = str_repeat('x', 3000);
for ($start = 1; $start <= $rows; $start += 500) {
    $values = array();
    for ($id = $start; $id < $start + 500; $id++) {
        $text     = $admin->real_escape_string("row $id; it's -- /* not a comment */ # still text\nnext line; " . $filler);
        $values[] = "($id, '$text', " . ($id % 3 === 0 ? 'NULL' : "'2026-01-01'") . ')';
    }
    $admin->query("INSERT INTO `$table` VALUES " . implode(',', $values));
}
$checksum = static function () use ($admin, $table): string {
    return (string) $admin->query("CHECKSUM TABLE `$table`")->fetch_assoc()['Checksum'];
};
$before = $checksum();
check('fixture: the table holds about 12 MB', (int) $admin->query("SELECT SUM(LENGTH(s_text)) FROM `$table`")->fetch_row()[0] > 12000000);

// The headroom is well under the table's size: a dump that held the table would fail here.
ini_set('memory_limit', (string) (memory_get_usage(true) + 40 * 1048576));

harness_section('Dump');

$file = tempnam(sys_get_temp_dir(), 'osc_backup_');
file_put_contents($file, '');
$base = memory_get_usage();
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
pin('table_data() finishes', true, Dump::newInstance()->table_data($file, $table));
if (function_exists('memory_reset_peak_usage')) {
    $growth = memory_get_peak_usage() - $base;
    check('...holding a few MB at most, not the table', $growth < 4 * 1048576, round($growth / 1048576, 1) . ' MB');
}
$body = (string) file_get_contents($file, false, null, 0, 200);
check('...starting with the table comment and an INSERT', strpos($body, "/* dumping data for table `$table` */\ninsert into `$table` values\n(1,") === 0, $body);
$dump    = (string) file_get_contents($file);
$inserts = substr_count($dump, "insert into `$table` values\n");
check('...split into INSERTs of about 1 MB', $inserts >= 12 && $inserts <= 16, $inserts . ' INSERTs');
$largest = max(array_map('strlen', explode("insert into `$table` values\n", $dump)));
check('...none much over 1 MB', $largest < 1048576 + 8192, $largest . ' bytes');
unset($dump);

// One statement per table, as backups made before the split were written.
$old     = tempnam(sys_get_temp_dir(), 'osc_backup_old_');
$oldBody = str_replace(";\ninsert into `$table` values\n", ",\n", (string) file_get_contents($file));
file_put_contents($old, $oldBody);
check('fixture: the old-format file has one INSERT', substr_count($oldBody, 'insert into') === 1);
unset($oldBody);

$restore = static function (string $path) use ($admin, $table): array {
    $admin->query("TRUNCATE TABLE `$table`");
    $handle = fopen($path, 'rb');
    $base   = memory_get_usage();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $ran  = DatabaseTools::restore(Connection::instance(), $handle);
    $peak = function_exists('memory_reset_peak_usage') ? memory_get_peak_usage() - $base : -1;
    fclose($handle);

    return array($ran, $peak);
};

harness_section('Restore');

list($ran, $peak) = $restore($file);
pin('one statement ran per INSERT', $inserts, $ran);
pin('every row came back', $rows, (int) $admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]);
pin('...with the same contents', $before, $checksum());
pin('foreign key checks are on again', '1', (string) Connection::instance()->scalar('SELECT @@FOREIGN_KEY_CHECKS'));

list($oldRan, $oldPeak) = $restore($old);
pin('an old one-INSERT backup still restores', 1, $oldRan);
pin('...with the same contents', $before, $checksum());
if ($peak >= 0) {
    printf("        restore peak: %.1f MB split, %.1f MB one INSERT\n", $peak / 1048576, $oldPeak / 1048576);
    check('the split restore holds a few MB at most', $peak < 4 * 1048576, round($peak / 1048576, 1) . ' MB');
    check('...well under the one-INSERT restore', $peak * 4 < $oldPeak);
}
unlink($file);
unlink($old);

harness_section('Foreign keys');

$parent = 'oc_t_backup_parent';
$child  = 'oc_t_backup_child';
$admin->query("CREATE TABLE `$parent` (pk_i_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$admin->query("CREATE TABLE `$child` (pk_i_id INT NOT NULL PRIMARY KEY, fk_i_parent_id INT NOT NULL,"
    . " CONSTRAINT fk_backup_child FOREIGN KEY (fk_i_parent_id) REFERENCES `$parent` (pk_i_id)) ENGINE=InnoDB");
$admin->query("INSERT INTO `$parent` VALUES (1), (2)");
$admin->query("INSERT INTO `$child` VALUES (10, 1), (11, 2)");

// The child table comes first, so its CREATE and rows point at a table not made yet.
$fk = tempnam(sys_get_temp_dir(), 'osc_backup_fk_');
file_put_contents($fk, '');
foreach (array($child, $parent) as $t) {
    Dump::newInstance()->table_structure($fk, $t);
    Dump::newInstance()->table_data($fk, $t);
}
$admin->query("DROP TABLE `$child`");
$admin->query("DROP TABLE `$parent`");

$handle = fopen($fk, 'rb');
try {
    DatabaseTools::restore(Connection::instance(), $handle);
    $restored = true;
} catch (\mindstellar\database\DbException $e) {
    $restored = false;
}
fclose($handle);
unlink($fk);
pin('a child table dumped before its parent restores', true, $restored);
try {
    $count = (int) $admin->query("SELECT COUNT(*) FROM `$child`")->fetch_row()[0];
} catch (Throwable $e) {
    $count = -1;
}
pin('...with its rows', 2, $count);
pin('...and its foreign key', 1, (int) $admin->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS"
    . " WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_backup_child'")->fetch_row()[0]);
pin('foreign key checks are on again', '1', (string) Connection::instance()->scalar('SELECT @@FOREIGN_KEY_CHECKS'));

harness_section('Prefix token');

$tok = tempnam(sys_get_temp_dir(), 'osc_backup_tok_');
file_put_contents($tok, '');
Dump::newInstance()->table_structure($tok, $table, true);
Dump::newInstance()->table_data($tok, $table, true);
$head = (string) file_get_contents($tok, false, null, 0, 4096);
check('a backup dump names the table by the prefix token', strpos($head, 'CREATE TABLE IF NOT EXISTS `/*TABLE_PREFIX*/t_backup_probe`') !== false
    && strpos($head, "insert into `/*TABLE_PREFIX*/t_backup_probe` values\n") !== false, $head);

// A site with another prefix restores it, in its own process: the prefix is a constant.
$child = tempnam(sys_get_temp_dir(), 'osc_backup_child_') . '.php';
file_put_contents($child, '<?php
define("DB_TABLE_PREFIX", "sc_");
require ' . var_export(__DIR__ . '/lib/scratchdb.php', true) . ';
scratchdb_bootstrap($argv[1]);
$handle = fopen($argv[2], "rb");
echo \mindstellar\admin\DatabaseTools::restore(\mindstellar\database\Connection::instance(), $handle, null, true);
');
$ran = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' ' . escapeshellarg($scratch) . ' ' . escapeshellarg($tok) . ' 2>&1'));
unlink($child);
check('it restores onto another prefix', ctype_digit($ran) && (int) $ran > 1, $ran);
try {
    $moved = (int) $admin->query('SELECT COUNT(*) FROM `sc_t_backup_probe`')->fetch_row()[0];
    $same  = (string) $admin->query('CHECKSUM TABLE `sc_t_backup_probe`')->fetch_assoc()['Checksum'] === $before;
} catch (Throwable $e) {
    $moved = -1;
    $same  = false;
}
pin('...as sc_t_backup_probe with every row', $rows, $moved);
pin('...and the same contents', true, $same);

$handle = fopen($tok, 'rb');
try {
    DatabaseTools::restore(Connection::instance(), $handle, null, true);
    $replaced = true;
} catch (\mindstellar\database\DbException $e) {
    $replaced = false;
}
fclose($handle);
pin('restoring over a table that has its rows replaces it', true, $replaced);
pin('...without doubling a row', $rows, (int) $admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]);
pin('only a table with this site\'s prefix is dropped', null, DatabaseTools::createdTable('CREATE TABLE `wp_users` (id int)'));
unlink($tok);

harness_section('Another install in the same database');

// The installer allows a prefix that starts with another one, so oc_ and oc_shop2_ can share a database.
$admin->query('CREATE TABLE `oc_shop2_t_x` (pk_i_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$admin->query('INSERT INTO `oc_shop2_t_x` VALUES (1), (2), (3)');
$ours = \mindstellar\backup\DatabaseDump::tables();
check('fixture: this site\'s own table is backed up', in_array($table, $ours, true));
check('a backup leaves the other install\'s table out', !in_array('oc_shop2_t_x', $ours, true), implode(', ', $ours));
$sized = DatabaseTools::size(Connection::instance(), 'oc_');
pin('...and does not count it in this site\'s size', count($ours), $sized['tables']);

$foreign = tempnam(sys_get_temp_dir(), 'osc_backup_foreign_');
file_put_contents($foreign, "CREATE TABLE IF NOT EXISTS `oc_shop2_t_x` (pk_i_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n");
$handle = fopen($foreign, 'rb');
DatabaseTools::restore(Connection::instance(), $handle, null, true);
fclose($handle);
unlink($foreign);
pin('a restore or rollback never drops it', 3, (int) $admin->query('SELECT COUNT(*) FROM `oc_shop2_t_x`')->fetch_row()[0]);
$other = tempnam(sys_get_temp_dir(), 'osc_backup_other_');
file_put_contents($other, '');
Dump::newInstance()->table_structure($other, 'oc_shop2_t_x', true);
Dump::newInstance()->table_data($other, 'oc_shop2_t_x', true);
check('...and the prefix token never takes its name', strpos((string) file_get_contents($other), '/*TABLE_PREFIX*/') === false, (string) file_get_contents($other));
unlink($other);

harness_section('Upgrade lock');

$conn = Connection::instance();
$lock = (new \mindstellar\migration\MigrationRunner($conn, ABS_PATH . 'oc-includes/osclass/installer/migrations'))->lockName();
$admin->query("SELECT GET_LOCK('" . $admin->real_escape_string($lock) . "', 0)");
pin('refused while an upgrade holds the lock', null, DatabaseTools::upgradeLock($conn));
$admin->query("SELECT RELEASE_LOCK('" . $admin->real_escape_string($lock) . "')");

$release = DatabaseTools::upgradeLock($conn);
check('taken when free', $release instanceof Closure);
pin('...and held by this session', 1, (int) $conn->scalar('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', array($lock)));
$inner = DatabaseTools::upgradeLock($conn);
check('taken again inside a run that holds it', $inner instanceof Closure);
$inner();
pin('...and letting go of that keeps the outer hold', 1, (int) $conn->scalar('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', array($lock)));
$release();
pin('released afterwards', 1, (int) $conn->scalar('SELECT IS_FREE_LOCK(?)', array($lock)));

harness_section('Error log');

$log = tempnam(sys_get_temp_dir(), 'osc_errlog_');
$was = ini_set('error_log', $log);
$secret = str_repeat('hash$2y$10$abc', 100);
$failing = "INSERT INTO `no_such_table` VALUES ('" . $secret . "')";
try {
    $conn->execute($failing);
} catch (\mindstellar\database\DbException $e) {
}
ini_set('error_log', (string) $was);
$logged = (string) file_get_contents($log);
unlink($log);
check('a failed statement is logged', strpos($logged, 'Db query failed: INSERT INTO `no_such_table`') !== false, $logged);
check('...cut to about 300 characters, with its size', strpos($logged, $secret) === false && strpos($logged, '… (' . strlen($failing) . ' bytes)') !== false, substr($logged, 0, 500));

exit(harness_result());

/* file end: ./tests/backup-restore.php */
