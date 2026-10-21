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
 * FacetIndex (t_item_facet and its terms): the rows each field storage type writes, the
 * 191-character cut, option rows with their variant ids, a rewrite that can run twice, terms
 * shared under the collation, made once, pruned and dropped with their field, a term another
 * writer made after the snapshot, the batched rebuild and its cursor, and the readiness check.
 *
 * Usage: php tests/facet-index.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/facet-rows.php';

use mindstellar\database\Db;
use mindstellar\fields\FieldTypeRegistry;
use mindstellar\listing\ListingStore;
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
    $rows = $admin->query('SELECT s_key, s_value, fk_i_variant_id AS v, d_number AS n FROM ' . facet_rows_sql() . " x WHERE fk_i_item_id = $item ORDER BY s_key, s_value, fk_i_variant_id")->fetch_all(MYSQLI_ASSOC);

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
pin('number: the (key, \'\') term and the number', ["f$num=|0|12.5"], $facet($item));
FacetIndex::rewriteField($item, $num, 'abc');
pin('number abc: no row', [], $facet($item));
FacetIndex::rewriteField($item, $num, '1e400');
pin('number 1e400: no row', [], $facet($item));

$date = $field('DATE');
FacetIndex::rewriteField($item, $date, '1700000000');
pin('date: timestamp row', ["f$date=|0|1700000000"], $facet($item));
FacetIndex::dropField($date);

$span = $field('DATEINTERVAL');
FacetIndex::rewriteField($item, $span, ['from' => '1700000000', 'to' => '1700086400']);
pin('date interval: two rows', ["f$span.from=|0|1700000000", "f$span.to=|0|1700086400"], $facet($item));
FacetIndex::rewriteField($item, $span, ['from' => '1700000000', 'to' => '']);
pin('date interval: an empty end has no row', ["f$span.from=|0|1700000000"], $facet($item));
FacetIndex::rewriteField($item, $span, ['to' => '1700172800']);
pin('date interval: a write of one end keeps the other', ["f$span.from=|0|1700000000", "f$span.to=|0|1700172800"], $facet($item));
FacetIndex::rewriteField($item, $span, ['from' => '']);
pin('date interval: emptying one end drops only its row', ["f$span.to=|0|1700172800"], $facet($item));
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

$solo = seed_item($admin, $cat);
$s1   = seed_exec($admin, "INSERT INTO {$p}t_item_variant (fk_i_item_id, s_options) VALUES (?, ?)", 'is', [$solo, '{"Colour":"Red","Size":"M"}']);
$s2   = seed_exec($admin, "INSERT INTO {$p}t_item_variant (fk_i_item_id, s_options) VALUES (?, ?)", 'is', [$solo, '{"Colour":"Blue","Size":"L"}']);
FacetIndex::insertVariants($solo, [['pk_i_id' => $s1, 's_options' => '{"Colour":"Red","Size":"M"}'], ['pk_i_id' => $s2, 's_options' => '{"Colour":"Blue","Size":"L"}']]);
pin('insertVariants writes the rows of the rows given', ["oColour=Blue|$s2|-", "oColour=Red|$s1|-", "oSize=L|$s2|-", "oSize=M|$s1|-"], $facet($solo));
FacetIndex::dropVariants($solo, [$s2]);
pin('dropVariants with ids drops only those variants', ["oColour=Red|$s1|-", "oSize=M|$s1|-"], $facet($solo));
FacetIndex::insertVariants($solo, [['pk_i_id' => $s2, 's_options' => '{"Colour":"Blue","Size":"XL"}']]);
pin('insertVariants after dropVariants([id]) leaves the other variant untouched', ["oColour=Blue|$s2|-", "oColour=Red|$s1|-", "oSize=M|$s1|-", "oSize=XL|$s2|-"], $facet($solo));
pin('dropVariants with no ids runs nothing', 0, harness_query_count(static fn () => FacetIndex::dropVariants($solo, [])));
pin('insertVariants with no rows runs nothing', 0, harness_query_count(static fn () => FacetIndex::insertVariants($solo, [])));
FacetIndex::dropVariants($solo);
pin('dropVariants with null drops every option row', [], $facet($solo));
$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = $solo");

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
    "f$num=|0|7",
    "f$span.from=|0|1700000000",
    "f$span.to=|0|1700086400",
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
facet_put($admin, 'f999', 'keep', $more[1]);
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

harness_section('terms');
$term    = static fn (string $key, string $value): array => array_map('intval', array_column($admin->query(
    "SELECT pk_i_id FROM {$p}t_facet_term WHERE s_key = '" . $admin->real_escape_string($key) . "' AND s_value = '" . $admin->real_escape_string($value) . "'"
)->fetch_all(MYSQLI_ASSOC), 'pk_i_id'));
$termsOf = static fn (string $key): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_facet_term WHERE s_key = '$key'")->fetch_row()[0];
$rowTerm = static fn (int $item, int $field): array => array_map('intval', array_column($admin->query(
    "SELECT f.fk_i_term_id FROM {$p}t_item_facet f JOIN {$p}t_facet_term t ON t.pk_i_id = f.fk_i_term_id WHERE f.fk_i_item_id = $item AND t.s_key = 'f$field'"
)->fetch_all(MYSQLI_ASSOC), 'fk_i_term_id'));
FacetIndex::forgetTypes();

