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
 * The per-country address format: which fields show and are needed, the postcode pattern, the
 * phone rule, and the default for a country not in the map.
 *
 * DB-free.  Usage: php tests/address-format.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}

use mindstellar\user\AddressFormat;

$base   = ['name' => 'Sue', 'phone' => '+44 20 7946 0958', 'line1' => '1 High St', 'city' => 'Leeds', 'state' => '', 'postcode' => '', 'country' => ''];
$wrong  = static fn (array $a, bool $ships = true): array => array_column(AddressFormat::problems($a, '', $ships), 'code', 'pointer');
$needed = static fn (string $c): array => AddressFormat::for($c)['required'];

harness_section('the map');
pin('about 45 countries', true, count(AddressFormat::all()) >= 45);
pin('US asks for state and ZIP code', [['line1', 'city', 'state', 'postcode'], 'ZIP code'], [$needed('US'), AddressFormat::for('us')['postcode']]);
pin('GB: no county, a required Postcode', [['line1', 'city', 'postcode'], '', 'Postcode'], [$needed('GB'), AddressFormat::for('GB')['state'], AddressFormat::for('GB')['postcode']]);
pin('JP: prefecture, postcode first', [['postcode', 'state', 'city', 'line1', 'line2'], true], [AddressFormat::for('JP')['fields'], in_array('state', $needed('JP'), true)]);
pin('DE: no state shown, postcode before city', [['line1', 'line2', 'postcode', 'city'], ''], [AddressFormat::for('DE')['fields'], AddressFormat::for('DE')['state']]);
pin('IN: state and a 6-digit PIN code', [['line1', 'city', 'state', 'postcode'], 'PIN code'], [array_values(array_intersect(['line1', 'city', 'state', 'postcode'], $needed('IN'))), AddressFormat::for('IN')['postcode']]);
pin('JP: a city field the post does not need is shown but optional', [true, false], [in_array('city', AddressFormat::for('JP')['fields'], true), in_array('city', $needed('JP'), true)]);
pin('HK: a required Area and no postcode', [['state', 'city', 'line1', 'line2'], 'Area', true, null], [AddressFormat::for('HK')['fields'], AddressFormat::for('HK')['state'], in_array('state', $needed('HK'), true), AddressFormat::for('HK')['pattern']]);
pin('an unknown country gets the default', [AddressFormat::for(''), ['line1', 'city'], 'State / Province', 'Postcode', null], [AddressFormat::for('ZZ'), $needed('ZZ'), AddressFormat::for('ZZ')['state'], AddressFormat::for('ZZ')['postcode'], AddressFormat::for('ZZ')['pattern']]);
pin('every pattern is valid in PCRE and has an example that matches', [], array_keys(array_filter(AddressFormat::all(), static fn (array $f): bool => $f['pattern'] !== null && preg_match('/' . $f['pattern'] . '/iu', $f['example']) !== 1)));

harness_section('what is needed');
pin('GB saves with no state', [], $wrong(['country' => 'GB', 'postcode' => 'SW1A 1AA'] + $base));
pin('GB without a postcode is refused', ['/postcode' => 'required'], $wrong(['country' => 'GB'] + $base));
pin('US without state is refused', ['/state' => 'required'], $wrong(['country' => 'US', 'postcode' => '78701'] + $base));
pin('US saves with state and ZIP', [], $wrong(['country' => 'US', 'state' => 'TX', 'postcode' => '78701'] + $base));
pin('JP saves with prefecture', [], $wrong(['country' => 'JP', 'state' => 'Tokyo', 'postcode' => '100-0001'] + $base));
pin('DE saves with no state', [], $wrong(['country' => 'DE', 'postcode' => '10115'] + $base));
pin('IN needs state', ['/state' => 'required'], $wrong(['country' => 'IN', 'postcode' => '110001'] + $base));
pin('HK saves with an area and no postcode', [], $wrong(['country' => 'HK', 'state' => 'Kowloon'] + $base));
pin('IE saves with no Eircode', [], $wrong(['country' => 'IE'] + $base));
pin('an unknown country saves with just the basics', [], $wrong(['country' => 'ZZ'] + $base));
pin('name, phone, line1, city and country are needed everywhere', ['/name' => 'required', '/phone' => 'required', '/line1' => 'required', '/city' => 'required', '/country' => 'required'], $wrong(['name' => '', 'phone' => '', 'line1' => '', 'city' => '', 'country' => ''] + $base));
pin('nothing is needed when the cart does not ship', [], $wrong(['name' => '', 'phone' => '', 'line1' => '', 'city' => '', 'country' => 'US'] + $base, false));

harness_section('postcode and phone');
pin('IN keeps its 6-digit rule', ['/postcode' => 'pattern'], $wrong(['country' => 'IN', 'state' => 'Delhi', 'postcode' => '12345'] + $base));
pin('IN passes with 6 digits', [], $wrong(['country' => 'IN', 'state' => 'Delhi', 'postcode' => '110001'] + $base));
pin('a typed postcode is checked even where optional', ['/postcode' => 'pattern'], $wrong(['country' => 'IE', 'postcode' => 'nope'] + $base));
pin('GB postcodes with and without the space pass', [], array_merge($wrong(['country' => 'GB', 'postcode' => 'sw1a1aa'] + $base), $wrong(['country' => 'GB', 'postcode' => 'M1 1AE'] + $base)));
pin('a UK number with a plus passes', null, AddressFormat::phoneProblem('+44 20 7946 0958'));
pin('a UK number without a plus passes', null, AddressFormat::phoneProblem('020 7946 0958'));
pin('brackets and dashes pass', null, AddressFormat::phoneProblem('(212) 555-0100'));
pin('a 2-digit number fails', 'pattern', AddressFormat::phoneProblem('12')[0] ?? '');
pin('two plus signs fail', 'pattern', AddressFormat::phoneProblem('+1+2')[0] ?? '');
pin('letters fail', 'pattern', AddressFormat::phoneProblem('555-CALL-ME')[0] ?? '');
pin('more than 15 digits fail', 'pattern', AddressFormat::phoneProblem('1234567890123456')[0] ?? '');

pin('lines() writes a German address with the postcode before the city', ['Torstraße 14', '10119 Berlin'], AddressFormat::lines(['country' => 'DE', 'line1' => 'Torstraße 14', 'city' => 'Berlin', 'postcode' => '10119', 'state' => 'ignored']));
pin('lines() writes a US address with the ZIP after the state', ['1 Main St', 'Austin', 'TX 73301'], AddressFormat::lines(['country' => 'US', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301']));

$data = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/user/AddressFormatData.php');
check('the generated data keeps its credit to Google and its CC BY 4.0 licence', str_contains($data, 'copyright Google LLC') && str_contains($data, 'https://creativecommons.org/licenses/by/4.0/') && str_contains($data, 'Changed:'));
check('the data covers about every country', count(\mindstellar\user\AddressFormatData::MAP) > 240);

exit(harness_result());
