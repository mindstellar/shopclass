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
 * table clears the flag and queues a build (an unrelated table does neither), and the command
 * is listed with a summary.
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

$colour = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type) VALUES ('colour', 'colour', 'DROPDOWN')", '', []);
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
$rowsOf = static fn (int $id): int => (int) $admin->query("SELECT COUNT(*) FROM $facet WHERE fk_i_item_id = $id")->fetch_row()[0];
$jobs   = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "' AND s_status IN ('pending', 'running')")->fetch_row()[0];

harness_section('Foreground rebuild');
$setFlag('0');
$admin->query("DELETE FROM $facet");
$lines = [];
$last  = FacetJobs::reindex(0, static function (string $text) use (&$lines): void {
    $lines[] = $text;
});
pin('returns the last listing id', max($ids), $last);
pin('every listing has its row', [1, 1, 1, 1, 1], array_map($rowsOf, $ids));
pin('the ready flag is set', '1', $flag());
check('a progress line was printed', $lines !== []);

harness_section('--from');
$setFlag('0');
$admin->query("DELETE FROM $facet");
facet_put($admin, "f$colour", 'Wrong', $ids[0]);
facet_put($admin, "f$colour", 'Wrong', $ids[1]);
FacetJobs::reindex($ids[2] - 1, static function (string $text): void {
});
$wrong = (int) $admin->query('SELECT COUNT(*) FROM ' . facet_rows_sql() . " x WHERE s_value = 'Wrong' AND fk_i_item_id IN ({$ids[0]}, {$ids[1]})")->fetch_row()[0];
pin('listings before --from keep their rows', 2, $wrong);
pin('listings from --from are rebuilt', [1, 1, 1], array_map($rowsOf, array_slice($ids, 2)));

harness_section('afterRepair');
$setFlag('1');
FacetJobs::afterRepair(["CREATE TABLE IF NOT EXISTS `{$p}t_other` (id INT)"]);
pin('an unrelated table leaves the flag', '1', $flag());
pin('an unrelated table queues nothing', 0, $jobs());
FacetJobs::afterRepair(["CREATE TABLE IF NOT EXISTS `$facet` (id INT)"]);
pin('the index table clears the flag', '0', $flag());
pin('the index table queues one build', 1, $jobs());

harness_section('Command list');
$cli = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php');
check('search:reindex is in the command map with a summary', preg_match("/'search:reindex'\s*=>\s*\['cmdSearchReindex', '[^']+'\]/", $cli) === 1);
check('db:repair calls afterRepair', str_contains($cli, 'FacetJobs::afterRepair($result[\'ran\'])'));

exit(harness_result());
