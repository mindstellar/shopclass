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
 * LocationNameStore and LocationNames: country, region and city names per language.
 *
 * Usage:  php tests/models/location-names.php
 *         php tests/run-models.php location-names
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\container\Container;
use mindstellar\location\LocationNames;
use mindstellar\location\LocationNameStore;

$admin = scratchdb_session('osc_models_location_names');

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');
$country = seed_country($admin, 'DE', 'Germany');
$region  = seed_region($admin, $country, 'Rhineland');
$cologne = seed_city($admin, $region, 'Cologne', $country);
$bonn    = seed_city($admin, $region, 'Bonn', $country);

harness_reset_container();
$store = new LocationNameStore();
$names = new LocationNames($store);
$table = DB_TABLE_PREFIX . 't_location_name';
$count = static fn (): int => (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];

harness_section('Store: round trip');
$store->set('city', $cologne, 'de_DE', 'Köln');
$store->set('city', $bonn, 'de_DE', 'Bonn');
pin('names() returns the saved name by id', [(string) $cologne => 'Köln'], $store->names('city', [$cologne], 'de_DE'));
pin('names() for a locale with no row is empty', [], $store->names('city', [$cologne], 'en_US'));
pin('names() with no ids is empty', [], $store->names('city', [], 'de_DE'));
$store->set('city', $cologne, 'de_DE', 'Köln am Rhein');
pin('set() again replaces the name', 'Köln am Rhein', $store->names('city', [$cologne], 'de_DE')[(string) $cologne]);
$store->set('city', $cologne, 'en_US', 'Cologne City');
pin('all() lists every language', ['de_DE' => 'Köln am Rhein', 'en_US' => 'Cologne City'], $store->all('city', $cologne));
$store->set('city', $cologne, 'de_DE', 'Köln');
$store->set('city', $cologne, 'en_US', '  ');
pin('a blank name deletes the row', ['de_DE' => 'Köln'], $store->all('city', $cologne));

harness_section('Store: country ids, forget, locale delete');
$store->set('country', 'DE', 'de_DE', 'Deutschland');
pin('a country id is stored lower case', 'de', $admin->query("SELECT s_place_id FROM $table WHERE e_place_type = 'country'")->fetch_assoc()['s_place_id']);
pin('names() takes the code in any case', ['de' => 'Deutschland'], $store->names('country', ['DE'], 'de_DE'));
$store->forget('city', $bonn);
pin('forget() clears every language of the place', [], $store->all('city', $bonn));
pin('forget() leaves other places', ['de_DE' => 'Köln'], $store->all('city', $cologne));
seed_locale($admin, 'fr_FR', 'Francais');
$store->set('city', $cologne, 'fr_FR', 'Cologne');
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_locale WHERE pk_c_code = 'fr_FR'");
pin('deleting a locale removes its names', ['de_DE' => 'Köln'], $store->all('city', $cologne));

harness_section('Store: find ids by name');
pin('idsNamed() finds the id', [(string) $cologne], $store->idsNamed('city', 'Köln'));
pin('idsNamed() ignores case and accents', [(string) $cologne], $store->idsNamed('city', 'koln'));
pin('idsStartingWith() finds the id', [(string) $cologne], $store->idsStartingWith('city', 'kö'));
pin('idsStartingWith() with no match is empty', [], $store->idsStartingWith('city', 'zz'));
pin('a percent sign in the prefix is not a wildcard', [], $store->idsStartingWith('city', '%'));
pin('another place type is not searched', [], $store->idsNamed('region', 'Köln'));

harness_section('Resolver: memo');
$names = new LocationNames($store);
$q = harness_query_count(static function () use ($names, $cologne, $region): void {
    $names->prime('de_DE', ['city' => [$cologne], 'region' => [$region], 'country' => ['DE']]);
});
pin('prime() for three types runs three queries', 3, $q);
$q = harness_query_count(static function () use ($names, $cologne, $region): void {
    $names->prime('de_DE', ['city' => [$cologne], 'region' => [$region], 'country' => ['DE']]);
});
pin('priming the same ids again runs none', 0, $q);
pin('a known miss is remembered', 0, harness_query_count(static fn () => $names->name('region', $region, 'de_DE', 'x')));
pin('name() gives the translation', 'Köln', $names->name('city', $cologne, 'de_DE', 'Cologne'));
pin('name() falls back', 'Cologne', $names->name('city', $cologne, 'en_US', 'Cologne'));
pin('name() with no id falls back', 'Free', $names->name('city', 0, 'de_DE', 'Free'));

harness_section('Resolver: rows');
$rows = [
    ['pk_i_id' => 1, 'fk_i_city_id' => (string) $cologne, 'fk_i_region_id' => (string) $region, 'fk_c_country_code' => 'DE', 's_city' => 'Cologne', 's_region' => 'Rhineland', 's_country' => 'Germany'],
    ['pk_i_id' => 2, 'fk_i_city_id' => null, 'fk_i_region_id' => null, 'fk_c_country_code' => null, 's_city' => 'Free text', 's_region' => '', 's_country' => ''],
];
$de = $names->translateRows($rows, 'de_DE');
pin('de_DE rows get the German names', ['Köln', 'Rhineland', 'Deutschland'], [$de[0]['s_city'], $de[0]['s_region'], $de[0]['s_country']]);
pin('a row with no ids is untouched', $rows[1], $de[1]);
pin('en_US rows are byte-identical', $rows, $names->translateRows($rows, 'en_US'));

harness_section('Resolver: lists');
$bonnAgain = $bonn;
$list = [
    ['city_id' => $cologne, 'city_name' => 'Cologne'],
    ['city_id' => $bonnAgain, 'city_name' => 'Bonn'],
];
pin('an untouched list keeps its order', $list, $names->translateList($list, 'city', 'city_id', 'city_name', 'en_US'));
$store->set('city', $bonnAgain, 'de_DE', 'Bonn');
$names2 = new LocationNames($store);
$sorted = $names2->translateList($list, 'city', 'city_id', 'city_name', 'de_DE');
pin('a translated list is sorted by the shown name', ['Bonn', 'Köln'], array_column($sorted, 'city_name'));

harness_section('Helper');
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!function_exists('osc_current_user_locale')) {
    function osc_current_user_locale()
    {
        return 'de_DE';
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocation.php';
Container::root()->set(LocationNames::class, static fn () => new LocationNames($store));
pin('osc_location_name() gives the current locale name', 'Köln', osc_location_name('city', $cologne, 'Cologne'));
pin('osc_location_name() falls back for a place with no name', 'Nowhere', osc_location_name('city', 999999, 'Nowhere'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
