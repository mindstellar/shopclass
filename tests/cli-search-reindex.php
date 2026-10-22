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
 * The search:reindex command and the db:repair hook: the foreground rebuild fills the rows and
 * sets the ready flag, --from leaves earlier listings alone, a repair that creates the index
 * table clears the flag and queues a build (an unrelated table does neither), the rebuild ends
 * by rewriting the table (never inside a transaction), and the command is listed with a summary.
 *
 * Usage: php tests/cli-search-reindex.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/facet-rows.php';

use mindstellar\search\FacetIndex;
use mindstellar\search\FacetJobs;

$admin = api_admin_boot('osc_cli_search_reindex');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);

$colour = seed_meta_field($admin, 'colour', 'DROPDOWN');
$ids    = [];
for ($i = 0; $i < 5; $i++) {
    $ids[] = seed_item($admin, $cat, null, 'Mug ' . $i);
}
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) SELECT pk_i_id, $colour, 'Red', '' FROM {$p}t_item");

$facet = FacetIndex::tableName();
$flag  = static function () use ($admin, $p): ?string {
    $r = $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = 'facet_ready'")->fetch_row();

    return $r ? $r[0] : null;
};
$setFlag = static function (string $value) use ($admin, $p): void {
    $admin->query("DELETE FROM {$p}t_preference WHERE s_name IN ('facet_ready', 'facet_built_at')");
    $admin->query("INSERT INTO {$p}t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', 'facet_ready', '$value', 'BOOLEAN')");
    osc_reset_preferences();
};
// InnoDB gives a table a new id when it rewrites it.
$tableId = static function () use ($admin, $facet): int {
    $view = $admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME IN ('INNODB_TABLES', 'INNODB_SYS_TABLES')")->fetch_row()[0];

    return (int) $admin->query("SELECT TABLE_ID FROM information_schema.$view WHERE NAME = CONCAT(DATABASE(), '/$facet')")->fetch_row()[0];
};
$rowsOf = static fn (int $id): int => (int) $admin->query("SELECT COUNT(*) FROM $facet WHERE fk_i_item_id = $id")->fetch_row()[0];
$jobs   = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "' AND s_status IN ('pending', 'running')")->fetch_row()[0];

harness_section('Foreground rebuild');
$setFlag('0');
$admin->query("DELETE FROM $facet");
$lines  = [];
$before = $tableId();
$last   = FacetJobs::reindex(0, static function (string $text) use (&$lines): void {
    $lines[] = $text;
});
pin('returns the last listing id', max($ids), $last);
pin('every listing has its row', [1, 1, 1, 1, 1], array_map($rowsOf, $ids));
pin('the ready flag is set', '1', $flag());
check('a progress line was printed', $lines !== []);
check('the table was rewritten at the end', $tableId() !== $before);
pin('the last line says so', 'Index table compacted', end($lines));

harness_section('pack');
$before = $tableId();
\mindstellar\database\Db::beginTransaction();
pin('inside a transaction it does nothing', false, FacetJobs::pack());
check('and the transaction is still open', \mindstellar\database\Db::inTransaction());
\mindstellar\database\Db::rollBack();
pin('the table was not rewritten', $before, $tableId());
$session = static fn (): int => (int) \mindstellar\database\Db::scalar('SELECT @@SESSION.lock_wait_timeout');
\mindstellar\database\Db::execute('SET SESSION lock_wait_timeout = 77');
pin('outside one it rewrites the table', true, FacetJobs::pack());
check('with a new table id', $tableId() !== $before);
pin('the session lock wait is put back', 77, $session());
pin('the rows are all there', [1, 1, 1, 1, 1], array_map($rowsOf, $ids));

