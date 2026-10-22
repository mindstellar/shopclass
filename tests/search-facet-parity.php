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
 * Every option and custom-field filter gives the same listings and the same total whether the
 * filter index is off or on, apart from three accepted differences that are pinned on their own:
 * a value past 191 characters matches on its first 191, a NUMBER that is not a number matches no
 * range, and a stored value with leading spaces matches trimmed.
 * Usage: php tests/search-facet-parity.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\listing\VariantStore;
use mindstellar\search\FacetIndex;
use mindstellar\search\SearchBuilder;
use mindstellar\search\SearchCriteria;

$admin = api_admin_boot('osc_search_facet_parity');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Parity', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0',
    'defaultResultsPerPage@search' => '50', 'maxResultsPerPage@search' => '50', 'defaultOrderField@search' => 'dt_pub_date', 'defaultOrderType@search' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

$field = static function (string $name, string $type) use ($admin, $p, $cat): int {
    $id = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position) VALUES (?, ?, ?, 0, 1, 0)", 'sss', [$name, strtolower($name), $type]);
    seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', [$cat, $id]);

    return $id;
};
$f = [
    'dd'   => $field('Material', 'DROPDOWN'),
    'rd'   => $field('Fit', 'RADIO'),
    'cb1'  => $field('Gift', 'CHECKBOX'),
    'cb2'  => $field('Eco', 'CHECKBOX'),
    'cb3'  => $field('Vegan', 'CHECKBOX'),
    'num'  => $field('Weight', 'NUMBER'),
    'date' => $field('Made', 'DATE'),
    'di'   => $field('Stay', 'DATEINTERVAL'),
    'long' => $field('Long', 'DROPDOWN'),
    'num2' => $field('Odd', 'NUMBER'),
    'sp'   => $field('Spaced', 'DROPDOWN'),
];
$long192 = str_repeat('a', 191) . 'b';
$day     = 1700000000 - (1700000000 % 86400) + 43200;
$t0      = 1700000000;

// title => [price, meta by field key, [colour, size, stock, was price, has own price] rows]
$spec = [
    'A' => [10, ['dd' => 'Red', 'rd' => 'Slim', 'cb1' => '1', 'cb2' => '1', 'cb3' => '1', 'num' => '12.5', 'date' => $day, 'di' => [100, 200]],
        [['Red', 'M', 1, 20000000], ['Blue', 'L', 1, null]]],
    'B' => [20, ['dd' => 'red', 'rd' => 'Slim', 'cb1' => '1', 'cb2' => '1', 'cb3' => '0', 'num' => '9', 'date' => $day + 86400 * 3, 'di' => [150, 250]],
        [['Red', 'S', 2, null]]],
    'C' => [30, ['dd' => 'Rèd', 'rd' => 'Loose', 'cb1' => '0', 'cb2' => '1', 'cb3' => '1', 'num' => '10', 'date' => $day + 3600, 'di' => [120, 180]],
        [['Blue', 'M', 0, null]]],
    'D' => [40, ['dd' => '', 'cb1' => '1', 'num' => '20'],
        [['Red', 'M', 0, 50000000]]],
    'E' => [50, ['dd' => 'Blue', 'num' => '25', 'di' => [100, 300]], []],
    'F' => [60, [], []],
    'G' => [70, ['long' => $long192], []],
    'S' => [80, ['sp' => ' Red'], []],
    'N' => [90, ['num2' => 'abc'], []],
    'T' => [95, ['num2' => '3'], []],
    'H' => [100, [], [['Red', 'XS', null, null]]],
    'I' => [110, [], [['Red', 'XS', 1, 110000000]]],
    'J' => [120, [], [['Red', 'XS', 1, 150000000, false]]],
];
foreach ($spec as $title => [$price, $meta, $rows]) {
    $id = seed_item($admin, $cat, $sue, $title, (float) $price);
    foreach ($meta as $key => $value) {
        $pairs = $key === 'di' ? [['from', (string) ($t0 + $value[0])], ['to', (string) ($t0 + $value[1])]] : [['', (string) $value]];
        foreach ($pairs as [$multi, $stored]) {
            seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, ?)", 'iiss', [$id, $f[$key], $stored, $multi]);
        }
    }
    if ($rows !== []) {
        VariantStore::saveSet($id, array_map(static fn (array $r): array => [
            'options' => ['Colour' => $r[0], 'Size' => $r[1]],
            'fields'  => ['i_price' => ($r[4] ?? true) ? $price * 1000000 : null, 'i_stock' => $r[2], 'i_compare_price' => $r[3]],
        ], $rows));
    }
    FacetIndex::rewriteItem($id);
}

