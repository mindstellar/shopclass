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
 * Cash on delivery limited to areas: no areas offers it everywhere; a country, region or city
 * area offers it only to an address inside, matched by code, id or name ignoring case and
 * accents; an incomplete address is outside; placing an order refuses it outside.
 * Usage: php tests/billing-cod-areas.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\CartPricer;
use mindstellar\billing\CodAreas;
use mindstellar\billing\GoodsCheckout;
use mindstellar\exception\InvalidException;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_cod_areas');

$locale = seed_locale($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach ([
    'enabled_users'            => '1',
    'language'                 => 'en_US',
    'currency'                 => 'USD',
    'billing_enabled'          => '1',
    'billing_currency'         => 'USD',
    'billing_offline_enabled'  => '1',
    'billing_offline_instructions' => 'Pay to account 123',
    'billing_cod_enabled'      => '1',
    'billing_open_hold_orders' => '0',
] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

seed_country($admin, 'IN', 'India');
seed_country($admin, 'DE', 'Germany');
$punjab = seed_region($admin, 'IN', 'Punjab');
$delhi  = seed_region($admin, 'IN', 'Delhi');
$ludh   = seed_city($admin, $punjab, 'Ludhiana', 'IN');
seed_city($admin, $delhi, 'Dwarka', 'IN');
$bavaria = seed_region($admin, 'DE', 'Bayern');
$munich  = seed_city($admin, $bavaria, 'München', 'DE');

$mug     = seed_item($admin, $cat, $sue, 'Mug', 5.0);
$u       = VariantStore::saveDefault($mug, ['i_stock' => null]);
$lines   = [['variant' => $u, 'qty' => 1]];
$pricer  = new CartPricer();
$checkout = new GoodsCheckout();
$offered = static fn (array $address): bool => isset(GoodsCheckout::gateways($pricer->quote($lines, ['address' => $address, 'gateway' => 'cod']))['cod']);
$setAreas = static function (array $areas): void {
    Preference::getInstance()->set(CodAreas::PREF, $areas === [] ? '' : json_encode($areas));
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$full    = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Ludhiana', 'state' => 'Punjab', 'postcode' => '141001', 'country' => 'IN'];
$refused = static function (callable $fn): array {
    try {
        $fn();
    } catch (InvalidException $e) {
        return array_map(static fn (array $x): string => $x['pointer'] . ' ' . $x['code'], $e->errors());
    }

    return ['not refused'];
};
$place = static fn (array $address) => $checkout->place($tom, $lines, $address, 'standard', 'cod');

harness_section('no areas');
pin('cash on delivery is offered everywhere, even with no address', [true, true, true], [$offered([]), $offered(['country' => 'DE']), $offered($full)]);

harness_section('a country');
$setAreas([['type' => 'country', 'id' => 'IN']]);
pin('inside by code or by name, any case', [true, true, true], [$offered(['country' => 'IN']), $offered(['country' => 'in']), $offered(['country' => ' INDIA '])]);
pin('outside is not offered', [false, false], [$offered(['country' => 'DE']), $offered(['country' => 'Germany'])]);
pin('no country yet is not offered', false, $offered(['state' => 'Punjab']));

harness_section('a region');
$setAreas([['type' => 'region', 'id' => (string) $punjab]]);
pin('inside by name, by id, ignoring case', [true, true, true], [$offered(['country' => 'IN', 'state' => 'Punjab']), $offered(['country' => 'IN', 'state' => 'PUNJAB']), $offered(['country' => 'IN', 'state' => (string) $punjab])]);
pin('another region, another country, or no state is not offered', [false, false, false], [$offered(['country' => 'IN', 'state' => 'Delhi']), $offered(['country' => 'DE', 'state' => 'Punjab']), $offered(['country' => 'IN'])]);

harness_section('a city');
$setAreas([['type' => 'city', 'id' => (string) $ludh], ['type' => 'city', 'id' => (string) $munich]]);
pin('inside by city name in its region', [true, true], [$offered(['country' => 'IN', 'state' => 'Punjab', 'city' => 'ludhiana']), $offered(['country' => 'DE', 'state' => 'bayern', 'city' => 'Munchen'])]);
pin('another city, the wrong region, or no city is not offered', [false, false, false], [$offered(['country' => 'IN', 'state' => 'Punjab', 'city' => 'Amritsar']), $offered(['country' => 'IN', 'state' => 'Delhi', 'city' => 'Ludhiana']), $offered(['country' => 'IN', 'state' => 'Punjab'])]);

harness_section('placing');
$setAreas([['type' => 'country', 'id' => 'IN']]);
pin('inside the areas places the order', true, $place($full)['order']->getId() > 0);
pin('outside is refused as unavailable', ['/gateway unavailable'], $refused(static fn () => $place(['country' => 'DE', 'state' => 'Bayern', 'city' => 'München'] + $full)));
pin('another way to pay is still placed outside', true, $checkout->place($tom, $lines, ['country' => 'DE'] + $full, 'standard', 'offline')['order']->getId() > 0);

harness_section('the saved list');
pin('clean keeps well-formed distinct entries only', [[['type' => 'country', 'id' => 'IN'], ['type' => 'region', 'id' => '5']]], [CodAreas::clean('[{"type":"country","id":"in"},{"type":"country","id":"IN"},{"type":"city","id":"x"},{"type":"state","id":"1"},{"type":"region","id":5},7]')]);
pin('a deleted place is dropped', 1, count(CodAreas::places([['type' => 'region', 'id' => (string) $punjab], ['type' => 'region', 'id' => '99999']])));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
