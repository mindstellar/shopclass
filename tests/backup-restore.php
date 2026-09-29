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
 * memory headroom dumps and restores, and comes back with the same rows.
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
check('...in the one-INSERT format', strpos($body, "/* dumping data for table `$table` */\ninsert into `$table` values\n(1,") === 0, $body);

harness_section('Restore');

$admin->query("TRUNCATE TABLE `$table`");
$handle = fopen($file, 'rb');
$ran    = DatabaseTools::restore(Connection::instance(), $handle);
fclose($handle);
unlink($file);

pin('one statement ran', 1, $ran);
pin('every row came back', $rows, (int) $admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]);
pin('...with the same contents', $before, $checksum());
pin('foreign key checks are on again', '1', (string) Connection::instance()->scalar('SELECT @@FOREIGN_KEY_CHECKS'));

exit(harness_result());

/* file end: ./tests/backup-restore.php */
