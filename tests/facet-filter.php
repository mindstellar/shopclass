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
 * The search conditions on the listing filter index by term id: the exact page and count SQL of
 * each shape, option SQL with term ids only as `?` (so Search::addCondition() takes it), field
 * SQL with ints only, NONE for an empty term list; then each shape runs on the scratch database
 * both ways, matches the listings it should, and EXPLAIN reads t_item_facet by an index
 * (PRIMARY or idx_item), never whole, with the page probes as ref/eq_ref lookups.
 *
 * Usage: php tests/facet-filter.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\search\FacetIndex;
use mindstellar\search\query\FacetFilter;

$admin = api_admin_boot('osc_facet_filter');
$p     = DB_TABLE_PREFIX;
$f     = $p . 't_item_facet';
$t     = $p . 't_facet_term';
$i     = $p . 't_item';
$v     = $p . 't_item_variant';

harness_section('option SQL');
pin(
    'one option, one value',
    [
        "(SELECT 1 FROM $f a WHERE a.fk_i_term_id = ? AND a.d_number = 0 AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL",
        "$i.pk_i_id IN (SELECT a.fk_i_item_id FROM $f a WHERE a.fk_i_term_id = ? AND a.d_number = 0)",
        [101],
    ],
    FacetFilter::optionProbe(['oColour' => [101]], false, false)
);
pin(
    'one option, two values',
    [
        "(SELECT 1 FROM $f a WHERE a.fk_i_term_id IN (?, ?) AND a.d_number = 0 AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL",
        "$i.pk_i_id IN (SELECT a.fk_i_item_id FROM $f a WHERE a.fk_i_term_id IN (?, ?) AND a.d_number = 0)",
        [101, 102],
    ],
    FacetFilter::optionProbe(['oColour' => [101, 102]], false, false)
);
$join = "JOIN $f b ON b.fk_i_term_id = ? AND b.d_number = 0 AND b.fk_i_item_id = a.fk_i_item_id AND b.fk_i_variant_id = a.fk_i_variant_id";
pin(
    'two options join on the same variant; values follow the order of the marks',
    [
        "(SELECT 1 FROM $f a $join WHERE a.fk_i_term_id = ? AND a.d_number = 0 AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL",
        "$i.pk_i_id IN (SELECT a.fk_i_item_id FROM $f a $join WHERE a.fk_i_term_id = ? AND a.d_number = 0)",
        [112, 101],
    ],
    FacetFilter::optionProbe(['oColour' => [101], 'oSize' => [112]], false, false)
);
$joins = "JOIN $f b ON b.fk_i_term_id IN (?, ?) AND b.d_number = 0 AND b.fk_i_item_id = a.fk_i_item_id AND b.fk_i_variant_id = a.fk_i_variant_id"
    . " JOIN $f c ON c.fk_i_term_id = ? AND c.d_number = 0 AND c.fk_i_item_id = a.fk_i_item_id AND c.fk_i_variant_id = a.fk_i_variant_id"
    . " JOIN $v v ON v.pk_i_id = a.fk_i_variant_id";
