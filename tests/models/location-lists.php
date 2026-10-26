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
 * Place lists (osc_get_* and osc_has_*) show the current locale's names.
 *
 * Usage:  php tests/models/location-lists.php
 *         php tests/run-models.php location-lists
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\container\Container;
use mindstellar\location\LocationNames;
use mindstellar\location\LocationNameStore;

$admin = scratchdb_session('osc_models_location_lists');

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');
$country = seed_country($admin, 'US', 'United States');
$alpine  = seed_region($admin, $country, 'Alpine');
$zurich  = seed_region($admin, $country, 'Zurich');
$cities  = [];
for ($i = 1; $i <= 20; $i++) {
    $cities[$i] = seed_city($admin, $alpine, sprintf('City %02d', $i), $country);
}

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
$GLOBALS['__lists_locale'] = 'en_US';
if (!function_exists('osc_current_user_locale')) {
    function osc_current_user_locale()
    {
        return $GLOBALS['__lists_locale'];
    }
}
foreach (['osc_base_url' => 'http://lists.test/', 'osc_cache_get' => false, 'osc_cache_set' => true] as $stub => $value) {
    if (!function_exists($stub)) {
        eval("function $stub() { return " . var_export($value, true) . '; }');
    }
}
defined('OSC_CACHE_TTL') || define('OSC_CACHE_TTL', 60);
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocation.php';
// hDefines redeclares functions the scratch harness already defines, so only the list getters are loaded.
preg_match_all('/^function osc_get_(?:countries|regions|cities)\(.*?^}\n/ms', (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php'), $getters);
pin('the three list getters were found', 3, count($getters[0]));
eval(implode("\n", $getters[0]));

harness_reset_container();
$store = new LocationNameStore();
$store->set('region', $zurich, 'de_DE', 'Aachen');
$store->set('country', 'US', 'de_DE', 'Vereinigte Staaten');
$store->set('city', $cities[1], 'de_DE', 'Zzz');
$fresh = static function () use ($store): void {
    Container::root()->set(LocationNames::class, static fn () => new LocationNames($store));
};
$locale = static function (string $code): void {
    $GLOBALS['__lists_locale'] = $code;
    $_COOKIE['oc_userLocale']  = $code;
};
$shown = static fn (array $rows, string $key): array => array_column($rows, $key);

harness_section('osc_get_*');
$locale('en_US');
$fresh();
$plain = Region::getInstance()->findByCountry('US');
pin('en_US rows are the DAO rows, byte for byte', $plain, osc_get_regions('US'));
$locale('de_DE');
$fresh();
pin('de_DE puts the German name first', ['Aachen', 'Alpine'], $shown(osc_get_regions('US'), 's_name'));
pin('the id stays with its row', [(string) $zurich, (string) $alpine], $shown(osc_get_regions('US'), 'pk_i_id'));
pin('countries are translated', 'Vereinigte Staaten', osc_get_countries()[0]['s_name']);
pin('an untranslated city keeps its name', 'City 02', osc_get_cities($alpine)[0]['s_name']);

$fresh();
$q = harness_query_count(static function () use ($alpine): void {
    osc_get_cities($alpine);
});
pin('a 20-city list costs the list query plus one name query', 2, $q);

harness_section('osc_has_*');
$locale('de_DE');
$fresh();
View::newInstance()->_erase('regions');
$order = [];
while (osc_has_regions('US')) {
    $order[] = osc_region_name();
}
pin('the loop yields the same order as osc_get_regions()', ['Aachen', 'Alpine'], $order);
View::newInstance()->_erase('regions');
$locale('en_US');
$fresh();
$order = [];
while (osc_has_regions('US')) {
    $order[] = osc_region_name();
}
pin('en_US loop keeps the stored names', ['Alpine', 'Zurich'], $order);
View::newInstance()->_erase('regions');
$locale('de_DE');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
