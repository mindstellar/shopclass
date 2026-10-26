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
 * Distance search: migration 0072 gives each listing a search point (its own position, else
 * its city or region centre), a radius keeps the listings within it, "nearest first" sorts by
 * distance, a box keeps to a map area, and the search page only reads these params when the
 * site switched them on.
 *
 * Usage:  php tests/models/geo-search.php
 *         php tests/run-models.php geo-search
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\listing\ListingStore;
use mindstellar\search\AlertEnvelope;
use mindstellar\search\Distance;
use mindstellar\search\query\RadiusFilter;
use mindstellar\search\SearchBuilder;
use mindstellar\search\SearchCriteria;

$admin = scratchdb_session('osc_models_geo_search');
foreach (array('OSC_CACHE_TTL' => 60, 'WEB_PATH' => 'http://localhost/', 'OC_ADMIN' => false, 'DEMO' => true) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
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
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

$p    = DB_TABLE_PREFIX;
$conn = Connection::getInstance();

$setPref = static function (string $name, string $value) use ($admin, $p): void {
    $admin->query("DELETE FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '" . $admin->real_escape_string($name) . "'");
    seed_exec($admin, "INSERT INTO {$p}t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', ?, ?, 'STRING')", 'ss', array($name, $value));
    osc_reset_preferences();
    if (class_exists('mindstellar\\cache\\CacheManager')) {
        \mindstellar\cache\CacheManager::getInstance()->flush();
    }
};

$locale  = seed_locale($admin);
$country = seed_country($admin, 'IN', 'India');
$region  = seed_region($admin, $country, 'Maharashtra');
$bare    = seed_region($admin, $country, 'Nowhere');
$admin->query("UPDATE {$p}t_region SET d_coord_lat = 19.751480, d_coord_long = 75.713888 WHERE pk_i_id = $region");
$city = static function (string $name, ?float $lat, ?float $lng) use ($admin, $p, $region, $country): int {
    $id = seed_city($admin, $region, $name, $country);
    if ($lat !== null) {
        $admin->query("UPDATE {$p}t_city SET d_coord_lat = $lat, d_coord_long = $lng WHERE pk_i_id = $id");
    }

    return $id;
};
$mumbai = $city('Mumbai', 19.075833, 72.8775);
$thane  = $city('Thane', 19.218333, 72.978056);
$pune   = $city('Pune', 18.520430, 73.856743);
$blank  = $city('Blank', null, null);
seed_currency($admin);
$cat = seed_category($admin, 'Cars', null, $locale);

$item = static function (string $title, ?int $regionId, ?int $cityId, ?array $coords = null) use ($admin, $p, $cat, $country): int {
    $id = seed_item($admin, $cat, null, $title, 10.0, 1, 1, 'en_US', $country);
    $admin->query("UPDATE {$p}t_item_location SET fk_i_region_id = " . ($regionId ?? 'NULL') . ', fk_i_city_id = ' . ($cityId ?? 'NULL')
        . ($coords === null ? '' : ', d_coord_lat = ' . $coords[0] . ', d_coord_long = ' . $coords[1]) . " WHERE fk_i_item_id = $id");

    return $id;
};
$inMumbai = $item('In Mumbai', $region, $mumbai);
$inThane  = $item('In Thane', $region, $thane);
$inPune   = $item('In Pune', $region, $pune);
$exact    = $item('Own position near Mumbai', $region, $pune, array(19.08, 72.88));
$inRegion = $item('Region only', $region, null);
$noCentre = $item('City without a centre, region without one', $bare, $blank);
$nowhere  = $item('No place', null, null);

$points = static function () use ($admin, $p): array {
    $out = array();
    foreach ($admin->query("SELECT fk_i_item_id, e_geo_precision, d_geo_lat FROM {$p}t_item_location ORDER BY fk_i_item_id")->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[(int) $row['fk_i_item_id']] = array($row['e_geo_precision'], $row['d_geo_lat'] === null ? null : round((float) $row['d_geo_lat'], 2));
    }

    return $out;
};

harness_section('migration 0072 on a site from before it');

$admin->query("ALTER TABLE {$p}t_item_location DROP INDEX idx_geo, DROP COLUMN d_geo_lat, DROP COLUMN d_geo_long, DROP COLUMN e_geo_precision");
$admin->query("DELETE FROM {$p}t_preference WHERE s_name IN ('geo_search', 'distance_unit')");
$migrate = static fn () => (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0072_geo_search.php')->up($conn);
$migrate();
$expected = array(
    $inMumbai => array('city', 19.08),
    $inThane  => array('city', 19.22),
    $inPune   => array('city', 18.52),
    $exact    => array('exact', 19.08),
    $inRegion => array('region', 19.75),
    $noCentre => array(null, null),
    $nowhere  => array(null, null),
);
pin('each listing points at its own position, else its city, else its region', $expected, $points());
pin('both settings are added, off and in km', array('distance_unit' => 'km', 'geo_search' => '0'), array_column(
    $admin->query("SELECT s_name, s_value FROM {$p}t_preference WHERE s_name IN ('geo_search', 'distance_unit') ORDER BY s_name")->fetch_all(MYSQLI_ASSOC),
    's_value',
    's_name'
));
$migrate();
pin('running it again changes nothing', $expected, $points());

harness_section('saving a listing sets its point');

$admin->query("UPDATE {$p}t_item_location SET fk_i_city_id = $thane WHERE fk_i_item_id = $inPune");
ListingStore::stampPoint($inPune);
pin('a new city moves the point to its centre', array('city', 19.22), $points()[$inPune]);
$admin->query("UPDATE {$p}t_item_location SET d_coord_lat = 18.5, d_coord_long = 73.8 WHERE fk_i_item_id = $inPune");
ListingStore::stampPoint($inPune);
pin('its own position wins over the city', array('exact', 18.5), $points()[$inPune]);
$admin->query("UPDATE {$p}t_item_location SET fk_i_city_id = $pune, d_coord_lat = NULL, d_coord_long = NULL WHERE fk_i_item_id = $inPune");
ListingStore::stampPoint($inPune);
pin('the job pass covers every listing', 0, ListingStore::refreshPoints(PHP_INT_MAX - 1, 10));
pin('a batch returns the last listing done', $inThane, ListingStore::refreshPoints(0, 2));

harness_section('the search query');

$ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'pk_i_id'));
$s   = new Search();
$s->near(19.08, 72.88, 25);
$s->order('distance', 'asc');
$rows = $s->doSearch();
pin('within 25 km of Mumbai, nearest first', array($exact, $inMumbai, $inThane), $ids($rows));
pin('each row has its distance in km', array(0, 1, 19), array_map(static fn ($r) => (int) round((float) $r['distance']), $rows));
pin('the count agrees', 3, $s->count());