$flags = "(v.i_stock IS NULL OR v.i_stock > 0) AND v.i_compare_price > COALESCE(v.i_price, $i.i_price)";
pin(
    'three options, in_stock and on_sale',
    [
        "(SELECT 1 FROM $f a $joins WHERE a.fk_i_term_id = ? AND a.d_number = 0 AND a.fk_i_item_id = $i.pk_i_id AND $flags LIMIT 1) IS NOT NULL",
        "$i.pk_i_id IN (SELECT a.fk_i_item_id FROM $f a $joins WHERE a.fk_i_term_id = ? AND a.d_number = 0 AND $flags)",
        [112, 113, 120, 101],
    ],
    FacetFilter::optionProbe(['oColour' => [101], 'oSize' => [112, 113], 'oMaterial' => [120]], true, true)
);
pin(
    'option and in_stock',
    [
        "(SELECT 1 FROM $f a JOIN $v v ON v.pk_i_id = a.fk_i_variant_id WHERE a.fk_i_term_id = ? AND a.d_number = 0 AND a.fk_i_item_id = $i.pk_i_id AND (v.i_stock IS NULL OR v.i_stock > 0) LIMIT 1) IS NOT NULL",
        "$i.pk_i_id IN (SELECT a.fk_i_item_id FROM $f a JOIN $v v ON v.pk_i_id = a.fk_i_variant_id WHERE a.fk_i_term_id = ? AND a.d_number = 0 AND (v.i_stock IS NULL OR v.i_stock > 0))",
        [101],
    ],
    FacetFilter::optionProbe(['oColour' => [101]], true, false)
);
pin('a name with no term is NONE both ways', [FacetFilter::NONE, FacetFilter::NONE, []], FacetFilter::optionProbe(['oColour' => [101], 'oSize' => []], true, false));
[$sql, $count, $params] = FacetFilter::optionProbe(['oColour' => [1, 2], 'oSize' => [3], 'oMaterial' => [4, 5]], true, true);
check('no quote character in the option SQL', strpbrk($sql . $count, "'\"\\`") === false, $sql);
pin('as many marks as values, page and count', [5, 5], [substr_count($sql, '?'), substr_count($count, '?')]);
foreach ([[[], 'no name'], [['A' => [1], 'B' => [1], 'C' => [1], 'D' => [1]], 'a fourth name']] as [$bad, $label]) {
    try {
        FacetFilter::optionProbe($bad, false, false);
        check("$label is refused", false);
    } catch (InvalidArgumentException $e) {
        check("$label is refused", true);
    }
}
$search = new Search();
try {
    $search->addCondition($sql, $params, $count);
    $conditions = json_decode($search->toJson(), true)['no_catched_conditions'];
    check('Search::addCondition() takes the option probe with its count text', str_contains((string) end($conditions), 'b.fk_i_term_id = 3 AND') && str_contains((string) end($conditions), 'c.fk_i_term_id IN (4, 5) AND') && str_contains((string) end($conditions), 'WHERE a.fk_i_term_id IN (1, 2) AND'), (string) end($conditions));
} catch (InvalidArgumentException $e) {
    check('Search::addCondition() takes the option probe with its count text', false, $e->getMessage());
}

harness_section('field SQL');
$probe = static fn (string $match): string => "(SELECT 1 FROM $f WHERE fk_i_term_id $match AND d_number = 0 AND fk_i_item_id = $i.pk_i_id AND fk_i_variant_id = 0 LIMIT 1) IS NOT NULL";
$in    = static fn (string $match): string => "$i.pk_i_id IN (SELECT fk_i_item_id FROM $f WHERE fk_i_term_id $match AND d_number = 0)";
pin('dropdown, one term is =', [$probe('= 41'), $in('= 41')], FacetFilter::fieldIn([41]));
pin('dropdown, several terms', [$probe('IN (41, 42)'), $in('IN (41, 42)')], FacetFilter::fieldIn([41, 42]));
pin('dropdown, no term is NONE both ways', [FacetFilter::NONE, FacetFilter::NONE], FacetFilter::fieldIn([]));
$range = "fk_i_term_id = 16 AND d_number >= 10 AND d_number <= 20";
pin(
    'number range, probe for the page and IN for the count',
    ["(SELECT 1 FROM $f WHERE $range AND fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL", "$i.pk_i_id IN (SELECT fk_i_item_id FROM $f WHERE $range)"],
    FacetFilter::fieldRange(16, 10.0, 20.0)
);
pin(
    'a decimal bound keeps its digits',
    "$i.pk_i_id IN (SELECT fk_i_item_id FROM $f WHERE fk_i_term_id = 16 AND d_number >= 0.1 AND d_number <= 1234567890123.456)",
    FacetFilter::fieldRange(16, 0.1, 1234567890123.456)[1]
);
pin('a big bound reads back as the same double', 1.0E+25, (float) explode('d_number <= ', FacetFilter::fieldRange(16, 0, 1.0E+25)[1])[1]);
$covers = "FROM $f a JOIN $f b ON b.fk_i_term_id = 182 AND b.fk_i_item_id = a.fk_i_item_id AND b.d_number >= 1700000000"
    . ' WHERE a.fk_i_term_id = 181 AND a.d_number <= 1690000000';
