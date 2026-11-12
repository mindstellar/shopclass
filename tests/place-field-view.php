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
 * The Location field: combobox wiring, ids derived from the id option, escaping, the refused-save
 * message, the hint by setting, and which place wins (refused form, listing, saved place).
 *
 * DB-free.  Usage: php tests/place-field-view.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

function _m($text)
{
    return $text;
}
function osc_location_name(string $type, $id, string $fallback = ''): string
{
    return $fallback;
}

use mindstellar\location\PlaceField;

$empty = PlaceField::compose(array('cityId' => '', 'city' => '', 'regionId' => '', 'region' => '', 'countryId' => '', 'country' => ''), null, null);
$kept  = static fn (array $over): array => $over + array('cityId' => '', 'city' => '', 'regionId' => '', 'region' => '', 'countryId' => '', 'country' => '');
$dom   = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

    return new DOMXPath($doc);
};
$attr = static fn (DOMXPath $x, string $q, string $name): ?string => $x->query($q)->item(0)?->attributes?->getNamedItem($name)?->nodeValue;

harness_section('markup');
$x = $dom(PlaceField::render(array('id' => 'loc', 'required' => true), $empty, true, false));
pin('the input is a combobox wired to its list and notes', array('combobox', 'list', 'false', 'loc-list', 'loc-hint loc-note'), array(
    $attr($x, '//input[@id="loc"]', 'role'),
    $attr($x, '//input[@id="loc"]', 'aria-autocomplete'),
    $attr($x, '//input[@id="loc"]', 'aria-expanded'),
    $attr($x, '//input[@id="loc"]', 'aria-controls'),
    $attr($x, '//input[@id="loc"]', 'aria-describedby'),
));
pin('the list, hint, note and error ids follow the id', array('loc-list', 'loc-hint', 'loc-note', 'loc-error'), array(
    $attr($x, '//ul[@role="listbox"]', 'id'),
    $attr($x, '//p[contains(@class,"oe-place__hint")]', 'id'),
    $attr($x, '//p[contains(@class,"oe-place__note")]', 'id'),
    $attr($x, '//p[contains(@class,"oe-field-error")]', 'id'),
));
pin('the label points at the input and carries a required star', array('loc', 1), array(
    $attr($x, '//label', 'for'),
    $x->query('//label/span[@aria-hidden="true"]')->length,
));
pin('the five hidden inputs post the place keys', array('cityId', 'regionId', 'countryId', 'region', 'country'), array_map(
    static fn (DOMNode $n): string => $n->attributes->getNamedItem('name')->nodeValue,
    iterator_to_array($x->query('//input[@type="hidden"]'))
));
pin('the hidden ids derive from the id', array('loc-cityId', 'loc-regionId', 'loc-countryId', 'loc-region', 'loc-country'), array_map(
    static fn (DOMNode $n): string => $n->attributes->getNamedItem('id')->nodeValue,
    iterator_to_array($x->query('//input[@type="hidden"]'))
));
pin('the root carries the script hook and options', array('1', '2', '8', '1'), array(
    $attr($x, '//div[@data-osc-place]', 'data-strict'),
    $attr($x, '//div[@data-osc-place]', 'data-min'),
    $attr($x, '//div[@data-osc-place]', 'data-limit'),
    $attr($x, '//div[@data-osc-place]', 'data-countries'),
));
$multi = $dom(PlaceField::render(array(), $empty, false, true));
pin('a site with several countries says so, and OFF is not strict', array('n', '0'), array(
    $attr($multi, '//div[@data-osc-place]', 'data-countries'),
    $attr($multi, '//div[@data-osc-place]', 'data-strict'),
));
pin('the star is left out when the place is not required', 0, $multi->query('//label/span')->length);
pin('theme classes are added, not swapped in', array(true, true, true), array(
    str_contains((string) $attr($dom(PlaceField::render(array('class' => 'sf-field', 'hint_class' => 'sf-hint', 'error_class' => 'sf-err'), $empty, true, false)), '//div[@data-osc-place]', 'class'), 'oe-place sf-field'),
    str_contains((string) $attr($dom(PlaceField::render(array('hint_class' => 'sf-hint'), $empty, true, false)), '//p[contains(@class,"oe-place__hint")]', 'class'), 'sf-hint'),
    str_contains((string) $attr($dom(PlaceField::render(array('error_class' => 'sf-err'), $empty, true, false)), '//p[contains(@class,"oe-field-error")]', 'class'), 'sf-err'),
));

harness_section('escaping');
$bad = PlaceField::compose($kept(array('city' => '"><script>alert(1)</script>', 'region' => '"><b>', 'country' => "'><i>")), null, null);
$html = PlaceField::render(array('id' => 'p"x', 'label' => '<u>L</u>'), $bad, true, false, '<s>oops</s>');
pin('no raw tag or quote survives from a value, id, label or message', 0, preg_match('/<script|<b>|<i>|<u>|<s>|id="p"x/', $html));
pin('the typed text comes back as text', '"><script>alert(1)</script>', $attr($dom($html), '//input[@name="city"]', 'value'));

