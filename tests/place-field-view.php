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

function __($text)
{
    return $text;
}
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
$x = $dom(PlaceField::render(array('id' => 'loc', 'required' => true, 'strict' => true, 'multi' => false), $empty));
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
$multi = $dom(PlaceField::render(array('strict' => false, 'multi' => true), $empty));
pin('a site with several countries says so, and OFF is not strict', array('n', '0'), array(
    $attr($multi, '//div[@data-osc-place]', 'data-countries'),
    $attr($multi, '//div[@data-osc-place]', 'data-strict'),
));
pin('the star is left out when the place is not required', 0, $multi->query('//label/span')->length);
pin('theme classes are added, not swapped in', array(true, true, true), array(
    str_contains((string) $attr($dom(PlaceField::render(array('class' => 'sf-field', 'hint_class' => 'sf-hint', 'error_class' => 'sf-err', 'strict' => true, 'multi' => false), $empty)), '//div[@data-osc-place]', 'class'), 'oe-place oe-place--single sf-field'),
    str_contains((string) $attr($dom(PlaceField::render(array('hint_class' => 'sf-hint', 'strict' => true, 'multi' => false), $empty)), '//p[contains(@class,"oe-place__hint")]', 'class'), 'sf-hint'),
    str_contains((string) $attr($dom(PlaceField::render(array('error_class' => 'sf-err', 'strict' => true, 'multi' => false), $empty)), '//p[contains(@class,"oe-field-error")]', 'class'), 'sf-err'),
));

harness_section('escaping');
$bad = PlaceField::compose($kept(array('city' => '"><script>alert(1)</script>', 'region' => '"><b>', 'country' => "'><i>")), null, null);
$html = PlaceField::render(array('id' => 'p"x', 'label' => '<u>L</u>', 'strict' => true, 'multi' => false, 'error' => '<s>oops</s>'), $bad);
pin('no raw tag or quote survives from a value, id, label or message', 0, preg_match('/<script|<b>|<i>|<u>|<s>|id="p"x/', $html));
pin('the typed text comes back as text', '"><script>alert(1)</script>', $attr($dom($html), '//input[@name="city"]', 'value'));

harness_section('refused save');
$x = $dom(PlaceField::render(array('id' => 'loc', 'strict' => true, 'multi' => false, 'error' => 'Pick your location from the list.'), $empty));
pin('the message shows in the error line and marks the input', array('Pick your location from the list.', null, 'true', 'loc-error loc-hint loc-note'), array(
    trim($x->query('//p[contains(@class,"oe-field-error")]')->item(0)->textContent),
    $attr($x, '//p[contains(@class,"oe-field-error")]', 'hidden'),
    $attr($x, '//input[@id="loc"]', 'aria-invalid'),
    $attr($x, '//input[@id="loc"]', 'aria-describedby'),
));
$ok = $dom(PlaceField::render(array('id' => 'loc', 'strict' => true, 'multi' => false), $empty));
pin('with no refusal the error line is hidden and the input is valid', array('', null), array($attr($ok, '//p[contains(@class,"oe-field-error")]', 'hidden'), $attr($ok, '//input[@id="loc"]', 'aria-invalid')));

harness_section('hint by setting');
$hintOn  = trim($ok->query('//p[contains(@class,"oe-place__hint")]')->item(0)->textContent);
$hintOff = trim($dom(PlaceField::render(array('strict' => false, 'multi' => false), $empty))->query('//p[contains(@class,"oe-place__hint")]')->item(0)->textContent);
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
$x = $dom(PlaceField::render(array('id' => 'loc', 'strict' => true, 'multi' => false), $legacy));
pin('and posts no ids but its typed region and country', array('', '', '', 'MH', 'India'), array_map(
    static fn (DOMNode $n): string => $n->attributes->getNamedItem('value')->nodeValue,
    iterator_to_array($x->query('//input[@type="hidden"]'))
));

harness_section('picked state, notes and the saved button');
$p = $dom(PlaceField::render(array('id' => 'loc', 'strict' => true, 'multi' => false), array_replace(PlaceField::compose($kept(array()), $listing, $account), array('saved' => $account))));
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
$none = $dom(PlaceField::render(array('strict' => true, 'multi' => false), $empty));
pin('with no saved place the button stays hidden and carries no place', array('', null), array(
    $attr($none, '//button[contains(@class,"oe-place__saved")]', 'hidden'),
    $attr($none, '//button[contains(@class,"oe-place__saved")]', 'data-place'),
));
$typedOff = $dom(PlaceField::render(array('strict' => false, 'multi' => false), PlaceField::compose($kept(array('city' => 'Bombay')), null, null)));
pin('OFF warns that a typed place is shown as typed; ON does not', array(true, ''), array(
    trim($typedOff->query('//p[contains(@class,"oe-place__note")]')->item(0)->textContent) !== '',
    trim($dom(PlaceField::render(array('strict' => true, 'multi' => false), PlaceField::compose($kept(array('city' => 'Bombay')), null, null)))->query('//p[contains(@class,"oe-place__note")]')->item(0)->textContent),
));