$key  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$call = api_admin_caller();

$facetSql = [];
// Titles of the listings one stored condition text matches, read on its own against t_item.
$matching = static function (string $where) use ($admin, $p): array {
    $titles = [];
    $res    = $admin->query("SELECT s_title FROM {$p}t_item_description d JOIN {$p}t_item ON pk_i_id = d.fk_i_item_id WHERE {$where}");
    if ($res === false) {
        return ['sql failed: ' . $admin->error];
    }
    foreach ($res->fetch_all(MYSQLI_NUM) as [$title]) {
        $titles[(string) $title] = (string) $title;
    }
    sort($titles);

    return array_values($titles);
};
// Runs a search with the filter index off and on. Besides the listings and the total of the search
// itself, the conditions it ran are read back: the page text and the count text, each as a match list.
$run = static function (callable $search) use ($matching, &$facetSql): array {
    $out = [];
    foreach ([false, true] as $ready) {
        $ready ? FacetIndex::markReady() : FacetIndex::markStale();
        scratchdb_forget_cache();
        $seen = [];
        osc_add_filter('sql_search_conditions', static function (array $c) use (&$seen): array {
            $seen[] = implode(' AND ', $c);

            return $c;
        }, 'parity_probe');
        $result = $search();
        osc_remove_filter('sql_search_conditions', 'parity_probe');
        $pageSql  = $seen[0] ?? '';
        $countSql = $seen[count($seen) - 1] ?? '';
        $facetSql[$ready ? 'on' : 'off'] = ['page' => $pageSql, 'count' => $countSql];
        $name = $ready ? 'on' : 'off';
        $out[$name]            = $result;
        $out[$name . ' page']  = $pageSql === '' ? 'no condition' : $matching($pageSql);
        $out[$name . ' count'] = $countSql === '' ? 'no condition' : $matching($countSql);
    }
    FacetIndex::markStale();

    return $out;
};
// What every run must give: the same listings and total from the search, and the same listings
// from the page text and the count text on their own, with the index off and on. $conditions is
// what the conditions match alone when the search has other parts (price, stock), or 'no condition'.
$expect = static function (array $titles, array|string|null $conditions = null): array {
    $match = $conditions ?? $titles;
    $out   = [];
    foreach (['off', 'on'] as $name) {
        $out[$name]            = [$titles, count($titles)];
        $out[$name . ' page']  = $match;
        $out[$name . ' count'] = $match;
    }

    return $out;
};
// Titles and total of one list call.
$ask = static function (array $query) use ($call, $key, $cat, $run): array {
    return $run(static function () use ($call, $key, $cat, $query): array|string {
        $r = $call('GET', 'listings', null, $key, [], $query + ['category' => (string) $cat, 'limit' => 48, 'count' => 'true']);
        $titles = array_column($r->body()['data'] ?? [], 'title');
        sort($titles);

        return $r->status() === 200 ? [$titles, $r->body()['meta']['total'] ?? null] : 'status ' . $r->status();
    });
};
// Pins that every run gives the expected titles and a total equal to their count.
$same = static function (string $name, array $query, array $titles, array|string|null $conditions = null) use ($ask, $expect): void {
    pin($name, $expect($titles, $conditions), $ask($query));
};
$onlyNew = static function (array $query) use ($ask): array {
    return $ask($query)['on'];
};
// The search page path, for the range filters the API does not take.
$askPage = static function (array $meta) use ($cat, $run): array {
    return $run(static function () use ($cat, $meta): array {
        $s = new Search();
        SearchBuilder::apply(SearchCriteria::fromRequest(['sCategory' => [(string) $cat], 'meta' => $meta]), $s);
        $titles = array_map(static fn (array $row): string => (string) $row['s_title'], (array) $s->doSearch());
        sort($titles);

        return [$titles, $s->countMatches()];
    });
};
$page = static function (string $name, array $meta, array $titles) use ($askPage, $expect): void {
    pin($name, $expect($titles), $askPage($meta));
};
$cf = static fn (string $k, mixed $v): array => ['custom_field' => [(string) $f[$k] => $v]];
$opt = static fn (array $o): array => ['option' => $o];