$s = new Search();
$s->near(19.08, 72.88, 1000);
$s->order('distance', 'desc');
$order = $ids($s->doSearch());
pin('nearest first is always nearest first, and leaves out listings with no point', array($exact, $inMumbai, $inThane, $inPune, $inRegion), $order);

$s = new Search();
$s->order('distance', 'asc');
pin('a distance sort with no point is the newest first', count($expected), count($s->doSearch()));

$a = new Search();
$a->addCity($mumbai);
$b = new Search();
$b->addCity($mumbai);
$b->near(19.08, 72.88, 25);
check('the result cache tells a radius search apart', $a->toJson() !== $b->toJson());

harness_section('the search page');

$crit  = static fn (array $params): SearchCriteria => SearchCriteria::fromRequest($params, array('pageSize' => 50));
$build = static function (array $params) use ($crit): Search {
    $search = new Search();
    SearchBuilder::apply($crit($params), $search);

    return $search;
};
$sorted = static function (array $list): array {
    sort($list);

    return $list;
};

$setPref('geo_search', '0');
$s = $build(array('sCity' => (string) $mumbai, 'iRadius' => '25'));
check('switched off, the radius is ignored and the city filter stays', $s->nearPoint() === null && $ids($s->doSearch()) === array($inMumbai));
$setPref('geo_search', '1');
$s = $build(array('sCity' => (string) $mumbai, 'iRadius' => '25'));
pin('switched on, the radius is measured from the city centre', array('lat' => 19.075833, 'lng' => 72.8775, 'km' => 25.0), $s->nearPoint());
pin('and replaces the city filter', $sorted(array($exact, $inMumbai, $inThane)), $sorted($ids($s->doSearch())));
pin('the search still names the city picked', array((string) $mumbai), $crit(array('sCity' => (string) $mumbai, 'iRadius' => '25'))->cities());
pin('a city by name, and a radius off the list snaps to the nearest choice', array(19.218333, 5.0), array($build(array('sCity' => 'Thane', 'iRadius' => '7'))->nearPoint()['lat'] ?? null, $build(array('sCity' => 'Thane', 'iRadius' => '7'))->nearPoint()['km'] ?? null));
check('a city by name is looked up in the country asked for', $build(array('sCity' => 'Thane', 'sCountry' => 'US', 'iRadius' => '10'))->nearPoint() === null);
pin('the visitor\'s point wins, rounded to two decimals', array(19.08, 72.88), array_values(array_slice((array) $build(array('sLat' => '19.0812', 'sLng' => '72.8799', 'iRadius' => '10', 'sCity' => (string) $pune))->nearPoint(), 0, 2)));
check('a point off the globe is ignored', $crit(array('sLat' => '95', 'sLng' => '72', 'iRadius' => '10'))->point() === null);
$s = $build(array('sCity' => (string) $mumbai, 'sOrder' => 'distance'));
pin('nearest first keeps the city filter, inside the largest radius', array(100.0, array($inMumbai)), array($s->nearPoint()['km'] ?? null, $ids($s->doSearch())));
check('nearest first with no point measures from nowhere', $build(array('sOrder' => 'distance'))->nearPoint() === null);
$setPref('distance_unit', 'mi');
pin('a radius in miles searches the same distance in km', 16.09, round((float) ($build(array('sCity' => (string) $mumbai, 'iRadius' => '10'))->nearPoint()['km'] ?? 0), 2));
$setPref('distance_unit', 'km');

