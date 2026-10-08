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
 * LocationService adds, renames and deletes countries the way Settings -> Locations did
 * through the Country model: same refusals, slugs made from the name, delete hooks fired.
 *
 * Usage:  php tests/models/location-country-service.php        (standalone, own scratch database)
 *         php tests/run-models.php location-country-service    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_location_country_service');

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
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

use mindstellar\location\LocationService;
use mindstellar\validation\NotFoundException;
use mindstellar\validation\RefusedException;

$p       = DB_TABLE_PREFIX;
$service = new LocationService();
$country = static function (string $code) use ($admin, $p): ?array {
    $code = $admin->real_escape_string($code);

    return $admin->query("SELECT pk_c_code, s_name, s_slug FROM {$p}t_country WHERE pk_c_code = '$code'")->fetch_assoc();
};
$refusal = static function (callable $write): ?string {
    try {
        $write();
    } catch (RefusedException $e) {
        return $e->getMessage();
    }

    return null;
};

harness_section('add');
pin('a lower-case code is stored upper case', 'FR', $service->addCountry(' fr ', 'France'));
pin('the slug is made from the name', ['FR', 'France', 'france'], array_values($country('FR')));
pin('a blank name is refused', 'Country name cannot be blank', $refusal(static fn () => $service->addCountry('DE', '')));
pin('a malformed code is refused', 'The country code must be two letters, like IN or DE', $refusal(static fn () => $service->addCountry('DEU', 'Germany')));
pin('a code already stored is refused', 'France already was in the database', $refusal(static fn () => $service->addCountry('FR', 'France')));
pin('a refused add writes nothing', null, $country('DE'));

harness_section('rename');
$service->editCountry('FR', 'République française', '');
pin('a rename with no slug makes one from the name', ['République française', 'republique-francaise'], [$country('FR')['s_name'], $country('FR')['s_slug']]);
$service->editCountry('FR', 'France', 'la-france');
pin('a typed slug is kept', 'la-france', $country('FR')['s_slug']);
$service->addCountry('BE', 'Belgium');
$service->editCountry('BE', 'Belgium', 'la-france');
pin('a slug another country holds is not taken', 'belgium', $country('BE')['s_slug']);
$service->editCountry('FR', 'France', 'la-france');
pin('re-saving the same values is not a failure', 'la-france', $country('FR')['s_slug']);
pin('a blank name is refused', 'Country name cannot be blank', $refusal(static fn () => $service->editCountry('FR', '')));
$missing = null;
try {
    $service->editCountry('ZZ', 'Nowhere');
} catch (NotFoundException $e) {
    $missing = $e->getMessage();
}
pin('an unknown country is not found', 'This location no longer exists.', $missing);

harness_section('delete');
$region = seed_region($admin, 'FR', 'Bretagne');
seed_city($admin, $region, 'Rennes', 'FR');
$fired = array();
osc_add_hook('before_delete_country', static function ($code) use (&$fired): void {
    $fired[] = 'before ' . $code;
});
osc_add_hook('after_delete_country', static function ($code) use (&$fired): void {
    $fired[] = 'after ' . $code;
});
$service->delete('country', 'fr');
pin('the country is gone', null, $country('FR'));
pin('its regions and cities go with it', [0, 0], [
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_region WHERE fk_c_country_code = 'FR'")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_city WHERE fk_c_country_code = 'FR'")->fetch_row()[0],
]);
pin('the delete hooks fire', ['before FR', 'after FR'], $fired);
pin('another country is kept', 'BE', $country('BE')['pk_c_code']);
$gone = static function (string $code) use ($service): bool {
    try {
        $service->delete('country', $code);
    } catch (NotFoundException $e) {
        return true;
    }

    return false;
};
pin('an unknown or malformed code is not found', [true, true, true], [$gone('FR'), $gone('ZZZ'), $gone('')]);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