harness_section('options');
$same('no filter lists all', [], ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'N', 'S', 'T'], 'no condition');
$same('one name', $opt(['Colour' => ['Red']]), ['A', 'B', 'D', 'H', 'I', 'J']);
$same('one name, two values', $opt(['Colour' => ['Red', 'Blue']]), ['A', 'B', 'C', 'D', 'H', 'I', 'J']);
$same('two names on one variant', $opt(['Colour' => ['Red'], 'Size' => ['M']]), ['A', 'D']);
$same('Red and L are on two variants of one listing', $opt(['Colour' => ['Red'], 'Size' => ['L']]), []);
$same('Blue and L are one variant', $opt(['Colour' => ['Blue'], 'Size' => ['L']]), ['A']);
$same('a case variant folds', $opt(['Colour' => ['red']]), ['A', 'B', 'D', 'H', 'I', 'J']);
$same('with price', $opt(['Colour' => ['Red']]) + ['price_min' => 15, 'price_max' => 45], ['B', 'D'], ['A', 'B', 'D', 'H', 'I', 'J']);
$same('with in_stock', $opt(['Colour' => ['Red']]) + ['in_stock' => 'true'], ['A', 'B', 'H', 'I', 'J']);
$same('with on_sale', $opt(['Colour' => ['Red']]) + ['on_sale' => 'true'], ['A', 'D', 'J']);
$same('with in_stock and on_sale on one variant', $opt(['Colour' => ['Red']]) + ['in_stock' => 'true', 'on_sale' => 'true'], ['A', 'J']);
$same('in_stock alone', ['in_stock' => 'true'], ['A', 'B', 'H', 'I', 'J']);
$same('an unknown value', $opt(['Colour' => ['Mauve']]), []);

harness_section('dropdown and radio');
$same('one value folds case and accent, the empty one is not it', $cf('dd', 'Red'), ['A', 'B', 'C']);
$same('several values', $cf('dd', ['Red', 'Blue']), ['A', 'B', 'C', 'E']);
$same('an empty value is dropped', $cf('dd', ['', 'Blue']), ['E']);
$same('a radio value', $cf('rd', 'Slim'), ['A', 'B']);
$same('a value that is not stored', $cf('dd', 'Mauve'), []);
$same('a 192-character value matches itself', $cf('long', $long192), ['G']);
$same('two fields are AND', ['custom_field' => [(string) $f['dd'] => ['Red', 'Blue'], (string) $f['rd'] => 'Slim']], ['A', 'B']);

harness_section('checkbox');
$same('one box', $cf('cb1', '1'), ['A', 'B', 'D']);
$same('a box left unticked is not ticked', $cf('cb3', '1'), ['A', 'C']);
$same('three boxes', ['custom_field' => [(string) $f['cb1'] => '1', (string) $f['cb2'] => '1', (string) $f['cb3'] => '1']], ['A']);

harness_section('number, date and interval');
$page('a number range keeps the ends and a decimal', [(string) $f['num'] => ['from' => '10', 'to' => '20']], ['A', 'C', 'D']);
$page('a number range keeps a value equal to its upper end', [(string) $f['num'] => ['from' => '9', 'to' => '9']], ['B']);
$page('a number range drops a value just above its upper end', [(string) $f['num'] => ['from' => '10', 'to' => '19.99']], ['A', 'C']);
$page('a range with no row in it', [(string) $f['num'] => ['from' => '100', 'to' => '200']], []);
$page('a negative lower bound', [(string) $f['num'] => ['from' => '-5', 'to' => '9']], ['B']);
$same('a date matches its whole day', $cf('date', (string) $day), ['A', 'C']);
$same('another day', $cf('date', (string) ($day + 86400 * 3)), ['B']);
$page('an interval is covered', [(string) $f['di'] => ['from' => (string) ($t0 + 130), 'to' => (string) ($t0 + 170)]], ['A', 'C', 'E']);
$page('a stored interval that ends before the asked end does not cover it', [(string) $f['di'] => ['from' => (string) ($t0 + 130), 'to' => (string) ($t0 + 260)]], ['E']);
$page('a stored interval that starts after the asked start does not cover it', [(string) $f['di'] => ['from' => (string) ($t0 + 110), 'to' => (string) ($t0 + 170)]], ['A', 'E']);
$page('an interval may start exactly where a stored one starts', [(string) $f['di'] => ['from' => (string) ($t0 + 100), 'to' => (string) ($t0 + 150)]], ['A', 'E']);
$page('an interval may end exactly where a stored one ends', [(string) $f['di'] => ['from' => (string) ($t0 + 200), 'to' => (string) ($t0 + 250)]], ['B', 'E']);
$page('an interval equal to a stored one is covered', [(string) $f['di'] => ['from' => (string) ($t0 + 150), 'to' => (string) ($t0 + 250)]], ['B', 'E']);
$page('an interval inside the stored one only', [(string) $f['di'] => ['from' => (string) ($t0 + 90), 'to' => (string) ($t0 + 170)]], []);

