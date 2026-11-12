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

harness_section('fields and dropdown styles');
$x = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

    return new DOMXPath($doc);
};
$opts = static fn (DOMXPath $d, string $q): array => array_map(static fn (DOMNode $n): string => $n->attributes->getNamedItem('value')->nodeValue, iterator_to_array($d->query($q)));
$login($sue);
$kept([]);
$row = $listing($pune, $mh, 'Pune', 'Maharashtra');
$d   = $x($print(['id' => 'loc', 'style' => 'dropdown', 'item' => $row]));
pin('dropdown edit: the listing\'s region and city are selected', [(string) $mh, (string) $pune], [
    $d->query('//select[@name="regionId"]/option[@selected]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
    $d->query('//select[@name="cityId"]/option[@selected]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
]);
pin('the region list holds the country\'s regions and the city list only the region\'s cities', [['', (string) $kl, (string) $mh], ['', (string) $mumbai, (string) $pune]], [
    $opts($d, '//select[@name="regionId"]/option'),
    $opts($d, '//select[@name="cityId"]/option'),
]);
pin('a one-country site draws no country control', [0, 'IN'], [
    $d->query('//select[@name="countryId"]')->length,
    $d->query('//input[@type="hidden"][@name="countryId"]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
]);
$region = $x($print(['id' => 'loc', 'style' => 'dropdown', 'item' => ['fk_i_city_id' => null, 'fk_i_region_id' => (string) $kl, 's_region' => 'Kerala', 's_city' => '', 'fk_c_country_code' => 'IN']]));
pin('a listing with a region and no city keeps the region and lists its cities', [(string) $kl, ['', (string) $munnar]], [
    $region->query('//select[@name="regionId"]/option[@selected]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
    $opts($region, '//select[@name="cityId"]/option'),
]);
$kept(['regionId' => (string) $kl, 'countryId' => 'IN', 'region' => 'Kerala']);
foreach (['fields', 'dropdown'] as $style) {
    $d = $x($print(['id' => 'loc', 'style' => $style]));
    $keptRegion = $style === 'fields'
        ? $d->query('//input[@name="regionId"]')->item(0)?->attributes->getNamedItem('value')->nodeValue
        : $d->query('//select[@name="regionId"]/option[@selected]')->item(0)?->attributes->getNamedItem('value')->nodeValue;
    pin("$style: a refused form with a region and no city keeps the region id", (string) $kl, $keptRegion);
}
$kept([]);
$d = $x($print(['id' => 'loc', 'style' => 'fields', 'item' => $row]));
pin('fields edit: region and city boxes hold the names and ids', ['Maharashtra', (string) $mh, 'Pune', (string) $pune], [
    $d->query('//input[@id="loc-region"]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
    $d->query('//input[@name="regionId"]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
    $d->query('//input[@id="loc-city"]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
    $d->query('//input[@name="cityId"]')->item(0)?->attributes->getNamedItem('value')->nodeValue,
]);
$kept(['form_errors' => ['/region' => 'Pick your location from the list.']]);
$d = $x($print(['id' => 'loc', 'style' => 'fields']));
pin('a refused /region message shows under the region part only', ['Pick your location from the list.', '', ''], [
    trim($d->query('//p[@id="loc-region-error"]')->item(0)->textContent),
    trim($d->query('//p[@id="loc-city-error"]')->item(0)->textContent),
    trim($d->query('//p[@id="loc-country-error"]')?->item(0)?->textContent ?? ''),
]);
$kept([]);
Preference::getInstance()->set('item_place_style', 'dropdown');
scratchdb_forget_cache();
osc_reset_preferences();
pin('the site setting picks the style when none is given', 'dropdown', $x($print(['id' => 'loc']))->query('//div[@data-osc-place]')->item(0)->attributes->getNamedItem('data-osc-place')->nodeValue);
pin('the dropdown root carries the ajax address its lists reload from', osc_base_url(true), $x($print(['id' => 'loc']))->query('//div[@data-osc-place]')->item(0)->attributes->getNamedItem('data-ajax')?->nodeValue);
pin('the other styles carry none', [null, null], array_map(
    static fn (string $style): ?string => $x($print(['id' => 'loc', 'style' => $style]))->query('//div[@data-osc-place]')->item(0)->attributes->getNamedItem('data-ajax')?->nodeValue,
    ['single', 'fields']
));

exit(harness_result());
