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
 * The admin listing form's Location: with 'place' the picker draws one place field in place of the
 * country, region and city controls, filled from the listing or a refused save, with the refusal
 * under it. Without 'place' it draws the old controls.
 *
 * DB-free.  Usage: php tests/admin-item-form-place.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/admin-ui-stubs.php';

function __($text)
{
    return $text;
}
function _m($text)
{
    return $text;
}
function osc_current_ui_locale()
{
    return 'en_US';
}
// The names come back as stored: this test is about the form, not the translations.
\mindstellar\container\Container::root()->instance(\mindstellar\location\LocationNames::class, new class () {
    public function name(string $type, $id, string $locale, string $fallback): string
    {
        return $fallback;
    }
});
function osc_enqueue_script($id)
{
}
function osc_enqueue_style($id)
{
}
function osc_item_required_location()
{
    return 'city';
}
function osc_item_place_style(): string
{
    return $GLOBALS['placeStyle'] ?? 'single';
}
function osc_item_place_from_list(): bool
{
    return false;
}
function osc_get_countries()
{
    return array(array('pk_c_code' => 'IN', 's_name' => 'India'));
}
function osc_form_error(string $pointer): string
{
    return '';
}
class Session
{
    public static array $form = array();

    public static function getInstance(): self
    {
        return new self();
    }

    public function _getForm($key = null)
    {
        return self::$form[$key] ?? '';
    }
}

require_once ABS_PATH . 'oc-includes/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/classes/admin/ui/Picker.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocation.php';

$dom = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

    return new DOMXPath($doc);
};
$attr = static fn (DOMXPath $x, string $q, string $name): ?string => $x->query($q)->item(0)?->attributes?->getNamedItem($name)?->nodeValue;
$draw = static function (array $opts) use ($dom): DOMXPath {
    ob_start();
    osc_admin_location_picker($opts + array('detail' => 'none'));

    return $dom(ob_get_clean());
};
$listing = array('fk_i_city_id' => 7, 's_city' => 'Mumbai', 'fk_i_region_id' => 3, 's_region' => 'Maharashtra', 'fk_c_country_code' => 'IN');

harness_section('place field in the admin form');
$x = $draw(array('place' => true, 'item' => $listing, 'required' => array('city')));
pin('one place input shows the listing\'s place', array(1, 'Mumbai, Maharashtra'), array(
    $x->query('//input[contains(@class,"oe-place__input")]')->length,
    $attr($x, '//input[contains(@class,"oe-place__input")]', 'value'),
));
pin('the hidden ids carry the listing\'s place', array('7', '3', 'IN'), array(
    $attr($x, '//input[@name="cityId"]', 'value'),
    $attr($x, '//input[@name="regionId"]', 'value'),
    $attr($x, '//input[@name="countryId"]', 'value'),
));
pin('no country select and no region text box', array(0, 0), array(
    $x->query('//select')->length,
    $x->query('//input[@id="region"][@type="text"]')->length,
));
pin('the old ids stay, for plugins that look them up', array('oe-place__input', 'cityId', 'regionId', 'countryId', 'region', 'country'), array(
    $attr($x, '//input[@id="city"]', 'class'),
    ...array_map(static fn (string $id): ?string => $attr($x, '//input[@type="hidden"][@id="' . $id . '"]', 'name'), array('cityId', 'regionId', 'countryId', 'region', 'country')),
));
pin('the field is marked required when the site asks for a place', 1, $x->query('//span[contains(@class,"oe-place__req")]')->length);
pin('the field takes the admin field class', true, str_contains((string) $attr($x, '//div[@data-osc-place]', 'class'), 'osc-field'));

$placeStyle = 'dropdown';
try {
    $drawn = \mindstellar\location\PlaceField::prepare(array('account' => false, 'saved' => false))[0]['style'];
} catch (Throwable $e) {
    $drawn = 'dropdown (needs the lists)';
}
pin('the admin form is one box even when the site setting is dropdown', 'single', $drawn);
$placeStyle = 'single';

harness_section('refused save');
$x = $draw(array('place' => true, 'errors' => array('city' => 'Pick your location from the list.')));
pin('the refusal shows under the field and marks the input', array('Pick your location from the list.', null, 'true'), array(
    trim($x->query('//p[@id="city-error"]')->item(0)->textContent),
    $attr($x, '//p[@id="city-error"]', 'hidden'),
    $attr($x, '//input[contains(@class,"oe-place__input")]', 'aria-invalid'),
));
$x = $draw(array('place' => true, 'errors' => array('region' => 'Choose a region.')));
pin('a region refusal lands on the same field', 'Choose a region.', trim($x->query('//p[@id="city-error"]')->item(0)->textContent));
Session::$form = array('city' => 'Bombay', 'cityId' => '');
$x = $draw(array('place' => true, 'item' => $listing));
pin('what was typed in a refused form beats the listing', 'Bombay', $attr($x, '//input[contains(@class,"oe-place__input")]', 'value'));
Session::$form = array('city' => 'Pune, Maharashtra', 'cityId' => '9', 'regionId' => '4', 'countryId' => 'IN');
$x = $draw(array('place' => true, 'item' => $listing, 'errors' => array('city' => 'Pick your location from the list.')));
pin('a refused save re-shows the typed text, the error and the picked ids', array('Pune, Maharashtra', '9', '4', 'IN', 'Pick your location from the list.'), array(
    $attr($x, '//input[contains(@class,"oe-place__input")]', 'value'),
    $attr($x, '//input[@name="cityId"]', 'value'),
    $attr($x, '//input[@name="regionId"]', 'value'),
    $attr($x, '//input[@name="countryId"]', 'value'),
    trim($x->query('//p[@id="city-error"]')->item(0)->textContent),
));
Session::$form = array();

harness_section('without place');
$x = $draw(array('countries' => array(array('pk_c_code' => 'IN', 's_name' => 'India')), 'value' => array('city' => 'Pune')));
pin('the old country select, region and city boxes are drawn', array(1, 1, 1, 0), array(
    $x->query('//select[@id="countryId"]')->length,
    $x->query('//input[@id="region"]')->length,
    $x->query('//input[@id="city"]')->length,
    $x->query('//input[contains(@class,"oe-place__input")]')->length,
));

exit(harness_result());

/* file end: ./tests/admin-item-form-place.php */