harness_section('the three accepted differences');
$newOnly = static fn (array $r): array => [$r['on'], $r['on page'], $r['on count']];
pin('a value past 191 characters matches on its first 191', [[['G'], 1], ['G'], ['G']], $newOnly($ask($cf('long', str_repeat('a', 191) . 'c'))));
pin('a NUMBER that is not a number matches no range', [[['T'], 1], ['T'], ['T']], $newOnly($askPage([(string) $f['num2'] => ['from' => '-1', 'to' => '5']])));
pin('a stored value with a leading space matches trimmed', [[['S'], 1], ['S'], ['S']], $newOnly($ask($cf('sp', 'Red'))));

harness_section('terms');
$termCount = static fn (string $name): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_facet_term WHERE s_key = 'f{$f[$name]}'")->fetch_row()[0];
pin('Red, red and Rèd are one term and Blue another', 2, $termCount('dd'));
pin('a unknown value is no term', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_facet_term WHERE s_value = 'Mauve'")->fetch_row()[0]);

harness_section('page and count text');
$texts = static function (array $query) use ($ask, &$facetSql): array {
    $ask($query);

    return [$facetSql['off'], $facetSql['on']];
};
[$off, $on] = $texts($opt(['Colour' => ['Red']]));
pin('an option page probes the listing, the count reads the facet range once', [true, true, true], [
    str_contains($on['page'], 'LIMIT 1) IS NOT NULL'), str_contains($on['count'], 'pk_i_id IN (SELECT'), !str_contains($on['count'], 'LIMIT 1')]);
pin('the old text is the same for page and count', true, $off['page'] === $off['count']);
[$off, $on] = $texts($cf('cb1', '1'));
pin('a checkbox page probes, the count reads the range', [true, true], [str_contains($on['page'], 'LIMIT 1) IS NOT NULL'), str_contains($on['count'], 'IN (SELECT')]);
foreach (['a dropdown with one value' => $cf('dd', 'Red'), 'a dropdown with several values' => $cf('dd', ['Red', 'Blue']), 'a radio with one value' => $cf('rd', 'Slim'), 'a radio with several values' => $cf('rd', ['Slim', 'Loose'])] as $label => $query) {
    [$off, $on] = $texts($query);
    pin("$label: the page probes, the count reads the range", [true, true, true], [
        str_contains($on['page'], 'LIMIT 1) IS NOT NULL'), str_contains($on['count'], 'pk_i_id IN (SELECT fk_i_item_id'), !str_contains($on['count'], 'LIMIT 1')]);
}
[$off, $on] = $texts($cf('dd', 'Mauve'));
pin('an unknown value is no row in either text and reads no table', ['1 = 0', '1 = 0'], [trim($on['page']), trim($on['count'])]);
$askPage([(string) $f['num'] => ['from' => '10', 'to' => '20']]);
pin('a range reads the range once on the page and for the count', [true, true], [str_contains($facetSql['on']['page'], 'IN (SELECT') && !str_contains($facetSql['on']['page'], 'LIMIT 1'), str_contains($facetSql['on']['count'], 'IN (SELECT') && !str_contains($facetSql['on']['count'], 'LIMIT 1')]);

harness_section('which table is read');
$ask($opt(['Colour' => ['Red']]) + ['limit' => 47]);
pin('an option filter names the index only when it is ready', [false, true], [str_contains($facetSql['off']['page'] . $facetSql['off']['count'], 'item_facet'), str_contains($facetSql['on']['page'], 'item_facet') && str_contains($facetSql['on']['count'], 'item_facet')]);
$ask($cf('dd', 'Red') + ['limit' => 47]);
pin('a field filter names the index only when it is ready', [false, true], [str_contains($facetSql['off']['page'] . $facetSql['off']['count'], 'item_facet'), str_contains($facetSql['on']['page'], 'item_facet') && str_contains($facetSql['on']['count'], 'item_facet')]);

exit(harness_result());