$shade = $field('DROPDOWN');
$one   = seed_item($admin, $cat);
$two   = seed_item($admin, $cat);
FacetIndex::rewriteField($one, $shade, 'Red');
FacetIndex::rewriteField($two, $shade, 'red');
pin("'Red' and 'red' on two listings share one term", 1, $termsOf("f$shade"));
pin('both rows name it', $rowTerm($one, $shade), $rowTerm($two, $shade));
FacetIndex::forgetTypes();
pin("termIds finds 'Rèd' by the collation, keyed as asked", ["f$shade" => ['Rèd' => $rowTerm($one, $shade)[0]]], FacetIndex::termIds(["f$shade" => ['Rèd']]));
pin('termIds leaves an unknown pair out', [], FacetIndex::termIds(["f$shade" => ['Purple'], 'f99999' => ['x']]));
pin('termIds makes no term', 0, count($term("f$shade", 'Purple')));

FacetIndex::forgetTypes();
$made = FacetIndex::ensureTerms(['oFinish' => ['Matte', 'Gloss']]);
pin('ensureTerms makes the missing terms', 2, $termsOf('oFinish'));
pin('ensureTerms returns their ids', [$term('oFinish', 'Matte')[0], $term('oFinish', 'Gloss')[0]], [$made['oFinish']['Matte'], $made['oFinish']['Gloss']]);
pin('ensureTerms twice runs no second statement', 0, harness_query_count(static fn () => FacetIndex::ensureTerms(['oFinish' => ['Matte', 'Gloss']])));
pin('and returns the same ids', $made, FacetIndex::ensureTerms(['oFinish' => ['Matte', 'Gloss']]));
FacetIndex::forgetTypes();
$inserts = 0;
$count   = harness_query_count(static function () use (&$inserts): void {
    FacetIndex::ensureTerms(['oFinish' => ['Matte', 'Satin']]);
});
pin('a new value next to a known one makes one term', 3, $termsOf('oFinish'));
pin('in one INSERT: term SELECT, INSERT, locking read, snapshot read', 4, $count);

$size = $field('NUMBER');
FacetIndex::rewriteField($one, $size, '0');
pin("NUMBER '0' is the (key, '') term with d_number 0", [["f$size", '', '0']], $admin->query(
    "SELECT t.s_key, t.s_value, f.d_number FROM {$p}t_item_facet f JOIN {$p}t_facet_term t ON t.pk_i_id = f.fk_i_term_id WHERE f.fk_i_item_id = $one AND t.s_key = 'f$size'"
)->fetch_all(MYSQLI_NUM));
FacetIndex::rewriteField($two, $size, '12');
pin('two numbers share the one term', 1, $termsOf("f$size"));

FacetIndex::rewriteField($one, $shade, 'Blue');
FacetIndex::rewriteField($two, $shade, 'Blue');
pin('a value nobody holds keeps its term until a prune', 2, $termsOf("f$shade"));
$pruned = FacetIndex::pruneTerms();
check('pruneTerms deletes terms no row names', $pruned >= 1);
pin('the unreferenced Red is gone', [], $term("f$shade", 'Red'));
pin('the referenced Blue stays', 1, count($term("f$shade", 'Blue')));
pin('rows are untouched', ["f$shade=Blue|0|-"], array_values(array_filter($facet($one), static fn (string $r): bool => str_starts_with($r, "f$shade="))));

$gone = $field('DATEINTERVAL');
FacetIndex::rewriteField($one, $gone, ['from' => '1700000000', 'to' => '1700086400']);
FacetIndex::dropField($gone);
pin('dropField leaves no row', [], array_values(array_filter($facet($one), static fn (string $r): bool => str_starts_with($r, "f$gone."))));
pin('and no term', 0, $termsOf("f$gone.from") + $termsOf("f$gone.to"));

FacetIndex::forgetTypes();
try {
    Db::transaction(static function (): void {
        FacetIndex::ensureTerms(['oBack' => ['Out']]);

        throw new RuntimeException('roll back');
    });
} catch (RuntimeException $e) {
}
pin('a rolled-back term is gone', [], $term('oBack', 'Out'));
$back = FacetIndex::ensureTerms(['oBack' => ['Out']]);
pin('and is not remembered: ensureTerms makes it again', $term('oBack', 'Out'), [$back['oBack']['Out']]);

harness_section('a term another writer made after the snapshot');
$teal = $field('DROPDOWN');
$late = seed_item($admin, $cat);
FacetIndex::forgetTypes();
$admin->query("DELETE FROM {$p}t_job_queue");
$inside = null;
$threw  = null;
try {
    Db::transaction(static function () use ($admin, $p, $late, $teal, $facet, &$inside): void {
        ListingStore::find($late, ['pk_i_id'], true);
        Db::select('SELECT COUNT(*) FROM ' . FacetIndex::termTable());
        $admin->query("INSERT INTO {$p}t_facet_term (s_key, s_value) VALUES ('f$teal', 'Teal')");
        Db::execute('INSERT INTO ' . DB_TABLE_PREFIX . "t_item_meta (fk_i_item_id, fk_i_field_id, s_multi, s_value) VALUES (?, ?, '', 'Teal')", [$late, $teal]);
        FacetIndex::rewriteField($late, $teal, 'Teal');
        $inside = $facet($late);
    });
} catch (\Throwable $e) {
    $threw = $e->getMessage();
}
pin('the listing write commits', null, $threw);
pin('the row is left out inside the transaction', [], $inside);
pin('and written once it commits', ["f$teal=Teal|0|-"], $facet($late));
pin('the index stays ready', true, FacetIndex::ready());
pin('no rebuild is queued', '0', (string) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue")->fetch_row()[0]);

exit(harness_result());
