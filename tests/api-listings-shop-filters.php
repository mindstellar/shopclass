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

harness_section('in_stock');
$all = ['Cheaper', 'Deal', 'Edge five', 'Edge six', 'Low', 'No variant', 'Plenty', 'Same price', 'Sold out', 'Untracked'];
pin('no filter lists everything', $all, $names($list([])));
pin('in_stock=true drops stock 0 and listings with no variant', array_values(array_diff($all, ['Sold out', 'No variant'])), $names($list(['in_stock' => 'true'])));
pin('in_stock=false is the same as no filter', $all, $names($list(['in_stock' => 'false'])));
$r = $list(['in_stock' => 'true', 'count' => 'true']);
pin('the total follows the filter', 8, $r->body()['meta']['total'] ?? null);

harness_section('on_sale');
pin('on_sale=true keeps rows whose was price is above the price', ['Deal', 'Sold out'], $names($list(['on_sale' => 'true'])));
pin('with in_stock too, one variant must be both', ['Deal'], $names($list(['on_sale' => 'true', 'in_stock' => 'true'])));
pin('the total follows the filter', 2, $list(['on_sale' => 'true', 'count' => 'true'])->body()['meta']['total'] ?? null);
pin('a variant with no price compares to the listing price, here above the was price', ['Sold out'], (static function () use ($admin, $p, $id, $names, $list): array {
    $admin->query("UPDATE {$p}t_item_variant SET i_price = NULL WHERE fk_i_item_id = {$id['Deal']}");
    $admin->query("UPDATE {$p}t_item SET i_price = 13000000 WHERE pk_i_id = {$id['Deal']}");

    // A different page size, as the search cache does not see a raw SQL change.
    return $names($list(['on_sale' => 'true', 'limit' => 49]));
})());
$admin->query("UPDATE {$p}t_item SET i_price = 11000000 WHERE pk_i_id = {$id['Deal']}");
pin('filters keep paging', ['Deal', 'Sold out'], (static function () use ($call, $key): array {
    $first = $call('GET', 'listings', null, $key, [], ['on_sale' => 'true', 'limit' => 1]);
    $next  = [];
    parse_str((string) parse_url((string) $first->body()['links']['next'], PHP_URL_QUERY), $next);
    $titles = array_column($first->body()['data'], 'title');
    $titles = array_merge($titles, array_column($call('GET', 'listings', null, $key, [], $next)->body()['data'], 'title'));
    sort($titles);

    return $titles;
})());
pin('a value that is not a boolean is refused', 422, $list(['on_sale' => 'maybe'])->status());

harness_section('on_sale compares the variant price first');
// title => [listing price, variant price, was price], in micros; a null variant price falls back to the listing's.
$cups = seed_category($admin, 'Cups', null, $locale);
foreach ([
    'Variant below was'   => [20000000, 10000000, 15000000],
    'Variant above was'   => [5000000, 10000000, 8000000],
    'Fallback below was'  => [9000000, null, 12000000],
    'Fallback above was'  => [14000000, null, 12000000],
] as $title => [$listing, $price, $was]) {
    $cup = seed_item($admin, $cups, $sue, $title, $listing / 1000000);
    VariantStore::saveDefault($cup, ['i_price' => $price ?? $listing, 'i_stock' => 5, 'i_compare_price' => $was]);
    if ($price === null) {
        $admin->query("UPDATE {$p}t_item_variant SET i_price = NULL WHERE fk_i_item_id = $cup");
    }
}
pin('the variant price wins over the listing price, and a missing one falls back to it', ['Fallback below was', 'Variant below was'], $names($list(['on_sale' => 'true', 'category' => (string) $cups])));

exit(harness_result());
