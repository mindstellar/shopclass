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
 * FacetIndex (t_item_facet): the rows each field storage type writes, the 191-character cut,
 * option rows with their variant ids, a rewrite that can run twice, the batched rebuild and
 * its cursor, and the readiness check (preference and table).
 *
 * Usage: php tests/facet-index.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\fields\FieldTypeRegistry;
use mindstellar\search\FacetIndex;

$admin  = api_admin_boot('osc_facet_index');
$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin);
$cat = seed_category($admin, 'Goods', null, $locale);

$field = static fn (string $type): int => seed_exec(
    $admin,
    "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_searchable) VALUES (?, ?, ?, 1)",
    'sss',
    ['F ' . $type . mt_rand(), strtolower($type) . mt_rand(), $type]
);
$facet = static function (int $item) use ($admin, $p): array {
    $rows = $admin->query("SELECT s_key, s_value, fk_i_variant_id AS v, d_number AS n FROM {$p}t_item_facet WHERE fk_i_item_id = $item ORDER BY s_key, s_value, fk_i_variant_id")->fetch_all(MYSQLI_ASSOC);

    return array_map(static fn (array $r): string => $r['s_key'] . '=' . $r['s_value'] . '|' . $r['v'] . '|' . ($r['n'] === null ? '-' : (float) $r['n']), $rows);
};
$item = seed_item($admin, $cat);

harness_section('helpers');
pin('key', 'f12', FacetIndex::key(12));
pin('interval key', 'f12.from', FacetIndex::key(12, 'from'));
pin('option key', 'oColour', FacetIndex::optionKey('Colour'));
pin('cut trims and keeps 191 characters', str_repeat('é', 191), FacetIndex::cut(' ' . str_repeat('é', 200) . ' '));
pin('number reads a decimal', 12.5, FacetIndex::number('12.5'));
pin('number refuses text', null, FacetIndex::number('abc'));
pin('number refuses an overflow', null, FacetIndex::number('1e400'));
pin('number refuses an empty string', null, FacetIndex::number(''));

pin('row kinds', ['text', 'text', 'flag', 'number', 'number', 'interval', '', '', ''], array_map(
    [FacetIndex::class, 'rowKind'],
    ['DROPDOWN', 'RADIO', 'CHECKBOX', 'NUMBER', 'DATE', 'DATEINTERVAL', 'TEXT', 'TEXTAREA', 'URL']
));

harness_section('storage types');
$drop = $field('DROPDOWN');
FacetIndex::rewriteField($item, $drop, 'Red');
pin('dropdown: one row', ["f$drop=Red|0|-"], $facet($item));
FacetIndex::rewriteField($item, $drop, 'Blue');
pin('dropdown: a new value replaces the row', ["f$drop=Blue|0|-"], $facet($item));
FacetIndex::rewriteField($item, $drop, '');
pin('dropdown: empty removes the row', [], $facet($item));

$radio = $field('RADIO');
FacetIndex::rewriteField($item, $radio, 'Yes');
pin('radio: one row', ["f$radio=Yes|0|-"], $facet($item));
FacetIndex::dropField($radio);
pin('dropField removes the rows', [], $facet($item));

$long = str_repeat('x', 192);
FacetIndex::rewriteField($item, $drop, $long);
pin('a 192-character value is cut to 191', [("f$drop=" . str_repeat('x', 191) . '|0|-')], $facet($item));
FacetIndex::dropField($drop);

$check = $field('CHECKBOX');
FacetIndex::rewriteField($item, $check, '1');
pin('checkbox ticked: a row', ["f$check=1|0|-"], $facet($item));
FacetIndex::rewriteField($item, $check, '0');
pin('checkbox 0: no row', [], $facet($item));

$num = $field('NUMBER');
FacetIndex::rewriteField($item, $num, '12.5');
pin('number: text and numeric twin', ["f$num=12.5|0|12.5"], $facet($item));
FacetIndex::rewriteField($item, $num, 'abc');
pin('number abc: no row', [], $facet($item));
FacetIndex::rewriteField($item, $num, '1e400');
pin('number 1e400: no row', [], $facet($item));

$date = $field('DATE');
FacetIndex::rewriteField($item, $date, '1700000000');
pin('date: timestamp row', ["f$date=1700000000|0|1700000000"], $facet($item));
FacetIndex::dropField($date);

$span = $field('DATEINTERVAL');
FacetIndex::rewriteField($item, $span, ['from' => '1700000000', 'to' => '1700086400']);
pin('date interval: two rows', ["f$span.from=1700000000|0|1700000000", "f$span.to=1700086400|0|1700086400"], $facet($item));
FacetIndex::rewriteField($item, $span, ['from' => '1700000000', 'to' => '']);
pin('date interval: an empty end has no row', ["f$span.from=1700000000|0|1700000000"], $facet($item));
FacetIndex::rewriteField($item, $span, ['to' => '1700172800']);
pin('date interval: a write of one end keeps the other', ["f$span.from=1700000000|0|1700000000", "f$span.to=1700172800|0|1700172800"], $facet($item));
FacetIndex::rewriteField($item, $span, ['from' => '']);
pin('date interval: emptying one end drops only its row', ["f$span.to=1700172800|0|1700172800"], $facet($item));
FacetIndex::dropField($span);
pin('dropField removes both interval keys', [], $facet($item));

foreach (['TEXT', 'TEXTAREA', 'URL'] as $type) {
    $id = $field($type);
    FacetIndex::rewriteField($item, $id, 'some text');
    pin("$type: no rows", [], $facet($item));
}

