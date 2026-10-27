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
 * Search and autocomplete find a place by its name in any language, and show it in the
 * visitor's language.
 *
 * Usage:  php tests/models/location-search.php
 *         php tests/run-models.php location-search
 */

require_once __DIR__ . '/../lib/harness.php';

// It stubs page helpers other model files load for real, so under the runner it runs alone.
if (defined('MODELS_RUNNER')) {
    $lsOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $lsOut, $lsCode);
    $lsOut = implode("\n", $lsOut);
    echo $lsOut, "\n";
    $lsFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $lsOut, $lsM) === 1;
    $lsFail  = $lsFound ? (int) $lsM[2] : 0;
    if (!$lsFound || ($lsCode !== 0 && $lsFail === 0)) {
        $lsFail = max(1, $lsFail);
    }
    $GLOBALS['okCount']   += $lsFound ? (int) $lsM[1] : 0;
    $GLOBALS['failCount'] += $lsFail;
    if ($lsFail > 0) {
        $GLOBALS['failLabels'][] = 'location-search: ' . $lsFail . ' failed (exit ' . $lsCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_location_search');

foreach (array(
    'OSC_CACHE_TTL' => 60,
    'WEB_PATH'      => 'http://localhost/',
    'REL_WEB_URL'   => '/',
    'PLUGINS_PATH'  => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'      => false,
    'OSC_DEBUG'     => false,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
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
if (!function_exists('osc_prime_item_upgrades')) {
    function osc_prime_item_upgrades(array $items)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocation.php';

use mindstellar\location\LocationAutocompleteQuery;
use mindstellar\location\LocationNames;
use mindstellar\location\LocationNameStore;
use mindstellar\search\query\LocationFilter;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchResult;

$p = DB_TABLE_PREFIX;

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');
$category = seed_category($admin, 'Things', null, 'en_US');
$country  = seed_country($admin, 'DE', 'Germany');
$region   = seed_region($admin, $country, 'Rhineland');
$cologne  = seed_city($admin, $region, 'Cologne', $country);
$bonn     = seed_city($admin, $region, 'Bonn', $country);

$listing = static function (int $city, string $cityName) use ($admin, $p, $category, $country, $region): int {
    $id = seed_item($admin, $category, null, 'In ' . $cityName, 10.0, 1, 1, 'en_US', $country);
    seed_exec(
        $admin,
        "UPDATE {$p}t_item_location SET s_country = 'Germany', fk_i_region_id = ?, s_region = 'Rhineland', fk_i_city_id = ?, s_city = ? WHERE fk_i_item_id = ?",
        'iisi',
        array($region, $city, $cityName, $id)
    );

    return $id;
};
$inCologne = $listing($cologne, 'Cologne');
$inBonn    = $listing($bonn, 'Bonn');

$store = new LocationNameStore();
$store->set(LocationNameStore::CITY, $cologne, 'de_DE', 'Köln');
$store->set(LocationNameStore::REGION, $region, 'de_DE', 'Rheinland');
$store->set(LocationNameStore::COUNTRY, $country, 'de_DE', 'Deutschland');

$found = static function (string $add, string $value): array {
    scratchdb_forget_cache();
    $s = new Search();
    $s->$add($value);

    return array_map('intval', array_column($s->doSearch(false, false), 'pk_i_id'));
};
$column = $p . 't_item_location.';

harness_section('Search by name');
pin('sCity=Köln finds the Cologne listing', array($inCologne), $found('addCity', 'Köln'));
pin('sCity=Cologne still finds it', array($inCologne), $found('addCity', 'Cologne'));
pin('sCity=Nowhere finds nothing', array(), $found('addCity', 'Nowhere'));
pin('sRegion=Rheinland finds both listings', array($inCologne, $inBonn), (static function () use ($found) {
    $ids = $found('addRegion', 'Rheinland');
    sort($ids);

    return $ids;
})());
pin('sCountry=Deutschland finds both listings', 2, count($found('addCountry', 'Deutschland')));

$filter = new LocationFilter();
$filter->addCity('Nowhere');
pin('a name with no translation records the same text as before', array($column . "s_city LIKE 'Nowhere' "), $filter->recorded('cities'));
$s = new Search();
$s->addCity('Nowhere');
pin('toJson() for it is unchanged', array($column . "s_city LIKE 'Nowhere' "), json_decode($s->toJson(), true)['cities']);
$filter->addCity('Köln');
pin(
    'a translated name records its ids, so the cache key follows the translations',
    '( ' . $column . "s_city LIKE 'Köln' OR " . $column . 'fk_i_city_id IN (' . $cologne . ') ) ',
    $filter->recorded('cities')[1]
);
pin('values() still gives the typed name', array('by' => 'name', 'value' => 'Köln'), $filter->values('cities')[1]);
$filter->addCountry('Deutschland');
pin('a translated country name records its code', '( ' . $column . "s_country LIKE 'Deutschland' OR " . $column . "fk_c_country_code IN ('de') ) ", $filter->recorded('countries')[0]);

pin('a LIKE wildcard does not match a name in another language', array(), $found('addCity', 'K%'));
$many = new LocationFilter();
$q    = harness_query_count(static function () use ($many): void {
    $many->addCity(array_map(static fn (int $i): string => 'Name ' . $i, range(1, 30)));
});
pin('only the first 10 typed names per level are looked up', 10, $q);

harness_section('Autocomplete');
harness_reset_container();
$complete = static fn (string $locale): LocationAutocompleteQuery => new LocationAutocompleteQuery($locale, $store, new LocationNames($store));
pin(
    "cities('Kö') returns Cologne in German",
    array(array('id' => (string) $cologne, 'label' => 'Köln', 'value' => 'Köln', 'region' => 'Rheinland')),
    $complete('de_DE')->cities('Kö')
);
pin("cities('Col') still returns it", array((string) $cologne), array_column($complete('de_DE')->cities('Col'), 'id'));
pin("cities('Kö') in English shows the stored names", array('Cologne', 'Rhineland'), array_values(array_intersect_key($complete('en_US')->cities('Kö')[0] ?? array(), array('label' => 1, 'region' => 1))));
pin("cities('Kö') narrowed by the region id", array((string) $cologne), array_column($complete('de_DE')->cities('Kö', $region), 'id'));
pin("cities('Kö') narrowed by the German region name", array((string) $cologne), array_column($complete('de_DE')->cities('Kö', 'Rheinland'), 'id'));
pin("cities('Kö') in another region finds nothing", array(), $complete('de_DE')->cities('Kö', $region + 1000));
pin('an empty term returns nothing', array(), $complete('de_DE')->cities(''));
pin("countries('Deu') returns Germany in German", array(array('id' => 'DE', 'label' => 'Deutschland', 'value' => 'Deutschland')), $complete('de_DE')->countries('Deu'));
pin("countries('Ger') still returns it", array('DE'), array_column($complete('de_DE')->countries('Ger'), 'id'));
pin("regions('Rheinl', 'DE') returns the region in German", array(array('id' => (string) $region, 'label' => 'Rheinland', 'value' => 'Rheinland')), $complete('de_DE')->regions('Rheinl', 'DE'));
pin("regions('Rhine') still returns it", array((string) $region), array_column($complete('de_DE')->regions('Rhine'), 'id'));
pin("regions('Rheinl', 'Deutschland') narrows by the German country name", array((string) $region), array_column($complete('de_DE')->regions('Rheinl', 'Deutschland'), 'id'));
pin('a typed percent sign is not a wildcard', array(), $complete('de_DE')->cities('%'));
// The fillers sort before Köln by name and by id ('09…' as text), so a cut-off at 100 ids would drop Cologne.
for ($i = 0; $i < 150; $i++) {
    $store->set(LocationNameStore::CITY, sprintf('09%05d', $i), 'de_DE', 'Köa ' . $i);
}
pin('more than 100 other matches do not push the region\'s city out', array((string) $cologne), array_column($complete('de_DE')->cities('Kö', $region), 'id'));
for ($i = 0; $i < 150; $i++) {
    $store->forget(LocationNameStore::CITY, sprintf('09%05d', $i));
}
$auto = $complete('de_DE');
pin("cities('Kö') runs at most 4 queries (match, list, names of cities and regions)", true, harness_query_count(static fn () => $auto->cities('Kö')) <= 4);
$auto = $complete('de_DE');
pin("countries('Deu') runs at most 3 queries", true, harness_query_count(static fn () => $auto->countries('Deu')) <= 3);

harness_section('Endpoints');
$_COOKIE['oc_userLocale'] = 'de_DE';
$ajax = static function (string $method, array $get): mixed {
    harness_reset_container();
    $controller = (new ReflectionClass(CWebAjax::class))->newInstanceWithoutConstructor();
    $call       = new ReflectionMethod(CWebAjax::class, $method);
    // Headers cannot be sent from the command line; AjaxResponse says so with a notice.
    set_error_handler(static fn (int $no): bool => $no === E_USER_NOTICE, E_USER_NOTICE);
    ob_start();
    try {
        Params::withRequest($get, static fn () => $call->invoke($controller));
    } finally {
        $out = (string) ob_get_clean();
        restore_error_handler();
    }

    return json_decode($out, true);
};
pin('the location box labels the city and region in German', array('Köln (Rheinland)'), array_column($ajax('location', array('term' => 'Kö')), 'label'));
pin('location_cities keeps the JSON keys', array('id', 'label', 'value', 'region'), array_keys($ajax('locationCities', array('term' => 'Kö', 'region' => (string) $region))[0] ?? array()));
pin('the cities select lists the German name, sorted', array('Bonn', 'Köln'), array_column($ajax('cities', array('regionId' => (string) $region)), 's_name'));

harness_section('Search page names');
$export = static function (array $request, string $locale): array {
    $_COOKIE['oc_userLocale'] = $locale;
    harness_reset_container();
    $controller          = (new ReflectionClass(CWebSearch::class))->newInstanceWithoutConstructor();
    $controller->mSearch = new Search();
    $call                = new ReflectionMethod(CWebSearch::class, 'exportResults');
    $call->invoke($controller, SearchCriteria::fromRequest($request), new SearchResult(array(), 0, $controller->mSearch));

    return array(View::newInstance()->_get('search_country'), View::newInstance()->_get('search_region'), View::newInstance()->_get('search_city'));
};
pin('search_city is German under de_DE', 'Köln', $export(array('sCity' => (string) $cologne), 'de_DE')[2]);
pin('search_city is the stored name under en_US', 'Cologne', $export(array('sCity' => (string) $cologne), 'en_US')[2]);
pin('search_region and search_country are German under de_DE', array('Deutschland', 'Rheinland'), array_slice($export(array('sCountry' => 'DE', 'sRegion' => (string) $region), 'de_DE'), 0, 2));
pin('a typed name is shown as typed', 'Kö', $export(array('sCity' => 'Kö'), 'de_DE')[2]);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
