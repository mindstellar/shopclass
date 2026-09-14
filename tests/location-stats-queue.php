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
 * The location recount queue (t_locations_tmp) against deletes that land mid-recount.
 *
 * A place deleted after it was queued has no row for its stats to point at, so every
 * batch failed its foreign key and logged it. For regions and cities the whole batch
 * is one upsert, so one deleted id also lost the counts of every live id beside it.
 *
 * DB-backed. Usage:  php tests/location-stats-queue.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\Utils;

$admin  = scratchdb_session('osc_location_stats_queue');
$prefix = DB_TABLE_PREFIX;

seed_locale($admin);
seed_country($admin, 'AA', 'Alphaland');
seed_country($admin, 'BB', 'Betaland');
seed_country($admin, 'CC', 'Gammaland');
$keepRegion = seed_region($admin, 'AA', 'Kept Region');
$dropRegion = seed_region($admin, 'AA', 'Dropped Region');
$keepCity   = seed_city($admin, $keepRegion, 'Kept City', 'AA');
$dropCity   = seed_city($admin, $keepRegion, 'Dropped City', 'AA');
seed_city($admin, $keepRegion, 'Other City', 'AA');

$scalar = static function (string $sql) use ($admin): string {
    return (string) $admin->query($sql)->fetch_row()[0];
};

harness_section('a recount is queued');
pin('every country, region and city is queued', '8', (string) Utils::updateLocationStats(true));

harness_section('places deleted while it waits');
$admin->query("DELETE FROM {$prefix}t_country WHERE pk_c_code = 'CC'");
$admin->query("DELETE FROM {$prefix}t_city WHERE pk_i_id = $dropCity");
$admin->query("DELETE FROM {$prefix}t_region WHERE pk_i_id = $dropRegion");
pin('the deletes went through', '0', $scalar("SELECT COUNT(*) FROM {$prefix}t_country WHERE pk_c_code = 'CC'"));

$log = tempnam(sys_get_temp_dir(), 'locstats_');
ini_set('error_log', $log);
$notices = array();
set_error_handler(static function (int $no, string $msg) use (&$notices): bool {
    $notices[] = $msg;

    return true;
});

$left = -1;
for ($batch = 0; $batch < 10 && $left !== 0; $batch++) {
    $left = (int) Utils::updateLocationStats(false, 3);
}
restore_error_handler();
$logged = (string) file_get_contents($log);
@unlink($log);

pin('the queue drains', 0, $left);
pin('the queue table is empty', '0', $scalar("SELECT COUNT(*) FROM {$prefix}t_locations_tmp"));
pin('no database error is logged', '', $logged);
pin('no PHP notice is raised', array(), $notices);

harness_section('the live places beside them are still counted');
pin('countries', 'AA,BB', $scalar("SELECT GROUP_CONCAT(fk_c_country_code ORDER BY 1) FROM {$prefix}t_country_stats"));
pin('regions', (string) $keepRegion, $scalar("SELECT GROUP_CONCAT(fk_i_region_id) FROM {$prefix}t_region_stats"));
pin('cities', '2', $scalar("SELECT COUNT(*) FROM {$prefix}t_city_stats"));
pin('the kept city among them', '1', $scalar("SELECT COUNT(*) FROM {$prefix}t_city_stats WHERE fk_i_city_id = $keepCity"));

harness_section('a batch whose write fails is not dequeued');
// Re-queue every surviving place, then take the region/city stats tables away
// so their writes throw. Country stats stays up, so it should still drain.
Utils::updateLocationStats(true);
$admin->query("RENAME TABLE {$prefix}t_region_stats TO {$prefix}t_region_stats_away");
$admin->query("RENAME TABLE {$prefix}t_city_stats TO {$prefix}t_city_stats_away");

Utils::updateLocationStats(false, 10);

pin('country ids drain despite the other tables being gone', '0', $scalar(
    "SELECT COUNT(*) FROM {$prefix}t_locations_tmp WHERE e_type = 'COUNTRY'"
));
pin('the region id stays queued', '1', $scalar(
    "SELECT COUNT(*) FROM {$prefix}t_locations_tmp WHERE e_type = 'REGION' AND id_location = $keepRegion"
));
pin('both city ids stay queued', '2', $scalar(
    "SELECT COUNT(*) FROM {$prefix}t_locations_tmp WHERE e_type = 'CITY'"
));

$admin->query("RENAME TABLE {$prefix}t_region_stats_away TO {$prefix}t_region_stats");
$admin->query("RENAME TABLE {$prefix}t_city_stats_away TO {$prefix}t_city_stats");

harness_section('the queue drains once the write can succeed again');
$left = -1;
for ($batch = 0; $batch < 10 && $left !== 0; $batch++) {
    $left = (int) Utils::updateLocationStats(false, 10);
}
pin('the queue drains', 0, $left);
pin('the queue table is empty', '0', $scalar("SELECT COUNT(*) FROM {$prefix}t_locations_tmp"));

exit(harness_result());
