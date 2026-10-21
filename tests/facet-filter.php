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
 * The search conditions on the listing filter index: the exact SQL of each shape, option SQL
 * with values only as `?` (so Search::addCondition() takes it), field values always quoted and
 * cut as the index stores them; then each shape runs on the scratch database, matches the
 * listings it should, and EXPLAIN reads t_item_facet by an index, never whole. A one-key field
 * filter reads its key range (PRIMARY or idx_number); a probe or a join may also take idx_item.
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
$i     = $p . 't_item';
$v     = $p . 't_item_variant';

harness_section('option probe SQL');
pin(
    'one option, one value',
    ["(SELECT 1 FROM $f a WHERE a.s_key = ? AND a.s_value = ? AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL", ['oColour', 'Red']],
    FacetFilter::optionProbe(['Colour' => ['Red']], false, false)
);
pin(
    'one option, two values',
    ["(SELECT 1 FROM $f a WHERE a.s_key = ? AND a.s_value IN (?, ?) AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL", ['oColour', 'Red', 'Blue']],
    FacetFilter::optionProbe(['Colour' => ['Red', 'Blue']], false, false)
);
pin(
    'two options join on the same variant; values follow the order of the marks',
    ["(SELECT 1 FROM $f a JOIN $f b ON b.s_key = ? AND b.s_value = ? AND b.fk_i_item_id = a.fk_i_item_id AND b.fk_i_variant_id = a.fk_i_variant_id"
        . " WHERE a.s_key = ? AND a.s_value = ? AND a.fk_i_item_id = $i.pk_i_id LIMIT 1) IS NOT NULL", ['oSize', 'M', 'oColour', 'Red']],
    FacetFilter::optionProbe(['Colour' => ['Red'], 'Size' => ['M']], false, false)
);
pin(
    'three options, in_stock and on_sale',
    ["(SELECT 1 FROM $f a"
        . " JOIN $f b ON b.s_key = ? AND b.s_value IN (?, ?) AND b.fk_i_item_id = a.fk_i_item_id AND b.fk_i_variant_id = a.fk_i_variant_id"
        . " JOIN $f c ON c.s_key = ? AND c.s_value = ? AND c.fk_i_item_id = a.fk_i_item_id AND c.fk_i_variant_id = a.fk_i_variant_id"
        . " JOIN $v v ON v.pk_i_id = a.fk_i_variant_id"
        . " WHERE a.s_key = ? AND a.s_value = ? AND a.fk_i_item_id = $i.pk_i_id"
        . " AND (v.i_stock IS NULL OR v.i_stock > 0) AND v.i_compare_price > COALESCE(v.i_price, $i.i_price) LIMIT 1) IS NOT NULL",
        ['oSize', 'M', 'L', 'oMaterial', 'Cotton', 'oColour', 'Red']],
    FacetFilter::optionProbe(['Colour' => ['Red'], 'Size' => ['M', 'L'], 'Material' => ['Cotton']], true, true)
);
pin(
    'option and in_stock',
    ["(SELECT 1 FROM $f a JOIN $v v ON v.pk_i_id = a.fk_i_variant_id WHERE a.s_key = ? AND a.s_value = ? AND a.fk_i_item_id = $i.pk_i_id"
        . ' AND (v.i_stock IS NULL OR v.i_stock > 0) LIMIT 1) IS NOT NULL', ['oColour', 'Red']],
    FacetFilter::optionProbe(['Colour' => ['Red']], true, false)
);
[$sql, $params] = FacetFilter::optionProbe(['Colour' => [str_repeat('é', 200), " Red'--"], str_repeat('N', 60) => ['x']], true, true);
check('no quote character in the option SQL', strpbrk($sql, "'\"\\`") === false, $sql);
pin('as many marks as values', count($params), substr_count($sql, '?'));
pin('a long value is cut to 191 and trimmed like the index', [str_repeat('é', 191), "Red'--"], [$params[3], $params[4]]);
pin('a long name is the index key', FacetIndex::optionKey(str_repeat('N', 60)), $params[0]);
foreach ([[[], 'no name'], [['A' => ['1'], 'B' => ['1'], 'C' => ['1'], 'D' => ['1']], 'a fourth name'], [['A' => []], 'a name with no value']] as [$bad, $label]) {
    try {
        FacetFilter::optionProbe($bad, false, false);
        check("$label is refused", false);
    } catch (InvalidArgumentException $e) {
        check("$label is refused", true);
    }
}
$search = new Search();
try {
    $search->addCondition($sql, $params);
    $conditions = json_decode($search->toJson(), true)['no_catched_conditions'];
    check('Search::addCondition() takes the option probe', str_contains((string) end($conditions), "a.s_value IN ('" . str_repeat('é', 191) . "', 'Red\\'--')"), (string) end($conditions));
} catch (InvalidArgumentException $e) {
    check('Search::addCondition() takes the option probe', false, $e->getMessage());
}