harness_section('refused save');
$x = $dom(PlaceField::render(array('id' => 'loc'), $empty, true, false, 'Pick your location from the list.'));
pin('the message shows in the error line and marks the input', array('Pick your location from the list.', null, 'true', 'loc-error loc-hint loc-note'), array(
    trim($x->query('//p[contains(@class,"oe-field-error")]')->item(0)->textContent),
    $attr($x, '//p[contains(@class,"oe-field-error")]', 'hidden'),
    $attr($x, '//input[@id="loc"]', 'aria-invalid'),
    $attr($x, '//input[@id="loc"]', 'aria-describedby'),
));
$ok = $dom(PlaceField::render(array('id' => 'loc'), $empty, true, false));
pin('with no refusal the error line is hidden and the input is valid', array('', null), array($attr($ok, '//p[contains(@class,"oe-field-error")]', 'hidden'), $attr($ok, '//input[@id="loc"]', 'aria-invalid')));

harness_section('hint by setting');
$hintOn  = trim($ok->query('//p[contains(@class,"oe-place__hint")]')->item(0)->textContent);
$hintOff = trim($dom(PlaceField::render(array(), $empty, false, false))->query('//p[contains(@class,"oe-place__hint")]')->item(0)->textContent);
pin('ON and OFF each print a hint, and the two differ', array(true, true, true), array($hintOn !== '', $hintOff !== '', $hintOn !== $hintOff));

harness_section('which place wins');
$account = array('label' => 'Mumbai, Maharashtra', 'city_id' => 5001, 'region_id' => 101, 'country_code' => 'IN', 'origin' => 'address');
$listing = array('fk_i_city_id' => '5100', 's_city' => 'Munnar', 'fk_i_region_id' => '103', 's_region' => 'Kerala', 'fk_c_country_code' => 'IN');
$pick    = static fn (array $v): array => array($v['text'], $v['city_id'], $v['region_id'], $v['country_code'], $v['origin']);
pin('a listing with a city id shows as picked', array('Munnar, Kerala', 5100, 103, 'IN', 'listing'), $pick(PlaceField::compose($kept(array()), $listing, $account)));
pin('a refused form beats the listing', array('Pune, Maharashtra', 5002, 101, 'IN', 'list'), $pick(PlaceField::compose($kept(array('cityId' => '5002', 'regionId' => '101', 'countryId' => 'IN', 'city' => 'Pune, Maharashtra')), $listing, $account)));
pin('a refused form with a typed place beats the listing and keeps the text', array('Bombay', 0, 'Maha', 'India'), (static function ($v) {
    return array($v['text'], $v['city_id'], $v['region_text'], $v['country_text']);
})(PlaceField::compose($kept(array('city' => 'Bombay', 'region' => 'Maha', 'country' => 'India')), $listing, $account)));
pin('a new post takes the saved place', array('Mumbai, Maharashtra', 5001, 101, 'IN', 'address'), $pick(PlaceField::compose($kept(array()), null, $account)));
pin('a new post with no saved place is empty', array('', 0, 0, '', ''), $pick($empty));
$legacy = PlaceField::compose($kept(array()), array('fk_i_city_id' => null, 's_city' => 'Bombay (Andheri)', 's_region' => 'MH', 's_country' => 'India', 'fk_c_country_code' => null), $account);
pin('an old typed listing is text only, with its region and country in the hidden text', array('Bombay (Andheri)', 0, 'MH', 'India'), array($legacy['text'], $legacy['city_id'], $legacy['region_text'], $legacy['country_text']));
$x = $dom(PlaceField::render(array('id' => 'loc'), $legacy, true, false));
pin('and posts no ids but its typed region and country', array('', '', '', 'MH', 'India'), array_map(
    static fn (DOMNode $n): string => $n->attributes->getNamedItem('value')->nodeValue,
    iterator_to_array($x->query('//input[@type="hidden"]'))
));

harness_section('picked state, notes and the saved button');
$p = $dom(PlaceField::render(array('id' => 'loc'), array_replace(PlaceField::compose($kept(array()), $listing, $account), array('saved' => $account)), true, false));
pin('a picked place marks the field, fills the ids and notes where it came from', array(true, 'listing', '5100', '103', 'IN', 'This listing’s place'), array(
    str_contains((string) $attr($p, '//div[@data-osc-place]', 'class'), 'is-picked'),
    $attr($p, '//div[@data-osc-place]', 'data-origin'),
    $attr($p, '//input[@name="cityId"]', 'value'),
    $attr($p, '//input[@name="regionId"]', 'value'),
    $attr($p, '//input[@name="countryId"]', 'value'),
    trim($p->query('//p[contains(@class,"oe-place__note")]')->item(0)->textContent),
));
pin('the saved button carries the place as JSON', array('Mumbai, Maharashtra', 5001, 101, 'IN', 'address'), (static function ($json) {
    $d = json_decode((string) $json, true);

    return array($d['label'], $d['city_id'], $d['region_id'], $d['country_code'], $d['origin']);
})($attr($p, '//button[contains(@class,"oe-place__saved")]', 'data-place')));
$none = $dom(PlaceField::render(array(), $empty, true, false));
pin('with no saved place the button stays hidden and carries no place', array('', null), array(
    $attr($none, '//button[contains(@class,"oe-place__saved")]', 'hidden'),
    $attr($none, '//button[contains(@class,"oe-place__saved")]', 'data-place'),
));
$typedOff = $dom(PlaceField::render(array(), PlaceField::compose($kept(array('city' => 'Bombay')), null, null), false, false));
pin('OFF warns that a typed place is shown as typed; ON does not', array(true, ''), array(
    trim($typedOff->query('//p[contains(@class,"oe-place__note")]')->item(0)->textContent) !== '',
    trim($dom(PlaceField::render(array(), PlaceField::compose($kept(array('city' => 'Bombay')), null, null), true, false))->query('//p[contains(@class,"oe-place__note")]')->item(0)->textContent),
));

exit(harness_result());