pin(
    'date interval covers, probe for the page and IN for the count',
    ["(SELECT 1 $covers AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL", "$i.pk_i_id IN (SELECT a.fk_i_item_id $covers)"],
    FacetFilter::fieldCovers(181, 182, 1690000000, 1700000000)
);
foreach (['an infinite bound' => static fn () => FacetFilter::fieldRange(16, 0, INF), 'a NaN bound' => static fn () => FacetFilter::fieldRange(16, NAN, 1)] as $label => $call) {
    try {
        $call();
        check("$label is refused", false);
    } catch (InvalidArgumentException $e) {
        check("$label is refused", true);
    }
}
foreach (['fieldIn' => FacetFilter::fieldIn([41, 42]), 'fieldRange' => FacetFilter::fieldRange(16, 0.5, 2), 'fieldCovers' => FacetFilter::fieldCovers(1, 2, 3, 4)] as $label => $pair) {
    check("$label: no quote and no mark", strpbrk(implode(' ', $pair), "'\"\\`?") === false, implode(' | ', $pair));
}

harness_section('on the database');
$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Shirts', seed_category($admin, 'Clothes', null, $locale), $locale);
seed_item($admin, $cat, null, 'Shirt', 10.0);
$cols = implode(', ', array_column($admin->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$i' AND COLUMN_NAME <> 'pk_i_id' ORDER BY ORDINAL_POSITION"
)->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME'));
$total = 400;
while (($have = (int) $admin->query("SELECT COUNT(*) FROM $i")->fetch_row()[0]) < $total) {
    $admin->query("INSERT INTO $i ($cols) SELECT $cols FROM $i LIMIT " . ($total - $have));
}
$ids = array_map('intval', array_column($admin->query("SELECT pk_i_id FROM $i ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC), 'pk_i_id'));

// Listing k: dropdown f12 one of ten values, f7 ticked when k % 3 = 0, number f9 = k % 50,
// interval f5 from 1000 + k to 2000 + k, twenty other fields as bulk, and two variants:
// Red/M/Cotton (even k) or Blue/S, and Blue/L. The first is out of stock when k % 4 = 0
// and on sale when k % 5 = 0.
$texts    = ['Red', 'Blue', 'Green', '12', 'V4', 'V5', 'V6', 'V7', 'V8', 'V9'];
$facets   = [];
$variants = [];
foreach ($ids as $k => $id) {
    $facets[] = ['f12', $texts[$k % 10], $id, 0, null];
    if ($k % 3 === 0) {
        $facets[] = ['f7', '1', $id, 0, null];
    }
    $facets[] = ['f9', (string) ($k % 50), $id, 0, (float) ($k % 50)];
    $facets[] = ['f5.from', (string) (1000 + $k), $id, 0, (float) (1000 + $k)];
    $facets[] = ['f5.to', (string) (2000 + $k), $id, 0, (float) (2000 + $k)];
    for ($n = 100; $n < 120; $n++) {
        $facets[] = ['f' . $n, 'W' . (($k + $n) % 7), $id, 0, (float) $k];
    }
    $first      = $k % 2 === 0 ? ['Colour' => 'Red', 'Size' => 'M', 'Material' => 'Cotton'] : ['Colour' => 'Blue', 'Size' => 'S'];
    $variants[] = [2 * $k + 1, $id, $first, $k % 4 === 0 ? 0 : 5, $k % 5 === 0 ? 2000 : null];
    $variants[] = [2 * $k + 2, $id, ['Colour' => 'Blue', 'Size' => 'L'], null, null];
}
foreach ($variants as [$vid, $id, $options, $stock, $compare]) {
    foreach ($options as $name => $value) {
        $facets[] = [FacetIndex::optionKey($name), $value, $id, $vid, null];
    }
}
foreach (array_chunk($variants, 200) as $chunk) {
    $admin->query("INSERT INTO $v (pk_i_id, fk_i_item_id, s_options, i_price, i_compare_price, i_stock) VALUES " . implode(', ', array_map(
        static fn (array $r): string => sprintf("(%d, %d, '%s', 1000, %s, %s)", $r[0], $r[1], $admin->real_escape_string(json_encode($r[2])), $r[4] ?? 'NULL', $r[3] ?? 'NULL'),
        $chunk
    )));
}
$termOf = [];
foreach ($facets as $r) {
    $termOf[$r[0] . "\0" . ($r[4] === null ? $r[1] : '')] = [$r[0], $r[4] === null ? $r[1] : ''];
}
foreach (array_chunk(array_values($termOf), 500) as $chunk) {
    $admin->query("INSERT INTO $t (s_key, s_value) VALUES " . implode(', ', array_map(static fn (array $r): string => sprintf("('%s', '%s')", $r[0], $r[1]), $chunk)));
}
foreach ($admin->query("SELECT pk_i_id, s_key, s_value FROM $t")->fetch_all(MYSQLI_ASSOC) as $r) {
    $termOf[$r['s_key'] . "\0" . $r['s_value']] = (int) $r['pk_i_id'];
}
foreach (array_chunk($facets, 500) as $chunk) {
    $admin->query("INSERT INTO $f (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id) VALUES " . implode(', ', array_map(
        static fn (array $r): string => sprintf('(%d, %s, %d, %d)', $termOf[$r[0] . "\0" . ($r[4] === null ? $r[1] : '')], $r[4] ?? 0, $r[2], $r[3]),
        $chunk
    )));
}
$admin->query("ANALYZE TABLE $f, $t, $i, $v");

$picked = static fn (callable $keep): array => array_values(array_filter($ids, static fn (int $id, int $k): bool => $keep($k), ARRAY_FILTER_USE_BOTH));
$terms  = static function (string $key, array $values): array {
    return array_values(FacetIndex::termIds([$key => $values])[$key] ?? []);
};
$option = static function (array $options, bool $inStock, bool $onSale) use ($terms): array {
    $byKey = [];
    foreach ($options as $name => $values) {
        $byKey[FacetIndex::optionKey($name)] = $terms(FacetIndex::optionKey($name), $values);
    }

    return FacetFilter::optionProbe($byKey, $inStock, $onSale);
};
$one    = static fn (string $key, string $value): int => $terms($key, [$value])[0];
$shapes = [
    '1 option'                => [$option(['Colour' => ['Red']], false, false), $picked(static fn (int $k): bool => $k % 2 === 0)],
    '1 option, 2 values'      => [$option(['Colour' => ['Red', 'Blue']], false, false), $ids],
    '2 options, one variant'  => [$option(['Colour' => ['Red'], 'Size' => ['M']], false, false), $picked(static fn (int $k): bool => $k % 2 === 0)],
    '2 options, two variants' => [$option(['Colour' => ['Red'], 'Size' => ['L']], false, false), []],
    '3 options'               => [$option(['Colour' => ['Red', 'Blue'], 'Size' => ['M', 'S'], 'Material' => ['Cotton']], false, false), $picked(static fn (int $k): bool => $k % 2 === 0)],
    'option + in_stock'       => [$option(['Colour' => ['Red']], true, false), $picked(static fn (int $k): bool => $k % 2 === 0 && $k % 4 !== 0)],
    'option + on_sale'        => [$option(['Colour' => ['Red']], false, true), $picked(static fn (int $k): bool => $k % 10 === 0)],
    'unknown option value'    => [$option(['Colour' => ['Purple']], false, false), []],
    'dropdown eq'             => [FacetFilter::fieldIn($terms('f12', ['Red'])), $picked(static fn (int $k): bool => $k % 10 === 0)],
    'dropdown in'             => [FacetFilter::fieldIn($terms('f12', ['red', '12'])), $picked(static fn (int $k): bool => $k % 10 === 0 || $k % 10 === 3)],
    'unknown dropdown value'  => [FacetFilter::fieldIn($terms('f12', ['Purple'])), []],
    'checkbox'                => [FacetFilter::fieldIn([$one('f7', '1')]), $picked(static fn (int $k): bool => $k % 3 === 0)],
    'number range'            => [FacetFilter::fieldRange($one('f9', ''), 10, 20.5), $picked(static fn (int $k): bool => $k % 50 >= 10 && $k % 50 <= 20)],
    'date interval covers'    => [FacetFilter::fieldCovers($one('f5.from', ''), $one('f5.to', ''), 1100, 2050), $picked(static fn (int $k): bool => $k >= 50 && $k <= 100)],
];
$probes = ['1 option', '1 option, 2 values', '2 options, one variant', '2 options, two variants', '3 options', 'option + in_stock', 'option + on_sale', 'dropdown eq', 'dropdown in', 'checkbox', 'number range', 'date interval covers'];
foreach ($shapes as $label => [$condition, $expected]) {
    $s = new Search();
    $s->addCondition($condition[0], $condition[2] ?? [], $condition[1]);
    $page  = (string) array_values(array_filter(json_decode($s->toJson(), true)['no_catched_conditions'], 'is_string'))[0];
    $count = $condition[1];
    foreach ($condition[2] ?? [] as $param) {
        $count = (string) preg_replace('/\?/', (string) $param, $count, 1);
    }
    $forms = [
        'page'  => "SELECT $i.pk_i_id FROM $i WHERE $i.b_enabled = 1 AND $page ORDER BY $i.pk_i_id",
        'count' => "SELECT $i.pk_i_id FROM $i WHERE $i.b_enabled = 1 AND $count ORDER BY $i.pk_i_id",
    ];
    foreach ($forms as $form => $query) {
        pin("$label ($form): the listings it should", $expected, array_map('intval', array_column($admin->query($query)->fetch_all(MYSQLI_ASSOC), 'pk_i_id')));
        if (str_contains($query, FacetFilter::NONE)) {
            continue;
        }
        $explain = $form === 'page' ? $query : "SELECT COUNT(*) FROM ($query) search_count";
        $plan    = $admin->query('EXPLAIN ' . $explain)->fetch_all(MYSQLI_ASSOC);
        $reads   = array_values(array_filter($plan, static fn (array $row): bool => in_array((string) $row['table'], [$f, 'a', 'b', 'c'], true)));
        $types   = $form === 'page' && in_array($label, $probes, true) ? ['ref', 'eq_ref'] : ['ref', 'eq_ref', 'range'];
        $bad     = array_filter($reads, static fn (array $row): bool => !in_array((string) $row['type'], $types, true) || !in_array((string) $row['key'], ['PRIMARY', 'idx_item'], true));
        check(
            "$label ($form): t_item_facet read by PRIMARY or idx_item (" . implode('/', $types) . ')',
            $reads !== [] && $bad === [],
            implode('; ', array_map(static fn (array $row): string => $row['table'] . ' ' . $row['type'] . ' ' . ($row['key'] ?? 'NULL'), $plan))
        );
    }
    check("$label: the page and count texts differ only where they should", ($page === $count) === !in_array($label, $probes, true) || $page === FacetFilter::NONE, $page . ' | ' . $count);
}

exit(harness_result());
