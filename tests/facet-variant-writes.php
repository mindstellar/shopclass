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
    foreach ($admin->query("SELECT s_key, s_value, fk_i_variant_id FROM {$p}t_item_facet WHERE fk_i_item_id = $item ORDER BY fk_i_variant_id, s_key") as $r) {
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
$admin->query("INSERT INTO {$p}t_item_facet (s_key, s_value, fk_i_item_id, fk_i_variant_id) VALUES ('f1', '1', $item, 0)");
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
$stale = static fn () => $admin->query("INSERT IGNORE INTO {$p}t_item_facet (s_key, s_value, fk_i_item_id, fk_i_variant_id) VALUES ('oColour', 'Stale', $item, 4000000001)");
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
// Begin, lock read, 3 inserts, delete of the default row, the two facet statements in their
// savepoint, commit.
pin('a 3-row save is 11 statements', 11, $count);
pin('with its six option rows', 6, count($facets($item)));

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

harness_section('a save waits for a held facet row');
$item = $listing();
[$redM] = VariantStore::saveSet($item, [$row('Red', 'M'), $row('Blue', 'L')]);
[$waiting, $out] = race($admin, "INSERT INTO {$p}t_item_facet (s_key, s_value, fk_i_item_id, fk_i_variant_id) VALUES ('oColour', 'Pink', $item, 4000000000)", $db,
    [[__DIR__ . '/lib/facet-variant-child.php', ['save', $item]]], '%DELETE FROM%t_item_facet%');
preg_match('/^(ok|threw.*)$/m', $out[0], $m);
pin('the save waited on the held row', 1, $waiting);
pin('and then succeeded', 'ok', $m[1] ?? trim($out[0]));
$ids = $variantIds($item);
pin('the facet rows match the save', $expect([[$redM, 'Red', 'M'], [$ids['{"Colour":"Green","Size":"S"}'] ?? 0, 'Green', 'S']]), $facets($item));
pin('the table is the one FacetIndex writes', $p . 't_item_facet', FacetIndex::tableName());

exit(harness_result());
