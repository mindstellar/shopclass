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
 * What the Location field starts with: the listing being edited, a refused form, the signed-in
 * user's saved address or account city, nothing for a guest; and the printed field.
 *
 * Usage:  php tests/models/place-field-prefill.php       (standalone, own scratch database)
 *         php tests/run-models.php place-field-prefill   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_place_field_prefill');
foreach (['hLocation', 'hUtils', 'hSanitize', 'hPreference'] as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
$p     = DB_TABLE_PREFIX;
seed_locale($admin);
seed_country($admin, 'IN', 'India');
$mh     = seed_region($admin, 'IN', 'Maharashtra');
$kl     = seed_region($admin, 'IN', 'Kerala');
$mumbai = seed_city($admin, $mh, 'Mumbai', 'IN');
$pune   = seed_city($admin, $mh, 'Pune', 'IN');
$munnar = seed_city($admin, $kl, 'Munnar', 'IN');
$guest  = 0;
$sue    = seed_user($admin, 'sue', 'sue@example.test');
$tom    = seed_user($admin, 'tom', 'tom@example.test');
$ann    = seed_user($admin, 'ann', 'ann@example.test');
$admin->query("UPDATE {$p}t_user SET s_address = '1 Main St', fk_c_country_code = 'IN', fk_i_region_id = $mh, s_region = 'Maharashtra', fk_i_city_id = $mumbai, s_city = 'Mumbai' WHERE pk_i_id = $sue");
$admin->query("UPDATE {$p}t_user SET fk_c_country_code = 'IN', fk_i_region_id = $mh, s_region = 'Maharashtra', fk_i_city_id = $pune, s_city = 'Pune' WHERE pk_i_id = $tom");
$admin->query("UPDATE {$p}t_user SET fk_c_country_code = 'IN', s_region = 'Goa', s_city = 'Panaji' WHERE pk_i_id = $ann");
foreach (['language' => 'en_US', 'item_required_location' => 'city'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$queued = [];
if (!function_exists('osc_get_countries')) {
    function osc_get_countries()
    {
        return _osc_location_list(Country::getInstance()->listAll(), 'country');
    }
}
if (!function_exists('osc_enqueue_script')) {
    function osc_enqueue_script($id)
    {
        $GLOBALS['queued'][] = 'script:' . $id;
    }
}
if (!function_exists('osc_enqueue_style')) {
    function osc_enqueue_style($id, $url = null)
    {
        $GLOBALS['queued'][] = 'style:' . $id;
    }
}

$login = static function (int $id): void {
    Session::getInstance()->_setEphemeral('userId', $id);
};
$kept = static function (array $form): void {
    $s = Session::getInstance();
    foreach (['cityId', 'city', 'regionId', 'region', 'countryId', 'country', 'form_errors'] as $k) {
        $s->_setForm($k, $form[$k] ?? '');
    }
};
$view = static fn (array $v): array => [$v['text'], $v['city_id'], $v['region_id'], $v['country_code'], $v['origin'], $v['saved']['label'] ?? null];
$listing = static fn (int $city, int $region, string $cityName, string $regionName): array => [
    'fk_i_city_id' => (string) $city, 's_city' => $cityName, 'fk_i_region_id' => (string) $region, 's_region' => $regionName, 'fk_c_country_code' => 'IN',
];

harness_section('new post');
$kept([]);
$login($guest);
pin('a guest starts empty with no saved button', ['', 0, 0, '', '', null], $view(osc_place_field_value()));
$login($sue);
pin('a user with an address gets it, origin address, and no button for the place already shown', ['Mumbai, Maharashtra', $mumbai, $mh, 'IN', 'address', null], $view(osc_place_field_value()));
$login($tom);
pin('a user with only an account city gets it, origin account', ['Pune, Maharashtra', $pune, $mh, 'IN', 'account', null], $view(osc_place_field_value()));
$login($ann);
pin('an account city with no id is not offered', ['', 0, 0, '', '', null], $view(osc_place_field_value()));

harness_section('edit');
$login($sue);
$row = $listing($munnar, $kl, 'Munnar', 'Kerala');
pin('the listing\'s place shows picked, and the saved place is offered', ['Munnar, Kerala', $munnar, $kl, 'IN', 'listing', 'Mumbai, Maharashtra'], $view(osc_place_field_value(['item' => $row])));
pin('the saved button can be turned off', null, osc_place_field_value(['item' => $row, 'saved' => false])['saved']);
pin('an old typed listing is text only, ids empty, region and country text kept', ['Bombay (Andheri)', 0, 'MH', 'India'], (static function (array $v): array {
    return [$v['text'], $v['city_id'], $v['region_text'], $v['country_text']];
})(osc_place_field_value(['item' => ['fk_i_city_id' => null, 's_city' => 'Bombay (Andheri)', 's_region' => 'MH', 's_country' => 'India']])));
$kept(['cityId' => (string) $pune, 'regionId' => (string) $mh, 'countryId' => 'IN', 'city' => 'Pune, Maharashtra']);
pin('a refused form beats the listing', ['Pune, Maharashtra', $pune, $mh, 'IN', 'list'], array_slice($view(osc_place_field_value(['item' => $row])), 0, 5));
$kept(['city' => 'Bombay', 'region' => 'MH', 'country' => 'India']);
pin('a refused typed place is kept as typed', ['Bombay', 0, 'MH', 'India'], (static function (array $v): array {
    return [$v['text'], $v['city_id'], $v['region_text'], $v['country_text']];
})(osc_place_field_value(['item' => $row])));

harness_section('the printed field');
$kept([]);
$print = static function (array $opts = []): string {
    ob_start();
    osc_place_field($opts);

    return (string) ob_get_clean();
};
$html = $print();
pin('it prints the combobox with the user\'s saved place picked', [1, 1, 'Mumbai, Maharashtra'], [
    substr_count($html, 'role="combobox"'),
    substr_count($html, 'is-picked'),
    preg_match('/id="place"[^>]*value="([^"]*)"/', $html, $m) === 1 ? $m[1] : null,
]);
pin('it queues the field script and style', true, [] === array_diff(['script:osc-place-field', 'style:osc-ui-common'], $queued));
pin('the place is required because the setting asks for a city', 1, preg_match('/<label for="place">Location<span/', $html));
$kept(['form_errors' => ['/region' => 'Pick your location from the list.']]);
$html = $print(['id' => 'loc']);
pin('a refused place shows its message and marks the input', [1, 1], [
    preg_match('/<p class="oe-field-error" id="loc-error">Pick your location from the list\.<\/p>/', $html),
    preg_match('/<input[^>]*id="loc"[^>]*aria-invalid="true"/', $html),
]);

exit(harness_result());