$params = AlertEnvelope::validate(AlertEnvelope::build($crit(array('sCity' => (string) $mumbai, 'iRadius' => '25')), array()));
pin('a saved alert keeps the radius and the city it measures from', array(25, array($mumbai), null), array($params['iRadius'] ?? null, $params['sCity'] ?? null, $params['sLat'] ?? null));
pin('and replays the same search', 25.0, $build($params)->nearPoint()['km'] ?? null);
$params = AlertEnvelope::validate(AlertEnvelope::build($crit(array('sLat' => '19.0812', 'sLng' => '72.88', 'iRadius' => '25')), array()));
pin('around the visitor\'s point it keeps the point, to two decimals', array('19.08', '72.88', 25), array($params['sLat'] ?? null, $params['sLng'] ?? null, $params['iRadius'] ?? null));

harness_section('a map area');

$fiji  = $item('Suva, west of the date line', null, null, array(-18.14, 178.44));
$samoa = $item('Apia, east of the date line', null, null, array(-13.83, -171.77));
ListingStore::stampPoint($fiji);
ListingStore::stampPoint($samoa);
$inBox = static function (array $box) use ($ids, $sorted): array {
    $s = new Search();
    $s->inBox(...$box);

    return array($sorted($ids($s->doSearch())), $s->count());
};
pin('a box round Mumbai holds the listings there and leaves Pune out', array($sorted(array($exact, $inMumbai, $inThane)), 3), $inBox(array(72.7, 18.9, 73.1, 19.3)));
pin('a box with no listings in it', array(array(), 0), $inBox(array(10.0, 10.0, 11.0, 11.0)));
pin('a box across the date line holds both sides', array($sorted(array($fiji, $samoa)), 2), $inBox(array(170.0, -25.0, -170.0, -10.0)));
pin('and only what is between its edges', array(array($fiji), 1), $inBox(array(175.0, -25.0, -175.0, -10.0)));
pin('the same edges not crossing it hold neither', array(array(), 0), $inBox(array(-170.0, -25.0, 170.0, -10.0)));
$s = new Search();
$s->inBox(72.7, 18.9, 73.1, 19.3);
$s->near(19.08, 72.88, 5);
pin('a box and a radius both apply', $sorted(array($exact, $inMumbai)), $sorted($ids($s->doSearch())));
pin('the search backends see the box', array('west' => 72.7, 'south' => 18.9, 'east' => 73.1, 'north' => 19.3), \mindstellar\search\SearchFilters::from((new ReflectionProperty(Search::class, 'parts'))->getValue($s))->box);
$a = new Search();
$a->inBox(72.7, 18.9, 73.1, 19.3);
$b = new Search();
$b->inBox(72.7, 18.9, 73.2, 19.3);
check('the result cache tells two boxes apart', $a->toJson() !== $b->toJson());

