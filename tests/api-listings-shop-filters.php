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
 * What a shop reads from GET /listings: `low_stock` (the count when 1 to 5 are left, else null,
 * for every caller) and the `in_stock` and `on_sale` filters.
 * Usage: php tests/api-listings-shop-filters.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_api_shop_filters');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0',
    'defaultResultsPerPage@search' => '50', 'maxResultsPerPage@search' => '50', 'defaultOrderField@search' => 'dt_pub_date', 'defaultOrderType@search' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

// title => [price, stock, was price], in micros; null stock is not tracked.
$spec = [
    'Low'        => [10000000, 3, null],
    'Edge five'  => [10000000, 5, null],
    'Edge six'   => [10000000, 6, null],
    'Plenty'     => [10000000, 12, null],
    'Untracked'  => [10000000, null, null],
    'Sold out'   => [10000000, 0, 15000000],
    'Deal'       => [10000000, 4, 12000000],
    'Same price' => [10000000, 9, 10000000],
    'Cheaper'    => [10000000, 9, 8000000],
];
$id = [];
foreach ($spec as $title => [$price, $stock, $was]) {
    $id[$title] = seed_item($admin, $cat, $sue, $title, $price / 1000000);
    VariantStore::saveDefault($id[$title], ['i_price' => $price, 'i_stock' => $stock, 'i_compare_price' => $was]);
}
$id['No variant'] = seed_item($admin, $cat, $sue, 'No variant', 10.0);

$key  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$call  = api_admin_caller();
$list  = static fn (array $query): \mindstellar\api\Response => $call('GET', 'listings', null, $key, [], $query);
$names = static fn (\mindstellar\api\Response $r): array => (static function (array $titles): array {
    sort($titles);

    return $titles;
})(array_column($r->body()['data'] ?? [], 'title'));
$low = static function (int $listing) use ($call, $key): mixed {
    $r = $call('GET', 'listings/' . $listing, null, $key);

    $variant = $r->body()['data']['product']['variants'][0] ?? [];

    return array_key_exists('low_stock', $variant) ? $variant['low_stock'] : 'missing';
};

harness_section('low_stock');
pin('3 left shows 3', 3, $low($id['Low']));
pin('5 left shows 5', 5, $low($id['Edge five']));
pin('6 left shows nothing', null, $low($id['Edge six']));
pin('12 left shows nothing', null, $low($id['Plenty']));
pin('stock not tracked shows nothing', null, $low($id['Untracked']));
pin('none left shows nothing', null, $low($id['Sold out']));
$mine = $call('GET', 'listings/' . $id['Low'], null, $key)->body()['data']['product']['variants'][0];
pin('the owner view keeps the count as stock', 3, $mine['stock'] ?? null);
$visitor = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'tom', ['listings:read'], \mindstellar\apikey\KeyOwner::user(seed_user($admin, 'tom', 'tom@example.test')))->token();
$seen = $call('GET', 'listings/' . $id['Low'], null, $visitor)->body()['data']['product']['variants'][0];
pin('another caller sees low_stock but not stock', [3, false], [$seen['low_stock'] ?? null, array_key_exists('stock', $seen)]);
pin('the response matches the schema', [], api_admin_schema_errors('ListingDocument', $call('GET', 'listings/' . $id['Low'], null, $key)));

exit(harness_result());