$failed = null;
\mindstellar\database\Db::execute('SET SESSION lock_wait_timeout = 77');
$admin->query("RENAME TABLE $facet TO {$facet}_away");
try {
    FacetJobs::pack();
} catch (\mindstellar\database\DbException $e) {
    $failed = $e;
}
$admin->query("RENAME TABLE {$facet}_away TO $facet");
check('a failed rewrite throws', $failed !== null);
check('with the server\'s reason', $failed !== null && str_contains($failed->getMessage(), "doesn't exist"));
pin('and still puts the session lock wait back', 77, $session());

// A FULLTEXT index cannot be rebuilt without a lock, so only the online options make the rewrite refuse.
$admin->query("ALTER TABLE $facet ADD COLUMN s_probe VARCHAR(10) NULL, ADD FULLTEXT INDEX idx_probe (s_probe)");
$refused = '';
try {
    FacetJobs::pack();
} catch (\mindstellar\database\DbException $e) {
    $refused = $e->getMessage();
}
$admin->query("ALTER TABLE $facet DROP INDEX idx_probe, DROP COLUMN s_probe");
check('a rewrite that cannot run online fails rather than locking the table', str_contains($refused, 'is not supported'));

harness_section('packFits');
pin('the command line packs any size', true, FacetJobs::packFits('cli', PHP_INT_MAX));
pin('a web request packs a small table', true, FacetJobs::packFits('fpm-fcgi', 128 * 1024 * 1024));
pin('but not a big one', false, FacetJobs::packFits('fpm-fcgi', 128 * 1024 * 1024 + 1));
check('the table size is read', FacetJobs::tableBytes() > 0);

harness_section('--from');
$setFlag('0');
$admin->query("DELETE FROM $facet");
facet_put($admin, "f$colour", 'Wrong', $ids[0]);
facet_put($admin, "f$colour", 'Wrong', $ids[1]);
$admin->query("INSERT INTO {$p}t_facet_term (s_key, s_value) VALUES ('f{$colour}', 'Orphan')");
FacetJobs::reindex($ids[2] - 1, static function (string $text): void {
});
$wrong = (int) $admin->query('SELECT COUNT(*) FROM ' . facet_rows_sql() . " x WHERE s_value = 'Wrong' AND fk_i_item_id IN ({$ids[0]}, {$ids[1]})")->fetch_row()[0];
pin('listings before --from keep their rows', 2, $wrong);
$termsLeft = array_column($admin->query("SELECT s_value FROM {$p}t_facet_term WHERE s_key = 'f{$colour}' ORDER BY s_value")->fetch_all(MYSQLI_ASSOC), 's_value');
pin('the rebuild prunes the term no row names and keeps the others', ['Red', 'Wrong'], $termsLeft);
pin('listings from --from are rebuilt', [1, 1, 1], array_map($rowsOf, array_slice($ids, 2)));

harness_section('afterRepair');
$setFlag('1');
FacetJobs::afterRepair(["CREATE TABLE IF NOT EXISTS `{$p}t_other` (id INT)"]);
pin('an unrelated table leaves the flag', '1', $flag());
pin('an unrelated table queues nothing', 0, $jobs());
FacetJobs::afterRepair(["CREATE TABLE IF NOT EXISTS `$facet` (id INT)"]);
pin('the index table clears the flag', '0', $flag());
pin('the index table queues one build', 1, $jobs());
$setFlag('1');
$admin->query("DELETE FROM {$p}t_job_queue");
FacetJobs::afterRepair(["CREATE TABLE IF NOT EXISTS `{$p}t_facet_term` (id INT)"]);
pin('the term table clears the flag', '0', $flag());
pin('the term table queues one build', 1, $jobs());

harness_section('Command list');
$cli = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php');
check('search:reindex is in the command map with a summary', preg_match("/'search:reindex'\s*=>\s*\['cmdSearchReindex', '[^']+'\]/", $cli) === 1);
check('db:repair runs the shared repair, which calls afterRepair', str_contains($cli, 'DatabaseTools::repair()') && str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/admin/DatabaseTools.php'), 'FacetJobs::afterRepair($result[\'ran\'])'));

exit(harness_result());
