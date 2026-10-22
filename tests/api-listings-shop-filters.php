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
 * for every caller) and the `in_stock`, `on_sale`, `option`, `ids` and `custom_field` list filters.
 * Usage: php tests/api-listings-shop-filters.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\listing\VariantStore;
use mindstellar\search\FacetIndex;

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

// Runs a list call with the filter index off, then on; the first answer is returned when both
// agree, else a marker so the pin that asked fails. $facetSql keeps the condition text of each run.
$facetSql = [];
$bothWays = static function (callable $run) use (&$facetSql): mixed {
    $out = [];
    foreach ([false, true] as $ready) {
        $ready ? FacetIndex::markReady() : FacetIndex::markStale();
        $seen = '';
        osc_add_filter('sql_search_conditions', static function (array $c) use (&$seen): array {
            $seen = implode(' AND ', $c);

            return $c;
        }, 'facet_probe');
        $out[$ready ? 'on' : 'off'] = $run();
        osc_remove_filter('sql_search_conditions', 'facet_probe');
        $facetSql[$ready ? 'on' : 'off'] = $seen;
    }
    FacetIndex::markStale();

    return $out['off'] === $out['on'] ? $out['off'] : ['differs' => $out];
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

harness_section('option');
$tees = seed_category($admin, 'Tees', null, $locale);
// title => rows of [colour, size, stock].
$shirts = [
    'Tee'      => [['Sunflower', 'S', 5], ['Sunflower', 'M', 0], ['Cherry', 'S', 0], ['Cherry', 'M', 7]],
    'Polo'     => [['Cherry', 'L', 4], ['Navy', 'L', 2]],
    'Percent'  => [['100%', 'S', 3], ['A_B', 'S', 3]],
    'Quote'    => [['Say "hi"', 'S', 1], ['Back\\slash', 'S', 1], ['Rose', 'S', 1]],
];
$shirt = [];
foreach ($shirts as $title => $rows) {
    $shirt[$title] = seed_item($admin, $tees, $sue, $title, 10.0);
    VariantStore::saveSet($shirt[$title], array_map(static fn (array $r): array => ['options' => ['Colour' => $r[0], 'Size' => $r[1], 'Fit' => 'Slim'], 'fields' => ['i_price' => 10000000, 'i_stock' => $r[2]]], $rows));
}
$inTees = static fn (array $query): array => $bothWays(static fn (): array => $names($list($query + ['category' => (string) $tees, 'limit' => 48])));
pin('one value', ['Polo', 'Tee'], $inTees(['option' => ['Colour' => ['Cherry']]]));
pin('values of one name are OR', ['Polo', 'Tee'], $inTees(['option' => ['Colour' => ['Navy', 'Sunflower']]]));
pin('names are AND, on one variant', ['Tee'], $inTees(['option' => ['Colour' => ['Cherry'], 'Size' => ['M']]]));
pin('both values on different variants do not match', [], $inTees(['option' => ['Colour' => ['Sunflower'], 'Size' => ['L']]]));
pin('a single value may be sent without a list', ['Polo'], $inTees(['option' => ['Size' => 'L']]));
pin('with in_stock, the same variant must be in stock', ['Tee'], $inTees(['option' => ['Colour' => ['Cherry'], 'Size' => ['M']], 'in_stock' => 'true']));
pin('Sunflower M is sold out', [], $inTees(['option' => ['Colour' => ['Sunflower'], 'Size' => ['M']], 'in_stock' => 'true']));
pin('a % in a value matches only itself', ['Percent'], $inTees(['option' => ['Colour' => ['100%']]]));
pin('a bare % matches nothing', [], $inTees(['option' => ['Colour' => ['%']]]));
pin('an _ in a value matches only itself', ['Percent'], $inTees(['option' => ['Colour' => ['A_B']]]));
pin('a _ is not any one character', [], $inTees(['option' => ['Colour' => ['A_']]]));
pin('a quote in a value matches', ['Quote'], $inTees(['option' => ['Colour' => ['Say "hi"']]]));
pin('a backslash in a value matches', ['Quote'], $inTees(['option' => ['Colour' => ['Back\\slash']]]));
pin('SQL in a value matches nothing and breaks nothing', [], $inTees(['option' => ['Colour' => ["x') OR 1=1 -- "]]]));
pin('an unknown value matches nothing', [], $inTees(['option' => ['Colour' => ['Mauve']]]));
pin('a name over 30 characters is dropped, the filter is then off', ['Percent', 'Polo', 'Quote', 'Tee'], $inTees(['option' => [str_repeat('n', 31) => ['x']]]));
pin('a value over 40 characters is dropped, the filter is then off', ['Percent', 'Polo', 'Quote', 'Tee'], $inTees(['option' => ['Colour' => [str_repeat('v', 41)]]]));
pin('the 4th name is dropped, not a 500', ['Tee'], $inTees(['option' => ['Colour' => ['Cherry'], 'Size' => ['M'], 'Fit' => ['Slim'], 'Extra' => ['none']]]));
pin('values past the 10th are dropped', [], $inTees(['option' => ['Colour' => array_merge(array_map(static fn (int $i): string => 'v' . $i, range(1, 10)), ['Cherry'])]]));
pin('Cherry as the 10th value still counts', ['Polo', 'Tee'], $inTees(['option' => ['Colour' => array_merge(array_map(static fn (int $i): string => 'v' . $i, range(1, 9)), ['Cherry'])]]));
pin('an empty value is dropped, the filter is then off', ['Percent', 'Polo', 'Quote', 'Tee'], $inTees(['option' => ['Colour' => ['']]]));
pin('an empty value beside a real one is dropped', ['Polo', 'Tee'], $inTees(['option' => ['Colour' => ['', 'Cherry']]]));
pin('two names that differ by case are one name, their values OR', ['Polo', 'Tee'], $inTees(['option' => ['Colour' => ['Navy'], 'COLOUR' => ['Sunflower']]]));
pin('the 10-value cap holds after the merge', [], $inTees(['option' => ['Colour' => array_map(static fn (int $i): string => 'v' . $i, range(1, 9)), 'COLOUR' => ['v10', 'Cherry']]]));
pin('a value both names ask for is counted once toward the cap', ['Polo', 'Tee'], $inTees(['option' => [
    'Colour' => array_map(static fn (int $i): string => 'v' . $i, range(1, 9)),
    'COLOUR' => array_merge(array_map(static fn (int $i): string => 'v' . $i, range(1, 9)), ['Cherry']),
]]));
$spellings = [];
for ($i = 0; count($spellings) < 10; $i++) {
    $spellings[implode('', array_map(static fn (string $c, int $n): string => ($i >> $n) & 1 ? strtoupper($c) : $c, str_split('colour'), range(0, 5)))] = ['Cherry'];
}
pin('names past the 10th asked are dropped, even when the first ten fold into one', ['Polo', 'Tee'], $inTees(['option' => $spellings + ['Size' => ['None']]]));
pin('the 10th asked still counts', [], $inTees(['option' => array_slice($spellings, 0, 9, true) + ['Size' => ['None']]]));
pin('a name that differs by an accent is one name too, as the tables compare them', ['Polo', 'Tee'], $inTees(['option' => ['Cólour' => ['Navy'], 'COLOUR' => ['Sunflower']]]));
$legs = seed_category($admin, 'Legwear', null, $locale);
VariantStore::saveSet(seed_item($admin, $legs, $sue, 'Jeans', 10.0), [['options' => ['Waist Size' => 'M'], 'fields' => ['i_stock' => 1]]]);
VariantStore::saveSet(seed_item($admin, $legs, $sue, 'Shorts', 10.0), [['options' => ['Size' => 'M'], 'fields' => ['i_stock' => 1]]]);
pin('a name matches whole, not the end of a longer name', ['Shorts'], $bothWays(static fn (): array => $names($list(['option' => ['Size' => ['M']], 'category' => (string) $legs]))));
VariantStore::saveSet(seed_item($admin, $legs, $sue, 'Numbered', 10.0), [['options' => ['12' => 'M'], 'fields' => ['i_stock' => 1]]]);
pin('a number-like name (an int key in PHP) matches', ['Numbered'], $bothWays(static fn (): array => $names($list(['option' => ['12' => ['M']], 'category' => (string) $legs]))));
pin('a listing with no option rows never matches', [], $bothWays(static fn (): array => $names($list(['option' => ['Colour' => ['Red']], 'category' => (string) $cat]))));
pin('the total follows the filter', 2, $bothWays(static fn (): mixed => $list(['option' => ['Colour' => ['Cherry']], 'category' => (string) $tees, 'count' => 'true'])->body()['meta']['total'] ?? null));
$pairs = seed_category($admin, 'Pairs', null, $locale);
pin('Colour=Red and Size=M on one variant, Red/L is not a match', [['Tee'], []], (static function () use ($admin, $pairs, $sue, $bothWays, $names, $list): array {
    $rb = seed_item($admin, $pairs, $sue, 'Red Blue', 10.0);
    VariantStore::saveSet($rb, [
        ['options' => ['Colour' => 'Red', 'Size' => 'M'], 'fields' => ['i_stock' => 1]],
        ['options' => ['Colour' => 'Blue', 'Size' => 'L'], 'fields' => ['i_stock' => 1]],
    ]);
    $ask = static fn (array $o): array => $bothWays(static fn (): array => $names($list(['option' => $o, 'category' => (string) $pairs])));

    return [$ask(['Colour' => ['Red'], 'Size' => ['M']]) === ['Red Blue'] ? ['Tee'] : 'wrong', $ask(['Colour' => ['Red'], 'Size' => ['L']])];
})());
pin('option and in_stock need one variant for both: Red is sold out, Blue is in stock', [[], ['Stocky']], (static function () use ($admin, $pairs, $sue, $bothWays, $names, $list): array {
    $it = seed_item($admin, $pairs, $sue, 'Stocky', 10.0);
    VariantStore::saveSet($it, [
        ['options' => ['Hue' => 'Red'], 'fields' => ['i_stock' => 0]],
        ['options' => ['Hue' => 'Blue'], 'fields' => ['i_stock' => 4]],
    ]);
    $ask = static fn (string $hue): array => $bothWays(static fn (): array => $names($list(['option' => ['Hue' => [$hue]], 'in_stock' => 'true', 'category' => (string) $pairs])));

    return [$ask('Red'), $ask('Blue')];
})());
pin('the compiled SQL names the filter index only when it is ready', [false, true], (static function () use ($bothWays, $list, $facetSql, $tees): array {
    $bothWays(static fn (): mixed => $list(['option' => ['Colour' => ['Cherry']], 'category' => (string) $tees]));
    global $facetSql;

    return [str_contains($facetSql['off'], 'item_facet'), str_contains($facetSql['on'], 'item_facet')];
})());
pin('in_stock alone never reads the filter index', false, (static function () use ($bothWays, $list): bool {
    $bothWays(static fn (): mixed => $list(['in_stock' => 'true']));
    global $facetSql;

    return str_contains($facetSql['on'], 'item_facet');
})());
pin('option filters keep paging', ['Polo', 'Tee'], (static function () use ($call, $key, $tees): array {
    $q     = ['option' => ['Colour' => ['Cherry']], 'category' => (string) $tees, 'limit' => 1];
    $first = $call('GET', 'listings', null, $key, [], $q);
    $next  = [];
    parse_str((string) parse_url((string) $first->body()['links']['next'], PHP_URL_QUERY), $next);
    $titles = array_merge(array_column($first->body()['data'], 'title'), array_column($call('GET', 'listings', null, $key, [], $next)->body()['data'], 'title'));
    sort($titles);

    return $titles;
})());
pin('option filters work with page numbers', ['Polo', 'Tee'], (static function () use ($call, $key, $tees): array {
    $titles = [];
    foreach ([1, 2] as $page) {
        $titles = array_merge($titles, array_column($call('GET', 'listings', null, $key, [], ['option' => ['Colour' => ['Cherry']], 'category' => (string) $tees, 'limit' => 1, 'page' => $page])->body()['data'], 'title'));
    }
    sort($titles);

    return $titles;
})());

harness_section('ids');
pin('ids lists those listings', ['Deal', 'Low'], $names($list(['ids' => $id['Low'] . ',' . $id['Deal']])));
pin('ids may be sent as a list', ['Deal', 'Low'], $names($list(['ids' => [(string) $id['Low'], (string) $id['Deal']]])));
$ordered = static fn (string $order): array => array_column($list(['ids' => $id['Low'] . ',' . $id['Deal'], 'sort' => 'created', 'order' => $order])->body()['data'], 'title');
pin('the sort is the one asked for', [['Low', 'Deal'], ['Deal', 'Low']], [$ordered('asc'), $ordered('desc')]);
pin('an id that is not a listing adds nothing', ['Low'], $names($list(['ids' => $id['Low'] . ',999999'])));
pin('ids with no valid id match nothing', [], $names($list(['ids' => 'abc,-1'])));
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = {$id['Deal']}");
pin('a disabled listing stays out', ['Low'], $names($list(['ids' => $id['Low'] . ',' . $id['Deal'], 'limit' => 48])));
$admin->query("UPDATE {$p}t_item SET b_enabled = 1 WHERE pk_i_id = {$id['Deal']}");
$many = implode(',', array_merge([$id['Low']], range(900000, 900060)));
pin('past 50 ids, the extra are dropped', ['Low'], $names($list(['ids' => $many])));
$tail = implode(',', array_merge(range(900000, 900049), [$id['Low']]));
pin('the 51st id is not read', [], $names($list(['ids' => $tail, 'limit' => 47])));
pin('ids paging by cursor', ['Deal', 'Low'], (static function () use ($call, $key, $id): array {
    $first = $call('GET', 'listings', null, $key, [], ['ids' => $id['Low'] . ',' . $id['Deal'], 'limit' => 1]);
    $next  = [];
    parse_str((string) parse_url((string) $first->body()['links']['next'], PHP_URL_QUERY), $next);
    $titles = array_merge(array_column($first->body()['data'], 'title'), array_column($call('GET', 'listings', null, $key, [], $next)->body()['data'], 'title'));
    sort($titles);

    return $titles;
})());

harness_section('custom_field lists');
$field = seed_meta_field($admin, 'Material', 'DROPDOWN', $tees);
foreach (['Tee' => 'Cotton', 'Polo' => 'Linen', 'Percent' => 'Wool'] as $title => $value) {
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, '')", 'iis', [$shirt[$title], $field, $value]);
}
foreach ($shirt as $listingId) {
    FacetIndex::buildStep($listingId - 1, 1);
}
$byField = static fn (mixed $value): array => $inTees(['custom_field' => [(string) $field => $value]]);
pin('the scalar form still works', ['Tee'], $byField('Cotton'));
pin('a list is OR', ['Polo', 'Tee'], $byField(['Cotton', 'Linen']));
pin('a list of one is the same as the scalar', ['Tee'], $byField(['Cotton']));
pin('empty and repeated values are ignored', ['Tee'], $byField(['', 'Cotton', 'Cotton']));
pin('an empty list is no filter', ['Percent', 'Polo', 'Quote', 'Tee'], $byField(['']));
pin('a value with a quote does not break the query', [], $byField(["Cot'ton", 'x" OR "1"="1']));
pin('values past the 20th are dropped', [], $byField(array_merge(array_map(static fn (int $i): string => 'v' . $i, range(1, 20)), ['Cotton'])));
pin('a list is OR with paging', ['Polo', 'Tee'], (static function () use ($call, $key, $tees, $field): array {
    $q     = ['custom_field' => [(string) $field => ['Cotton', 'Linen']], 'category' => (string) $tees, 'limit' => 1];
    $first = $call('GET', 'listings', null, $key, [], $q);
    $next  = [];
    parse_str((string) parse_url((string) $first->body()['links']['next'], PHP_URL_QUERY), $next);
    $titles = array_merge(array_column($first->body()['data'], 'title'), array_column($call('GET', 'listings', null, $key, [], $next)->body()['data'], 'title'));
    sort($titles);

    return $titles;
})());
pin('the response still matches the schema', [], api_admin_schema_errors('ListingPage', $call('GET', 'listings', null, $key, [], ['option' => ['Colour' => ['Cherry']], 'category' => (string) $tees])));

exit(harness_result());
