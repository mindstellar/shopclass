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
 * The variant writers keep a listing's option rows in t_item_facet: a save, an edit that drops a
 * row, a default row, row removal, listing delete, the query count of a save, and two writers on
 * one listing (a held variant row, a held facet row) waiting instead of failing.
 * Usage: php tests/facet-variant-writes.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/facet-rows.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\auth\Actor;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\VariantStore;
use mindstellar\search\FacetIndex;

$admin = api_admin_boot('osc_facet_variant_writes');
$p     = DB_TABLE_PREFIX;
$db    = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Clothes', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'title_character_length' => '100',
    'description_character_length' => '5000', 'moderate_admin_edit' => '1', 'map_type' => 'openstreet'] as $k => $v) {
    osc_set_preference($k, $v, 'osclass', 'STRING');
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.83';
Params::init();

$facets = static function (int $item) use ($admin, $p): array {
    $out = [];
    foreach ($admin->query('SELECT s_key, s_value, fk_i_variant_id FROM ' . facet_rows_sql() . " x WHERE fk_i_item_id = $item ORDER BY fk_i_variant_id, s_key") as $r) {
        $out[] = [$r['s_key'], $r['s_value'], (int) $r['fk_i_variant_id']];
    }

    return $out;
};
$variantIds = static function (int $item) use ($admin, $p): array {
    $out = [];
    foreach ($admin->query("SELECT pk_i_id, s_options FROM {$p}t_item_variant WHERE fk_i_item_id = $item ORDER BY pk_i_id") as $r) {
        $out[(string) $r['s_options']] = (int) $r['pk_i_id'];
    }

    return $out;
};
$row    = static fn (string $colour, string $size, int $stock = 1): array => ['options' => ['Colour' => $colour, 'Size' => $size], 'fields' => ['i_stock' => $stock]];
$expect = static function (array $pairs): array {
    $out = [];
    foreach ($pairs as [$id, $colour, $size]) {
        $out[] = ['oColour', $colour, $id];
        $out[] = ['oSize', $size, $id];
    }

    return $out;
};
$listing = static fn (): int => seed_item($admin, $cat, $sue, 'Tee', 15.0);

harness_section('a save writes one row per option per variant');
$item = $listing();
VariantStore::saveDefault($item, ['i_stock' => 4]);
pin('a default row has no facet rows', [], $facets($item));
[$redM, $blueL] = VariantStore::saveSet($item, [$row('Red', 'M'), $row('Blue', 'L')]);
pin('Red/M and Blue/L give four rows with their variant ids', $expect([[$redM, 'Red', 'M'], [$blueL, 'Blue', 'L']]), $facets($item));

harness_section('an edit that drops a row');
$kept = VariantStore::saveSet($item, [$row('Red', 'M', 9)]);
pin('Red/M keeps its id', [$redM], $kept);
pin('only Blue/L\'s rows are gone', $expect([[$redM, 'Red', 'M']]), $facets($item));

harness_section('a custom field row is not an option row');
facet_put($admin, 'f1', '1', $item);
$green = VariantStore::saveSet($item, [$row('Green', 'S')]);
pin('a save leaves the field row alone', array_merge([['f1', '1', 0]], $expect([[$green[0], 'Green', 'S']])), $facets($item));

harness_section('a facet failure does not stop a variant save');
$lost = $listing();
osc_set_preference('facet_ready', '1', 'osclass', 'BOOLEAN');
$admin->query("DELETE FROM {$p}t_job_queue");
$admin->query("RENAME TABLE {$p}t_item_facet TO {$p}t_item_facet_away");
$saved = VariantStore::saveSet($lost, [$row('Red', 'M')]);
$admin->query("RENAME TABLE {$p}t_item_facet_away TO {$p}t_item_facet");
pin('the variant row is written', 1, count($saved) === 1 ? count($variantIds($lost)) : 0);
pin('the index is marked stale', '0', (string) $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = 'facet_ready'")->fetch_row()[0]);
pin('and a rebuild is queued', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "'")->fetch_row()[0]);
$admin->query("DELETE FROM {$p}t_job_queue");

harness_section('saveDefault, removeDefault and deleteForItem');
// A stale option row seeded by hand, so each writer is seen dropping rows on its own.
$stale = static fn () => facet_put($admin, 'oColour', 'Stale', $item, 4000000001);
$stale();
VariantStore::saveDefault($item, ['i_stock' => 2]);
pin('saveDefault leaves no option rows', [['f1', '1', 0]], $facets($item));
$stale();
pin('removeDefault deletes the default row', 1, VariantStore::removeDefault($item));
pin('and the option rows, not the field row', [['f1', '1', 0]], $facets($item));
VariantStore::saveDefault($item, ['i_stock' => 2]);
$stale();
pin('deleteForItem deletes every variant', 1, VariantStore::deleteForItem($item));
pin('and their option rows, not the field row', [['f1', '1', 0]], $facets($item));

harness_section('an admin edit through the service');
$actor   = Actor::admin(1, '192.0.2.83');
$service = new ListingService();
$form    = static fn (int $id, array $product): array => [
    'id'           => (string) $id,
    'ownerId'      => '0',
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'title'        => ['en_US' => 'Cotton tee'],
    'description'  => ['en_US' => 'A plain cotton tee in two colours and sizes.'],
    'price'        => '15',
    'currency'     => 'USD',
    'contactName'  => 'Sue',
    'contactEmail' => 'sue@example.test',
    'contactPhone' => '5550199',
    'product'      => $product,
];
$edit = static fn (int $id, array $product) => $service->update(ListingInput::fromArray($form($id, $product), $actor, false), $actor, false, false);
$item = $listing();
$edit($item, ['sell' => '1', 'hasOptions' => '1', 'optionNames' => ['Colour', 'Size'], 'variants' => [['options' => ['Red', 'M'], 'stock' => '1'], ['options' => ['Blue', 'L'], 'stock' => '1']]]);
$ids = $variantIds($item);
pin('the product save writes the option rows', $expect([[$ids['{"Colour":"Red","Size":"M"}'], 'Red', 'M'], [$ids['{"Colour":"Blue","Size":"L"}'], 'Blue', 'L']]), $facets($item));
$edit($item, ['sell' => '0']);
pin('not for sale removes them', [], $facets($item));

harness_section('listing delete');
$item = $listing();
VariantStore::saveSet($item, [$row('Red', 'M'), $row('Blue', 'L')]);
$secret = (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = $item")->fetch_row()[0];
$service->delete($item, $secret, $actor);
pin('the listing\'s facet rows go with it', '0', (string) $admin->query("SELECT COUNT(*) FROM {$p}t_item_facet WHERE fk_i_item_id = $item")->fetch_row()[0]);

harness_section('the query count of a save');
$item = $listing();
VariantStore::saveDefault($item, ['i_stock' => 1]);
$count = harness_query_count(static function () use ($item, $row): void {
    VariantStore::saveSet($item, [$row('Red', 'S'), $row('Red', 'M'), $row('Red', 'L')]);
});
// Begin, lock read, 3 inserts, delete of the default row, the drop and insert each in their
// savepoint (two statements apiece), commit.
pin('a 3-row save is 13 statements', 13, $count);
pin('with its six option rows', 6, count($facets($item)));

harness_section('a save writes only the rows it changed');
$com = static function (callable $fn): array {
    $read = static function (): array {
        $out = [];
        $res = DBConnectionClass::newInstance()->getOsclassDb()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert', 'Com_delete')");
        foreach ($res as $r) {
            $out[$r['Variable_name']] = (int) $r['Value'];
        }

        return $out;
    };
    $a = $read();
    $fn();
    $b = $read();

    return ['insert' => $b['Com_insert'] - $a['Com_insert'], 'delete' => $b['Com_delete'] - $a['Com_delete']];
};
$item = $listing();
$set  = array_map(static fn (int $i): array => $row('Red', 'S' . $i), range(1, 100));
$ids  = VariantStore::saveSet($item, $set);
// A marker row on a kept variant: a rewrite of the listing's option rows would delete it.
facet_put($admin, 'oColour', 'Marker', $item, $ids[0]);
$marked = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM " . facet_rows_sql() . " x WHERE fk_i_item_id = $item AND s_value = 'Marker'")->fetch_row()[0];
$before = $facets($item);
$run    = $com(static fn () => VariantStore::saveSet($item, $set));
pin('an unchanged save of 100 rows runs no insert and no delete', ['insert' => 0, 'delete' => 0], $run);
pin('its facet rows are as they were', $before, $facets($item));
pin('and the marker on a kept row is still there', 1, $marked());
VariantStore::saveSet($item, array_reverse($set));
pin('a reorder leaves the facet rows as they were', $before, $facets($item));
pin('and the marker', 1, $marked());

$small = $listing();
[$redM, $blueL] = VariantStore::saveSet($small, [$row('Red', 'M'), $row('Blue', 'L')]);
facet_put($admin, 'oColour', 'Marker', $small, $redM);
$run = $com(static fn () => VariantStore::saveSet($small, [$row('Red', 'M'), $row('Blue', 'XL')]));
$ids = $variantIds($small);
pin('swapping Blue/L for Blue/XL keeps Red/M\'s id', $redM, $ids['{"Colour":"Red","Size":"M"}'] ?? null);
$sorted = static function (array $rows): array {
    usort($rows, static fn (array $x, array $y): int => [$x[2], $x[0], $x[1]] <=> [$y[2], $y[0], $y[1]]);

    return $rows;
};
pin('and writes only the new variant\'s rows', $sorted(array_merge(
    [['oColour', 'Marker', $redM]],
    $expect([[$redM, 'Red', 'M'], [$ids['{"Colour":"Blue","Size":"XL"}'], 'Blue', 'XL']])
    )), $sorted($facets($small)));
pin('one delete for the variant row and one for its option rows', 2, $run['delete']);

$run = $com(static fn () => VariantStore::saveSet($small, [$row('Red', 'M')]));
pin('dropping one variant inserts no facet row', 0, $run['insert']);
pin('and removes its rows', 1, count(array_filter($facets($small), static fn (array $r): bool => $r[1] === 'XL')) === 0 ? 1 : 0);
pin('the kept row keeps the marker', 1, (int) $admin->query("SELECT COUNT(*) FROM " . facet_rows_sql() . " x WHERE fk_i_item_id = $small AND s_value = 'Marker'")->fetch_row()[0]);

$run = $com(static fn () => VariantStore::saveSet($small, [$row('Red', 'M'), $row('Green', 'S')]));
pin('adding one variant deletes nothing', 0, $run['delete']);
pin('and the marker is still there', 1, (int) $admin->query("SELECT COUNT(*) FROM " . facet_rows_sql() . " x WHERE fk_i_item_id = $small AND s_value = 'Marker'")->fetch_row()[0]);

harness_section('an edit waits for a held variant row');
$item = $listing();
[$redM] = VariantStore::saveSet($item, [$row('Red', 'M', 5), $row('Blue', 'L', 5)]);
[$waiting, $out] = race($admin, "UPDATE {$p}t_item_variant SET i_stock = i_stock - 1 WHERE pk_i_id = $redM", $db,
    [[__DIR__ . '/lib/facet-variant-child.php', ['edit', $item]]], '%t_item_variant%FOR UPDATE%');
preg_match('/^(ok|threw.*)$/m', $out[0], $m);
pin('the edit waited on the held row', 1, $waiting);
pin('and then succeeded', 'ok', $m[1] ?? trim($out[0]));
$ids = $variantIds($item);
pin('Red/M kept its id', $redM, $ids['{"Colour":"Red","Size":"M"}'] ?? null);
pin('the facet rows are the edit\'s', $expect([[$redM, 'Red', 'M'], [$ids['{"Colour":"Green","Size":"S"}'] ?? 0, 'Green', 'S']]), $facets($item));

harness_section('a direct save waits on its locking read of the variants');
$item = $listing();
[$redM] = VariantStore::saveSet($item, [$row('Red', 'M', 5), $row('Blue', 'L', 5)]);
race_hold($admin, "UPDATE {$p}t_item_variant SET i_stock = i_stock - 1 WHERE pk_i_id = $redM");
$run     = race_run(__DIR__ . '/lib/facet-variant-child.php', $db, ['save', $item]);
$waiting = race_wait_for($db, 'SELECT *%t_item_variant%FOR UPDATE%');
$free    = race_facet_free($db, $item);
$out     = race_release($admin, [$run]);
preg_match('/^(ok|threw.*)$/m', $out[0], $m);
pin('the save waited on SELECT ... FOR UPDATE', 1, $waiting);
pin('holding no index row yet', true, $free);
pin('and then succeeded', 'ok', $m[1] ?? trim($out[0]));
$ids = $variantIds($item);
pin('the facet rows match the save', $expect([[$redM, 'Red', 'M'], [$ids['{"Colour":"Green","Size":"S"}'] ?? 0, 'Green', 'S']]), $facets($item));

harness_section('a save waits for a held facet row');
$item = $listing();
[$redM, $blueL] = VariantStore::saveSet($item, [$row('Red', 'M'), $row('Blue', 'L')]);
[$waiting, $out] = race($admin, "INSERT INTO {$p}t_item_facet (fk_i_term_id, fk_i_item_id, fk_i_variant_id) SELECT pk_i_id, $item, $blueL FROM {$p}t_facet_term WHERE s_key = 'oColour' AND s_value = 'Red'", $db,
    [[__DIR__ . '/lib/facet-variant-child.php', ['save', $item]]], '%DELETE FROM%t_item_facet%');
preg_match('/^(ok|threw.*)$/m', $out[0], $m);
pin('the save waited on the held row', 1, $waiting);
pin('and then succeeded', 'ok', $m[1] ?? trim($out[0]));
$ids = $variantIds($item);
pin('the facet rows match the save', $expect([[$redM, 'Red', 'M'], [$ids['{"Colour":"Green","Size":"S"}'] ?? 0, 'Green', 'S']]), $facets($item));
pin('the table is the one FacetIndex writes', $p . 't_item_facet', FacetIndex::tableName());

exit(harness_result());