harness_section('single style is unchanged');
$phase1Value = PlaceField::value(array('cityId' => '5100', 'city' => 'Munnar', 'regionId' => '103', 'region' => 'Kerala', 'countryId' => 'IN', 'country' => ''), null, array('label' => 'Mumbai, Maharashtra', 'city_id' => 5001, 'region_id' => 101, 'country_code' => 'IN', 'origin' => 'address'));
$phase1Html  = PlaceField::render(array('id' => 'loc', 'label' => 'Where', 'required' => true, 'class' => 'sf-field', 'hint_class' => 'h', 'error_class' => 'e', 'req_class' => 'r', 'strict' => true, 'multi' => true, 'error' => 'Pick one.'), $phase1Value);
pin('the markup equals the phase 1 output but for the style class and attribute value', trim((string) file_get_contents(__DIR__ . '/fixtures/place-single-phase1.html')), trim(str_replace(array(' oe-place--single', 'data-osc-place="single"'), array('', 'data-osc-place'), $phase1Html)));
pin('and says which style it is', array('single', true), array($attr($dom($phase1Html), '//div[@data-osc-place]', 'data-osc-place'), str_contains($phase1Html, 'oe-place--single')));
pin('an unknown style is drawn as single', 'single', $attr($dom(PlaceField::render(array('style' => 'x<b>', 'strict' => true, 'multi' => false), $empty)), '//div[@data-osc-place]', 'data-osc-place'));

harness_section('fields style');
$lists = array('countries' => array(array('code' => 'IN', 'name' => 'India'), array('code' => 'DE', 'name' => 'Germany')), 'regions' => array(), 'cities' => array());
$fieldsValue = PlaceField::compose($kept(array('countryId' => 'IN', 'regionId' => '101', 'region' => 'Maharashtra')), null, null);
$x = $dom(PlaceField::render(array('id' => 'loc', 'style' => 'fields', 'label' => 'Where', 'part_class' => 'sf-field', 'required_levels' => array('country' => true, 'region' => true, 'city' => false), 'strict' => true, 'multi' => true, 'lists' => $lists), $fieldsValue));
pin('the root is a named group of the style', array('fields', 'group', 'loc-label', 'Where'), array(
    $attr($x, '//div[@data-osc-place]', 'data-osc-place'),
    $attr($x, '//div[@data-osc-place]', 'role'),
    $attr($x, '//div[@data-osc-place]', 'aria-labelledby'),
    trim($x->query('//span[@id="loc-label"]')->item(0)->textContent),
));
pin('three parts in order, each with the theme class', array('country', 'region', 'city', true), array(
    ...array_map(static fn (DOMNode $n): string => $n->attributes->getNamedItem('data-level')->nodeValue, iterator_to_array($x->query('//div[contains(@class,"oe-place__part")]'))),
    str_contains((string) $attr($x, '//div[@data-level="city"]', 'class'), 'sf-field'),
));
pin('the country is a select of the enabled countries', array('countryId', 'loc-country', array('', 'IN', 'DE'), 'IN'), array(
    $attr($x, '//select', 'name'),
    $attr($x, '//select', 'id'),
    array_map(static fn (DOMNode $n): string => $n->attributes->getNamedItem('value')->nodeValue, iterator_to_array($x->query('//select/option'))),
    $attr($x, '//select/option[@selected]', 'value'),
));
pin('region and city are comboboxes with their own list and ids', array('region', 'loc-region', 'loc-region-list', 'city', 'loc-city', 'loc-city-list'), array(
    $attr($x, '//input[@id="loc-region"]', 'name'),
    $attr($x, '//input[@id="loc-region"]', 'id'),
    $attr($x, '//input[@id="loc-region"]', 'aria-controls'),
    $attr($x, '//input[@id="loc-city"]', 'name'),
    $attr($x, '//input[@id="loc-city"]', 'id'),
    $attr($x, '//input[@id="loc-city"]', 'aria-controls'),
));
pin('both are comboboxes', array('combobox', 'combobox'), array($attr($x, '//input[@id="loc-region"]', 'role'), $attr($x, '//input[@id="loc-city"]', 'role')));
pin('the kept region keeps its id and name, and the city is empty', array('101', 'Maharashtra', '', ''), array(
    $attr($x, '//input[@name="regionId"]', 'value'),
    $attr($x, '//input[@id="loc-region"]', 'value'),
    $attr($x, '//input[@name="cityId"]', 'value'),
    $attr($x, '//input[@id="loc-city"]', 'value'),
));
pin('no control carries a bare countryId, regionId or cityId id', 0, $x->query('//*[@id="countryId" or @id="regionId" or @id="cityId"]')->length);
pin('the star follows each level', array(1, 1, 0), array(
    $x->query('//div[@data-level="country"]//span[contains(@class,"oe-place__req")]')->length,
    $x->query('//div[@data-level="region"]//span[contains(@class,"oe-place__req")]')->length,
    $x->query('//div[@data-level="city"]//span[contains(@class,"oe-place__req")]')->length,
));
pin('one hint, the saved button and the live region; the field texts ride on the root', array(1, 1, 1, 'Select a region...', 'Loading…'), array(
    $x->query('//p[@id="loc-hint"]')->length,
    $x->query('//button[contains(@class,"oe-place__saved")]')->length,
    $x->query('//div[contains(@class,"oe-place__live")]')->length,
    $attr($x, '//div[@data-osc-place]', 'data-text-select-region'),
    $attr($x, '//div[@data-osc-place]', 'data-text-loading'),
));
$bad = PlaceField::compose($kept(array('countryId' => 'IN', 'regionId' => '101', 'region' => '"><img onerror=1>')), null, null);
$html = PlaceField::render(array('id' => 'loc', 'style' => 'fields', 'strict' => true, 'multi' => true, 'lists' => $lists), $bad);
pin('a hostile region name stays text', array(0, '"><img onerror=1>'), array(preg_match('/<img/', $html), $attr($dom($html), '//input[@id="loc-region"]', 'value')));

