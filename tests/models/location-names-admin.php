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
 * LocationService edits save a place's names per site language in the same transaction as the rename.
 *
 * Usage:  php tests/models/location-names-admin.php
 *         php tests/run-models.php location-names-admin
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

// A process of its own, as earlier files in the suite define DEMO, which locks LocationService.
if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = scratchdb_session('osc_models_location_names_admin');

foreach (array('_m', '__') as $translate) {
    if (!function_exists($translate)) {
        eval('function ' . $translate . '($text) { return $text; }');
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

use mindstellar\exception\InvalidException;
use mindstellar\location\LocationNameStore;
use mindstellar\location\LocationService;

$p = DB_TABLE_PREFIX;
seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');
seed_locale($admin, 'fr_FR', 'Francais');
$admin->query("UPDATE {$p}t_locale SET b_enabled = 0 WHERE pk_c_code = 'fr_FR'");
$country = seed_country($admin, 'DE', 'Germany');
$region  = seed_region($admin, $country, 'Bavaria');
$city    = seed_city($admin, $region, 'Munich', $country);

harness_reset_container();
$service = new LocationService();
$store   = new LocationNameStore();
pin('the enabled languages leave out fr_FR', ['de_DE', 'en_US'], array_values(array_column(osc_get_locales(), 'pk_c_code')));

$regionName = static fn (): string => (string) $admin->query("SELECT s_name FROM {$p}t_region WHERE pk_i_id = $region")->fetch_row()[0];
$refused    = static function (callable $write): ?string {
    try {
        $write();
    } catch (InvalidException $e) {
        return $e->pointer();
    }

    return null;
};

harness_section('region names');
$service->editRegion($region, 'Bavaria', '', ['de_DE' => 'Bayern']);
pin('a name is stored for its language', ['de_DE' => 'Bayern'], $store->all('region', $region));
pin('the stored name is kept', 'Bavaria', $regionName());
$service->editRegion($region, 'Bavaria', '', ['de_DE' => '  Freistaat Bayern  ']);
pin('a name is stored trimmed', ['de_DE' => 'Freistaat Bayern'], $store->all('region', $region));
$service->editRegion($region, 'Bavaria', '', ['de_DE' => '']);
pin('a blank name deletes the language', [], $store->all('region', $region));
$service->editRegion($region, 'Bavaria', '', ['de_DE' => 'Bayern', 'en_US' => 'Bavaria']);
pin('two languages are written', ['de_DE' => 'Bayern', 'en_US' => 'Bavaria'], $store->all('region', $region));
$service->editRegion($region, 'Bavaria', '', ['en_US' => '']);
pin('blanking one language keeps a language not sent', ['de_DE' => 'Bayern'], $store->all('region', $region));
$service->editRegion($region, 'Bayern State', '');
pin('a rename with no names leaves them alone', ['de_DE' => 'Bayern'], $store->all('region', $region));
pin('the rename itself is saved', 'Bayern State', $regionName());

harness_section('refusals roll the whole save back');
pin('a disabled language is refused', '/names/fr_FR', $refused(static fn () => $service->editRegion($region, 'Renamed', '', ['de_DE' => 'Neu', 'fr_FR' => 'Bavière'])));
pin('an unknown language is refused', '/names/xx_XX', $refused(static fn () => $service->editRegion($region, 'Renamed', '', ['xx_XX' => 'x'])));
pin('a value that is not text is refused', '/names/de_DE', $refused(static fn () => $service->editRegion($region, 'Renamed', '', ['de_DE' => ['Bayern']])));
pin('a refused save keeps the old name', 'Bayern State', $regionName());
pin('a refused save writes no names', ['de_DE' => 'Bayern'], $store->all('region', $region));
$long = str_repeat('ü', 101);
pin('a 101-character name is refused', '/names/de_DE', $refused(static fn () => $service->editRegion($region, 'Bayern State', '', ['de_DE' => $long])));
$service->editRegion($region, 'Bayern State', '', ['de_DE' => mb_substr($long, 1)]);
pin('a 100-character name is stored', 100, mb_strlen($store->all('region', $region)['de_DE']));

harness_section('countries and cities');
$service->editCountry('DE', 'Germany', '', ['de_DE' => 'Deutschland']);
pin('a country name is stored under its lower-case code', ['de_DE' => 'Deutschland'], $store->all('country', 'de'));
pin('the country row keeps its name', 'Germany', (string) $admin->query("SELECT s_name FROM {$p}t_country WHERE pk_c_code = 'DE'")->fetch_row()[0]);
$service->editCity($city, 'Munich', '', ['de_DE' => 'München']);
pin('a city name is stored', ['de_DE' => 'München'], $store->all('city', $city));
pin('a city refusal names the language', '/names/fr_FR', $refused(static fn () => $service->editCity($city, 'Munich', '', ['fr_FR' => 'Munich'])));

harness_section('query cost');
$service->editCity($city, 'Munich', '', ['de_DE' => 'München', 'en_US' => 'Munich City']);
$rename  = harness_query_count(static fn () => $service->editCity($city, 'Munich', '', null));
$changed = harness_query_count(static fn () => $service->editCity($city, 'Munich', '', ['de_DE' => 'Muenchen', 'en_US' => 'Munich City']));
$same    = harness_query_count(static fn () => $service->editCity($city, 'Munich', '', ['de_DE' => 'Muenchen', 'en_US' => 'Munich City']));
pin('changing one of two languages costs one read and one write', 2, $changed - $rename);
pin('a save with no change costs one read', 1, $same - $rename);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
