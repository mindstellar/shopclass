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
 * The listing counts of countries, regions and cities are recounted on the job queue:
 * queued once, counted in batches, and the old t_locations_tmp table is gone.
 *
 * Usage:  php tests/models/location-recount.php        (standalone, own scratch database)
 *         php tests/run-models.php location-recount    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\job\JobQueue;
use mindstellar\job\JobWorker;
use mindstellar\location\LocationRecountJobs;

$admin = scratchdb_session('osc_models_location_recount');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDatabase.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

Preference::newInstance();

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
$cat    = seed_category($admin, 'Cars', null, $locale);
seed_country($admin, 'US', 'United States');
$region = seed_region($admin, 'US', 'Alpha');
$city   = seed_city($admin, $region, 'Springfield');
$other  = seed_city($admin, $region, 'Shelbyville');
foreach (array(1, 2) as $n) {
    $item = seed_item($admin, $cat, null, 'Car ' . $n);
    $admin->query("UPDATE {$p}t_item_location SET fk_i_region_id = $region, fk_i_city_id = $city WHERE fk_i_item_id = $item");
}
// Counts that drifted: the recount must correct all three.
$admin->query("REPLACE INTO {$p}t_country_stats VALUES ('US', 9)");
$admin->query("REPLACE INTO {$p}t_region_stats VALUES ($region, 9)");
$admin->query("REPLACE INTO {$p}t_city_stats VALUES ($city, 9), ($other, 9)");
$num = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

harness_section('the old staging table');
pin('t_locations_tmp is gone', 0, $num("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$p}t_locations_tmp'"));

harness_section('queue and count');
pin('nothing is pending before a recount is queued', 0, LocationRecountJobs::pending());
pin('a recount queues every location: 1 country, 1 region, 2 cities', 4, osc_update_location_stats(true));
pin('one job per level', 3, JobQueue::getInstance()->stats(LocationRecountJobs::TYPE)['pending']);
pin('asking again while it is queued adds nothing', 4, osc_update_location_stats(true));
pin('still three jobs', 3, JobQueue::getInstance()->stats(LocationRecountJobs::TYPE)['pending']);
pin('the total is kept for the progress bar', '4', (string) osc_get_preference('location_todo'));

JobWorker::run(30);
pin('the queue is empty after the worker runs', 0, LocationRecountJobs::pending());
pin('the country count is corrected', 2, $num("SELECT i_num_items FROM {$p}t_country_stats WHERE fk_c_country_code = 'US'"));
pin('the region count is corrected', 2, $num("SELECT i_num_items FROM {$p}t_region_stats WHERE fk_i_region_id = $region"));
pin('the city with listings counts them', 2, $num("SELECT i_num_items FROM {$p}t_city_stats WHERE fk_i_city_id = $city"));
pin('the city without listings counts none', 0, $num("SELECT i_num_items FROM {$p}t_city_stats WHERE fk_i_city_id = $other"));

harness_section('the admin button');
osc_update_location_stats(true);
pin('a call without force runs the queued jobs and reports what is left', 0, osc_update_location_stats());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