harness_section('errors sit under their own part');
$x = $dom(PlaceField::render(array('id' => 'loc', 'style' => 'fields', 'errors' => array('region' => 'Pick a region.'), 'strict' => true, 'multi' => true, 'lists' => $lists), $empty));
pin('a region refusal shows under the region only and marks the region box', array('Pick a region.', '', '', 'true', null), array(
    trim($x->query('//p[@id="loc-region-error"]')->item(0)->textContent),
    trim($x->query('//p[@id="loc-country-error"]')->item(0)->textContent),
    trim($x->query('//p[@id="loc-city-error"]')->item(0)->textContent),
    $attr($x, '//input[@id="loc-region"]', 'aria-invalid'),
    $attr($x, '//input[@id="loc-city"]', 'aria-invalid'),
));
pin('only the part with a message is shown', array(null, '', ''), array(
    $attr($x, '//p[@id="loc-region-error"]', 'hidden'),
    $attr($x, '//p[@id="loc-country-error"]', 'hidden'),
    $attr($x, '//p[@id="loc-city-error"]', 'hidden'),
));

harness_section('one country');
$oneList = array('countries' => array(array('code' => 'IN', 'name' => 'India')), 'regions' => array(), 'cities' => array());
foreach (array('fields', 'dropdown') as $style) {
    $x = $dom(PlaceField::render(array('id' => 'loc', 'style' => $style, 'strict' => true, 'multi' => false, 'lists' => $oneList), array_replace($empty, array('country_code' => 'IN'))));
    pin($style . ': no country control, a hidden country code', array(0, 0, 'IN'), array(
        $x->query('//select[@name="countryId"]')->length,
        $x->query('//div[@data-level="country"]')->length,
        $attr($x, '//input[@type="hidden"][@name="countryId"]', 'value'),
    ));
}