harness_section('field SQL');
$in = "$i.pk_i_id IN (SELECT fk_i_item_id FROM $f WHERE ";
pin('dropdown, one value is =', $in . "s_key = 'f12' AND s_value = 'Red')", FacetFilter::fieldIn(12, ['Red']));
pin('dropdown, several values', $in . "s_key = 'f12' AND s_value IN ('Red', 'Blue'))", FacetFilter::fieldIn(12, ['Red', 'Blue']));
pin('a number value stays quoted', $in . "s_key = 'f12' AND s_value = '12')", FacetFilter::fieldIn(12, ['12']));
pin('a value is escaped', $in . "s_key = 'f12' AND s_value = 'O\\'Neil')", FacetFilter::fieldIn(12, ["O'Neil"]));
pin('a 200-char value is cut to 191', $in . "s_key = 'f12' AND s_value = '" . str_repeat('x', 191) . "')", FacetFilter::fieldIn(12, [str_repeat('x', 200)]));
pin('checkbox', $in . "s_key = 'f7' AND s_value = '1')", FacetFilter::fieldFlag(7));
pin('number range', $in . "s_key = 'f9' AND d_number >= 10 AND d_number <= 20)", FacetFilter::fieldRange(9, 10.0, 20.0));
pin('a decimal bound keeps its digits', $in . "s_key = 'f9' AND d_number >= 0.1 AND d_number <= 1234567890123.456)", FacetFilter::fieldRange(9, 0.1, 1234567890123.456));
pin('a big bound reads back as the same double', 1.0E+25, (float) explode('d_number <= ', FacetFilter::fieldRange(9, 0, 1.0E+25))[1]);
pin(
    'date interval covers',
    "$i.pk_i_id IN (SELECT a.fk_i_item_id FROM $f a JOIN $f b ON b.s_key = 'f5.to' AND b.fk_i_item_id = a.fk_i_item_id AND b.d_number >= 1700000000"
        . " WHERE a.s_key = 'f5.from' AND a.d_number <= 1690000000)",
    FacetFilter::fieldCovers(5, 1690000000, 1700000000)
);
foreach (['no value' => static fn () => FacetFilter::fieldIn(12, []), 'an infinite bound' => static fn () => FacetFilter::fieldRange(9, 0, INF), 'a NaN bound' => static fn () => FacetFilter::fieldRange(9, NAN, 1)] as $label => $call) {
    try {
        $call();
        check("$label is refused", false);
    } catch (InvalidArgumentException $e) {
        check("$label is refused", true);
    }
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
foreach (array_chunk($facets, 500) as $chunk) {
    $admin->query("INSERT INTO $f (s_key, s_value, fk_i_item_id, fk_i_variant_id, d_number) VALUES " . implode(', ', array_map(
        static fn (array $r): string => sprintf("('%s', '%s', %d, %d, %s)", $r[0], $r[1], $r[2], $r[3], $r[4] === null ? 'NULL' : $r[4]),
        $chunk
    )));
}
$admin->query("ANALYZE TABLE $f, $i, $v");

$picked = static fn (callable $keep): array => array_values(array_filter($ids, static fn (int $id, int $k): bool => $keep($k), ARRAY_FILTER_USE_BOTH));
$shapes = [
    '1 option'                => [FacetFilter::optionProbe(['Colour' => ['Red']], false, false), $picked(static fn (int $k): bool => $k % 2 === 0)],
    '1 option, 2 values'      => [FacetFilter::optionProbe(['Colour' => ['Red', 'Blue']], false, false), $ids],
    '2 options, one variant'  => [FacetFilter::optionProbe(['Colour' => ['Red'], 'Size' => ['M']], false, false), $picked(static fn (int $k): bool => $k % 2 === 0)],
    '2 options, two variants' => [FacetFilter::optionProbe(['Colour' => ['Red'], 'Size' => ['L']], false, false), []],
    '3 options'               => [FacetFilter::optionProbe(['Colour' => ['Red', 'Blue'], 'Size' => ['M', 'S'], 'Material' => ['Cotton']], false, false), $picked(static fn (int $k): bool => $k % 2 === 0)],
    'option + in_stock'       => [FacetFilter::optionProbe(['Colour' => ['Red']], true, false), $picked(static fn (int $k): bool => $k % 2 === 0 && $k % 4 !== 0)],
    'option + on_sale'        => [FacetFilter::optionProbe(['Colour' => ['Red']], false, true), $picked(static fn (int $k): bool => $k % 10 === 0)],
    'dropdown eq'             => [FacetFilter::fieldIn(12, ['Red']), $picked(static fn (int $k): bool => $k % 10 === 0)],
    'dropdown in'             => [FacetFilter::fieldIn(12, ['red', '12']), $picked(static fn (int $k): bool => $k % 10 === 0 || $k % 10 === 3)],
    'checkbox'                => [FacetFilter::fieldFlag(7), $picked(static fn (int $k): bool => $k % 3 === 0)],
    'number range'            => [FacetFilter::fieldRange(9, 10, 20.5), $picked(static fn (int $k): bool => $k % 50 >= 10 && $k % 50 <= 20)],
    'date interval covers'    => [FacetFilter::fieldCovers(5, 1100, 2050), $picked(static fn (int $k): bool => $k >= 50 && $k <= 100)],
];
$range = ['dropdown eq', 'dropdown in', 'checkbox', 'number range'];
foreach ($shapes as $label => [$condition, $expected]) {
    $keys = in_array($label, $range, true) ? ['PRIMARY', 'idx_number'] : ['PRIMARY', 'idx_number', 'idx_item'];
    $s    = new Search();
    if (is_array($condition)) {
        $s->addCondition(...$condition);
        $conditions = json_decode($s->toJson(), true)['no_catched_conditions'];
        $condition  = (string) end($conditions);
    }
    $query = "SELECT $i.pk_i_id FROM $i WHERE $i.b_enabled = 1 AND $condition ORDER BY $i.pk_i_id";
    pin("$label: the listings it should", $expected, array_map('intval', array_column($admin->query($query)->fetch_all(MYSQLI_ASSOC), 'pk_i_id')));
    foreach (['page' => $query, 'count' => "SELECT COUNT(*) FROM ($query) search_count"] as $form => $sqlText) {
        $plan  = $admin->query('EXPLAIN ' . $sqlText)->fetch_all(MYSQLI_ASSOC);
        $reads = array_values(array_filter($plan, static fn (array $row): bool => in_array((string) $row['table'], [$f, 'a', 'b', 'c'], true)));
        $bad   = array_filter($reads, static fn (array $row): bool => $row['type'] === 'ALL' || !in_array((string) $row['key'], $keys, true));
        check(
            "$label ($form): t_item_facet read by " . implode(' or ', $keys),
            $reads !== [] && $bad === [],
            implode('; ', array_map(static fn (array $row): string => $row['table'] . ' ' . $row['type'] . ' ' . ($row['key'] ?? 'NULL'), $plan))
        );
    }
}

exit(harness_result());