$pluginType = FieldTypeRegistry::getInstance();
$pluginType->register('acme.pick', ['label' => 'Pick', 'storage' => 'DROPDOWN']);
$plug = $field('TEXT');
FacetIndex::rewriteField($item, $plug, 'A', $pluginType->storageOf('acme.pick'));
pin('a plugin type stored as DROPDOWN writes a row', ["f$plug=A|0|-"], $facet($item));
FacetIndex::dropField($plug);

$looked = $field('DROPDOWN');
FacetIndex::rewriteField($item, $looked, 'Green');
pin('storage is looked up when not given', ["f$looked=Green|0|-"], $facet($item));
FacetIndex::dropField($looked);

harness_section('variants');
$v1 = seed_exec($admin, "INSERT INTO {$p}t_item_variant (fk_i_item_id, s_options) VALUES (?, ?)", 'is', [$item, '{"Colour":"Red","Size":"M"}']);
$v2 = seed_exec($admin, "INSERT INTO {$p}t_item_variant (fk_i_item_id, s_options) VALUES (?, ?)", 'is', [$item, '{"Colour":"Blue","Size":"L"}']);
$variants = $admin->query("SELECT pk_i_id, s_options FROM {$p}t_item_variant WHERE fk_i_item_id = $item ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC);
FacetIndex::rewriteVariants($item, $variants);
$expected = ["oColour=Blue|$v2|-", "oColour=Red|$v1|-", "oSize=L|$v2|-", "oSize=M|$v1|-"];
pin('two rows make four option rows with their variant ids', $expected, $facet($item));
FacetIndex::rewriteVariants($item, $variants);
pin('rewriting variants twice keeps the same rows', $expected, $facet($item));
FacetIndex::rewriteField($item, $drop, 'Red');
FacetIndex::dropVariants($item);
pin('dropVariants keeps the field rows', ["f$drop=Red|0|-"], $facet($item));
FacetIndex::dropField($drop);
FacetIndex::rewriteVariants($item, [['pk_i_id' => $v1, 's_options' => null]]);
pin('a default row has no option rows', [], $facet($item));
FacetIndex::rewriteVariants($item, [['pk_i_id' => $v1, 's_options' => '{"Size":"M","SIZE":"M"}']]);
pin('option names that differ only by case give one row', ["oSize=M|$v1|-"], $facet($item));
FacetIndex::rewriteVariants($item, [['pk_i_id' => $v1, 's_options' => null]]);

harness_section('rewriteItem');
$admin->query("DELETE FROM {$p}t_item_meta");
foreach ([[$drop, '', 'Red'], [$check, '', '1'], [$num, '', '7'], [$span, 'from', '1700000000'], [$span, 'to', '1700086400']] as [$f, $multi, $val]) {
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_multi, s_value) VALUES (?, ?, ?, ?)", 'iiss', [$item, $f, $multi, $val]);
}
FacetIndex::rewriteItem($item);
$first = $facet($item);
pin('rewriteItem builds field and option rows', [
    "f$drop=Red|0|-",
    "f$check=1|0|-",
    "f$num=7|0|7",
    "f$span.from=1700000000|0|1700000000",
    "f$span.to=1700086400|0|1700086400",
    "oColour=Blue|$v2|-", "oColour=Red|$v1|-", "oSize=L|$v2|-", "oSize=M|$v1|-",
], $first);
FacetIndex::rewriteItem($item);
pin('rewriteItem is idempotent', $first, $facet($item));

harness_section('buildStep');
$admin->query("DELETE FROM {$p}t_item_facet");
$more = [$item];
for ($i = 0; $i < 4; $i++) {
    $more[] = seed_item($admin, $cat);
}
foreach ($more as $id) {
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_multi, s_value) VALUES (?, ?, '', 'Red') ON DUPLICATE KEY UPDATE s_value = 'Red'", 'ii', [$id, $drop]);
}
$step = FacetIndex::buildStep(0, 2);
pin('first step ends on the 2nd listing', $more[1], $step);
$step = FacetIndex::buildStep($step, 2);
pin('second step ends on the 4th listing', $more[3], $step);
pin('a short batch returns null', null, FacetIndex::buildStep($step, 2));
foreach ($more as $id) {
    check("listing $id has its rows", in_array("f$drop=Red|0|-", $facet($id), true));
}
$admin->query("DELETE FROM {$p}t_item_facet WHERE fk_i_item_id = {$more[2]}");
$admin->query("INSERT INTO {$p}t_item_facet (s_key, s_value, fk_i_item_id) VALUES ('f999', 'keep', {$more[1]})");
FacetIndex::buildStep(0, 10, $drop);
check('a field step rebuilds that field', in_array("f$drop=Red|0|-", $facet($more[2]), true));
check('a field step leaves other keys alone', in_array('f999=keep|0|-', $facet($more[1]), true));
pin('buildStep past the last id gives null', null, FacetIndex::buildStep((int) end($more), 2));

harness_section('readiness');
pin('the table check sees the table', true, FacetIndex::tableExists());
osc_set_preference(FacetIndex::PREF_READY, '0', 'osclass', 'BOOLEAN');
pin('flag 0: not ready', false, FacetIndex::ready());
osc_set_preference(FacetIndex::PREF_READY, '1', 'osclass', 'BOOLEAN');
$admin->query("RENAME TABLE {$p}t_item_facet TO {$p}t_item_facet_gone");
pin('flag 1 without the table: not ready', false, FacetIndex::ready());
$admin->query("RENAME TABLE {$p}t_item_facet_gone TO {$p}t_item_facet");
pin('flag 1 with the table: ready', true, FacetIndex::ready());
FacetIndex::markStale();
pin('markStale clears the flag', false, FacetIndex::ready());
FacetIndex::markReady();
pin('markReady sets the flag', true, FacetIndex::ready());
check('markReady stamps the time', (string) osc_get_preference(FacetIndex::PREF_BUILT, 'osclass') !== '');

exit(harness_result());