harness_section('dropdown style');
$ddLists = array(
    'countries' => $lists['countries'],
    'regions'   => array(array('id' => '101', 'name' => 'Maharashtra'), array('id' => '103', 'name' => 'Kerala"><img onerror=1>')),
    'cities'    => array(array('id' => '5001', 'name' => 'Mumbai'), array('id' => '5002', 'name' => 'Pune')),
);
$ddValue = PlaceField::compose($kept(array()), array('fk_i_city_id' => '5002', 's_city' => 'Pune', 'fk_i_region_id' => '101', 's_region' => 'Maharashtra', 'fk_c_country_code' => 'IN'), null);
$html = PlaceField::render(array('id' => 'loc', 'style' => 'dropdown', 'strict' => true, 'multi' => true, 'lists' => $ddLists), $ddValue);
$x    = $dom($html);
$hintOf = static fn (string $style): string => trim($dom(PlaceField::render(array('id' => 'loc', 'style' => $style, 'strict' => false, 'multi' => true, 'lists' => $ddLists), $ddValue))->query('//p[@id="loc-hint"]')->item(0)->textContent);
check('each style has its own hint, so dropdowns do not ask the visitor to type', count(array_unique(array($hintOf('single'), $hintOf('fields'), $hintOf('dropdown')))) === 3);
pin('three selects named by id', array('countryId', 'regionId', 'cityId'), array_map(static fn (DOMNode $n): string => $n->attributes->getNamedItem('name')->nodeValue, iterator_to_array($x->query('//select'))));
pin('the listing\'s country, region and city are selected', array('IN', '101', '5002'), array(
    $attr($x, '//select[@name="countryId"]/option[@selected]', 'value'),
    $attr($x, '//select[@name="regionId"]/option[@selected]', 'value'),
    $attr($x, '//select[@name="cityId"]/option[@selected]', 'value'),
));
pin('the sibling options are listed', array(array('', '101', '103'), array('', '5001', '5002')), array(
    array_map(static fn (DOMNode $n): string => $n->attributes->getNamedItem('value')->nodeValue, iterator_to_array($x->query('//select[@name="regionId"]/option'))),
    array_map(static fn (DOMNode $n): string => $n->attributes->getNamedItem('value')->nodeValue, iterator_to_array($x->query('//select[@name="cityId"]/option'))),
));
pin('a hostile name in an option stays text', 0, preg_match('/<img/', $html));
pin('no select is disabled when both parents are set', 0, $x->query('//select[@disabled]')->length);
$noCity = $dom(PlaceField::render(array('id' => 'loc', 'style' => 'dropdown', 'strict' => true, 'multi' => true, 'lists' => array('cities' => array()) + $ddLists), PlaceField::compose($kept(array('countryId' => 'IN', 'regionId' => '101')), null, null)));
pin('a kept region with no city keeps the region selected and the city select disabled', array('101', null, 1), array(
    $attr($noCity, '//select[@name="regionId"]/option[@selected]', 'value'),
    $attr($noCity, '//select[@name="cityId"]/option[@selected]', 'value'),
    $noCity->query('//select[@name="cityId"][@disabled]')->length,
));
$none = $dom(PlaceField::render(array('id' => 'loc', 'style' => 'dropdown', 'strict' => true, 'multi' => true, 'lists' => array('regions' => array(), 'cities' => array()) + $ddLists), $empty));
pin('with no country the region and city selects are empty and disabled', array(1, 1), array(
    $none->query('//select[@name="regionId"][@disabled]')->length,
    $none->query('//select[@name="cityId"][@disabled]')->length,
));
$bare = array('regions' => array(), 'cities' => array()) + $ddLists;
$kr   = PlaceField::compose($kept(array('countryId' => 'IN')), null, null);
$on   = $dom(PlaceField::render(array('id' => 'loc', 'style' => 'dropdown', 'strict' => true, 'multi' => true, 'lists' => $bare), $kr));
$off  = $dom(PlaceField::render(array('id' => 'loc', 'style' => 'dropdown', 'strict' => false, 'multi' => true, 'lists' => $bare), $kr));
pin('a country with no regions: option on gives a disabled select, off gives text boxes', array(1, 0, 0, 1, 1), array(
    $on->query('//select[@name="regionId"][@disabled]')->length,
    $on->query('//input[@name="region"]')->length,
    $off->query('//select[@name="regionId"]')->length,
    $off->query('//input[@type="text"][@name="region"]')->length,
    $off->query('//input[@type="text"][@name="city"]')->length,
));

harness_section('kept region without a city');
$k = PlaceField::compose($kept(array('countryId' => 'IN', 'regionId' => '101', 'region' => 'Maharashtra')), null, null);
pin('the value keeps the region id, country code and name', array(0, 101, 'IN', 'Maharashtra'), array($k['city_id'], $k['region_id'], $k['country_code'], $k['region_name']));
$k = PlaceField::compose($kept(array()), array('fk_i_city_id' => null, 's_city' => '', 'fk_i_region_id' => '103', 's_region' => 'Kerala', 'fk_c_country_code' => 'IN'), null);
pin('a listing with a region and no city keeps them too', array(103, 'IN', 'Kerala'), array($k['region_id'], $k['country_code'], $k['region_name']));
pin('the single style still sends no ids for it', array('', '', ''), array_map(
    static fn (string $n): string => (string) $attr($dom(PlaceField::render(array('id' => 'loc', 'strict' => true, 'multi' => false), $k)), '//input[@name="' . $n . '"]', 'value'),
    array('cityId', 'regionId', 'countryId')
));

exit(harness_result());