$setPref('geo_search', '0');
pin('switched off, the search page ignores bbox', null, $build(array('bbox' => '72.7,18.9,73.1,19.3'))->box());
$setPref('geo_search', '1');
$s = $build(array('bbox' => '72.7,18.9,73.1,19.3'));
pin('switched on, the search page keeps to it', $sorted(array($exact, $inMumbai, $inThane)), $sorted($ids($s->doSearch())));
pin('an invalid bbox is ignored', null, $build(array('bbox' => '72.7,19.3,73.1,18.9'))->box());
pin('with a city, both apply', array($inMumbai), $ids($build(array('bbox' => '72.7,18.9,73.1,19.3', 'sCity' => (string) $mumbai))->doSearch()));
$params = AlertEnvelope::validate(AlertEnvelope::build($crit(array('bbox' => '170,-25,-170,-10')), array()));
pin('a saved alert keeps the box', '170,-25,-170,-10', $params['bbox'] ?? null);
pin('and replays it', $sorted(array($fiji, $samoa)), $sorted($ids($build((array) $params)->doSearch())));

$explain = $admin->query('EXPLAIN SELECT fk_i_item_id FROM ' . $p . 't_item_location WHERE d_geo_lat BETWEEN 18.9 AND 19.3 AND (d_geo_long BETWEEN 72.7 AND 73.1)')->fetch_assoc();
pin('the box reads the point index', 'idx_geo', $explain['key'] ?? null);

harness_section('the maths');

pin('a point needs finite numbers within ±90 and ±180', array(true, true, false, false, false, false, false), array(
    Distance::validPoint('-90', '180'), Distance::validPoint(0, 0), Distance::validPoint(90.01, 0), Distance::validPoint(0, -180.5),
    Distance::validPoint(INF, 0), Distance::validPoint(0, NAN), Distance::validPoint('1e999', '0'),
));
pin('the range is checked before rounding, so 90.004 is refused and the edges are kept', array(false, true), array(Distance::validPoint(90.004, 0), Distance::validPoint(-90.0, 180.0)));
pin('radius choices snap to the nearest band', array(5, 5, 25, 100, 0, 0), array_map(array(Distance::class, 'band'), array(1, 7, 30, 1000, 0, 'x')));
pin('across the date line the box is two ranges', 2, count(RadiusFilter::box(0.0, 179.9, 50)[1]));
pin('near a pole every longitude is in reach', array(array(-180.0, 180.0)), RadiusFilter::box(89.9, 10.0, 50)[1]);
[$lat, $lngs] = RadiusFilter::box(19.08, 72.88, 25);
check('the box holds a point 25 km east', 72.88 + 25 / (111.32 * cos(deg2rad(19.08))) <= $lngs[0][1] && $lat[1] - 19.08 > 0.22);

$setPref('geo_search', '0');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
