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
 * Product variants (t_item_variant): the validator's widths and types, that only an admin
 * writes `product`, the admin PATCH, the serializer's `product` member, the stock change that
 * cannot go below zero, the row cleanup on delete, and a fresh install matching an upgrade.
 * Usage: php tests/item-variant.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\auth\Actor;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingValidator;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_item_variant');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'logs_admin' => '1',
    'title_character_length' => '100', 'description_character_length' => '5000'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.72';

$rows = static fn (int $item): array => $admin->query("SELECT * FROM {$p}t_item_variant WHERE fk_i_item_id = $item ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC);

harness_section('the validator');
$v     = new ListingValidator();
$codes = static fn (array $errors): array => array_column($errors, 'pointer');
pin('a plain row passes', [], $v->product(['sell' => true, 'fields' => ['s_sku' => 'A-1', 'i_price' => 12500000, 'i_stock' => 5, 'i_weight' => 300, 's_shipping_class' => 'bulky']]));
pin('a row not for sale is not checked', [], $v->product(['sell' => false, 'fields' => ['s_sku' => str_repeat('x', 99)]]));
pin('a SKU over 64 characters', ['/product/sku'], $codes($v->product(['sell' => true, 'fields' => ['s_sku' => str_repeat('x', 65)]])));
pin('a SKU of 64 passes', [], $v->product(['sell' => true, 'fields' => ['s_sku' => str_repeat('x', 64)]]));
pin('a shipping class over 40 characters', ['/product/shipping_class'], $codes($v->product(['sell' => true, 'fields' => ['s_shipping_class' => str_repeat('x', 41)]])));
pin('a price that is not a number', ['/product/price'], $codes($v->product(['sell' => true, 'fields' => ['i_price' => 'free']])));
pin('a negative price', ['/product/price'], $codes($v->product(['sell' => true, 'fields' => ['i_price' => -1]])));
pin('stock that is not whole', ['/product/stock'], $codes($v->product(['sell' => true, 'fields' => ['i_stock' => '1.5']])));
pin('negative stock', ['/product/stock'], $codes($v->product(['sell' => true, 'fields' => ['i_stock' => -1]])));
pin('stock past the column', ['/product/stock'], $codes($v->product(['sell' => true, 'fields' => ['i_stock' => VariantStore::MAX_STOCK + 1]])));
pin('stock at the column limit passes', [], $v->product(['sell' => true, 'fields' => ['i_stock' => VariantStore::MAX_STOCK]]));
pin('negative weight', ['/product/weight'], $codes($v->product(['sell' => true, 'fields' => ['i_weight' => -5]])));
pin('weight past the column', ['/product/weight'], $codes($v->product(['sell' => true, 'fields' => ['i_weight' => VariantStore::MAX_WEIGHT + 1]])));
pin('the widths match the columns', ['s_sku' => 64, 's_shipping_class' => 40], ListingValidator::VARIANT_WIDTHS);
$cols = [];
foreach ($admin->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item_variant' AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL AND COLUMN_NAME IN ('s_sku', 's_shipping_class')") as $c) {
    $cols[$c['COLUMN_NAME']] = (int) $c['CHARACTER_MAXIMUM_LENGTH'];
}
ksort($cols);
$declared = ListingValidator::VARIANT_WIDTHS;
ksort($declared);
pin('and the live schema', $declared, $cols);

harness_section('only an admin writes product');
$adminActor = Actor::admin(1, '192.0.2.72');
$userActor  = Actor::user($sue, '192.0.2.72');
$service    = new ListingService();
$form       = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'title'        => ['en_US' => 'Ceramic mug'],
    'description'  => ['en_US' => 'A hand made ceramic mug, dishwasher safe.'],
    'price'        => '12',
    'contactName'  => 'Sue',
    'contactEmail' => 'sue@example.test',
    'contactPhone' => '5550199',
];
$sell = ['sell' => '1', 'sku' => 'MUG-1', 'price' => '9.50', 'stock' => '4', 'weight' => '350', 'shippingClass' => 'small'];

$data = ListingInput::fromArray($form(['product' => $sell]), $userActor, true);
pin('a user\'s form carries no product', false, array_key_exists('product', $data));
$data = ListingInput::fromArray($form(['ownerId' => '0', 'product' => $sell]), $adminActor, true);
$made = $data['product'] ?? ['fields' => []];
ksort($made['fields']);
pin('an admin\'s form carries it', ['sell' => true, 'fields' => ['i_price' => 9500000, 'i_stock' => 4, 'i_weight' => 350, 's_shipping_class' => 'small', 's_sku' => 'MUG-1']], $made);

$byUser = $service->create(ListingInput::fromArray($form(['product' => $sell]), $userActor, true), $userActor)->id();
pin('a user\'s new listing has no variant', [], $rows($byUser));
// Data built by hand, as a plugin might, still cannot sell for a user.
$handmade = ListingInput::fromArray($form(), $userActor, true) + ['product' => ['sell' => true, 'fields' => ['s_sku' => 'X']]];
$byHand   = $service->create($handmade, $userActor)->id();
pin('the service ignores product from a non-admin actor', [], $rows($byHand));

$one = $service->create(ListingInput::fromArray($form(['ownerId' => '0', 'product' => $sell]), $adminActor, true), $adminActor)->id();
$got = $rows($one);
pin('an admin\'s new listing gets the default row', 1, count($got));
pin('with the sent values', ['MUG-1', '9500000', '4', '350', 'small', null], [$got[0]['s_sku'], $got[0]['i_price'], $got[0]['i_stock'], $got[0]['i_weight'], $got[0]['s_shipping_class'], $got[0]['s_options']]);
pin('and the listing is buyable', true, VariantStore::isBuyable($one));

$edit = static fn (int $id, array $extra): int => $service->update(
    ListingInput::fromArray($form(['id' => (string) $id, 'ownerId' => '0'] + $extra), $adminActor, false),
    $adminActor,
    false,
    false
)->id();
$edit($one, ['product' => ['sell' => '1', 'stock' => '9', 'sku' => '']]);
$got = $rows($one);
pin('an edit changes the row, not adds one', 1, count($got));
pin('a sent member changes, an empty one clears, one left out stays', ['9', null, '9500000'], [$got[0]['i_stock'], $got[0]['s_sku'], $got[0]['i_price']]);
$edit($one, []);
pin('a form with no product leaves the row alone', ['9', '9500000'], [$rows($one)[0]['i_stock'], $rows($one)[0]['i_price']]);
$bad = null;
try {
    $edit($one, ['product' => ['sell' => '1', 'stock' => 'lots']]);
} catch (\mindstellar\exception\InvalidException $e) {
    $bad = $codes($e->errors());
}
pin('a bad stock refuses the save', ['/product/stock'], $bad);
pin('and changes nothing', '9', $rows($one)[0]['i_stock']);
$edit($one, ['product' => ['sell' => '0']]);
pin('stopping the sale removes the row', [], $rows($one));
pin('and the listing is no longer buyable', false, VariantStore::isBuyable($one));

harness_section('the API');
$bossId = api_admin_seed_admin($admin, 'boss');
$boss   = api_admin_key($bossId);
$userKey = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read', 'listings:write'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$tom     = seed_user($admin, 'tom', 'tom@example.test');
$tomKey  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'tom', ['listings:read'], \mindstellar\apikey\KeyOwner::user($tom))->token();
$call = api_admin_caller();

$plain = seed_item($admin, $cat, $sue, 'Plain listing', 10.0);
$r     = $call('GET', 'listings/' . $plain, null, $tomKey);
pin('a listing with no row has no product member', [200, false], [$r->status(), array_key_exists('product', $r->body()['data'] ?? [])]);

$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => ['sku' => 'P-1', 'price' => '7.25', 'stock' => 2, 'weight' => 120, 'shipping_class' => 'letter']], $boss);
pin('an admin PATCH saves the default row', 200, $r->status());
$got = $rows($plain);
pin('with the sent values', ['P-1', '7250000', '2', '120', 'letter'], [$got[0]['s_sku'], $got[0]['i_price'], $got[0]['i_stock'], $got[0]['i_weight'], $got[0]['s_shipping_class']]);
pin('the response matches the schema', [], api_admin_schema_errors('ListingDocument', $call('GET', 'admin/listings/' . $plain, null, $boss)));

$r       = $call('GET', 'listings/' . $plain, null, $tomKey);
$product = $r->body()['data']['product'] ?? null;
pin('the public view shows the product', true, $product['buyable'] ?? null);
pin('one variant, with stock reduced to in_stock', [1, true, 'P-1', '7.25', 120, 'letter', false], [
    count($product['variants'] ?? []),
    $product['variants'][0]['in_stock'] ?? null,
    $product['variants'][0]['sku'] ?? null,
    $product['variants'][0]['price'] ?? null,
    $product['variants'][0]['weight'] ?? null,
    $product['variants'][0]['shipping_class'] ?? null,
    array_key_exists('stock', $product['variants'][0] ?? []),
]);
$r = $call('GET', 'admin/listings/' . $plain, null, $boss);
pin('the admin view shows the stock count', 2, $r->body()['data']['product']['variants'][0]['stock'] ?? null);
pin('a page of listings carries product too', [true], array_map(static fn (array $l): bool => isset($l['product']), array_filter($call('GET', 'listings', null, $tomKey)->body()['data'] ?? [], static fn (array $l): bool => $l['id'] === $plain)));

$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => ['stock' => 'many']], $boss);
pin('a bad stock is refused', 422, $r->status());
$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => ['sku' => str_repeat('x', 65)]], $boss);
pin('a long SKU is refused', 422, $r->status());
pin('and the row is as it was', 'P-1', $rows($plain)[0]['s_sku']);

$mine = seed_item($admin, $cat, $sue, 'Sue\'s listing', 10.0);
$r    = $call('PATCH', 'listings/' . $mine, ['product' => ['sku' => 'HACK']], $userKey);
pin('a user\'s PATCH with product is refused', 422, $r->status());
pin('and no row is written', [], $rows($mine));

$shown = ItemForm::product_values(['pk_i_id' => $plain]);
pin('the admin form reads the default row', [true, 'P-1', '7.25', '2', '120', 'letter'], [$shown['sell'], $shown['sku'], $shown['price'], $shown['stock'], $shown['weight'], $shown['shippingClass']]);
pin('a listing with no row shows an unticked form', [false, ''], [ItemForm::product_values(['pk_i_id' => $mine])['sell'], ItemForm::product_values([])['sku']]);

$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => null], $boss);
pin('product null stops selling', [200, []], [$r->status(), $rows($plain)]);

harness_section('stock changes');
$id = VariantStore::saveDefault($mine, ['i_stock' => 1]);
pin('stock at 1 takes one off', true, VariantStore::adjustStock($id, -1));
pin('and is then 0', '0', $rows($mine)[0]['i_stock']);
pin('a change below zero is refused', false, VariantStore::adjustStock($id, -1));
pin('and the stock stays 0', '0', $rows($mine)[0]['i_stock']);
pin('adding stock works', true, VariantStore::adjustStock($id, 5));
pin('and is then 5', '5', $rows($mine)[0]['i_stock']);
pin('a change past the column is refused', false, VariantStore::adjustStock($id, VariantStore::MAX_STOCK));
pin('an unknown variant is refused', false, VariantStore::adjustStock(999999, 1));
$untracked = VariantStore::saveDefault($byUser, ['i_stock' => null]);
pin('a variant that does not track stock accepts a change and keeps no count', [true, null], [VariantStore::adjustStock($untracked, -3), $rows($byUser)[0]['i_stock']]);

// Two buyers at stock 1 on two connections: the second statement waits for the first one's
// row lock, then finds the stock gone.
$race = VariantStore::saveDefault($plain, ['i_stock' => 1]);
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$pass   = getenv('DRIFT_DB_PASS');
$conn   = static fn (): mysqli => new mysqli(getenv('DRIFT_DB_HOST') ?: '127.0.0.1', getenv('DRIFT_DB_USER') ?: 'root', $pass === false ? 'root' : $pass, $dbName, (int) (getenv('DRIFT_DB_PORT') ?: 33061));
$first  = $conn();
$second = $conn();
$take   = "UPDATE {$p}t_item_variant SET i_stock = i_stock + -1 WHERE pk_i_id = $race AND i_stock IS NOT NULL AND i_stock + -1 >= 0";
$first->begin_transaction();
$second->begin_transaction();
$first->query($take);
$firstWon = $first->affected_rows;
$second->query($take, MYSQLI_ASYNC);
usleep(200000);
$first->commit();
$reaped = [$second];
$none   = [];
$err    = [];
mysqli_poll($reaped, $none, $err, 5);
$second->reap_async_query();
$secondWon = $second->affected_rows;
$second->commit();
pin('two decrements at stock 1: one wins', [1, 0, '0'], [$firstWon, $secondWon, $admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $race")->fetch_row()[0]]);
$first->close();
$second->close();

harness_section('delete and upgrade');
VariantStore::saveDefault($one, ['s_sku' => 'DEL-1']);
pin('the listing has a row to lose', 1, count($rows($one)));
Item::getInstance()->deleteByPrimaryKey($one);
pin('deleting the listing removes its variants', [], $rows($one));

$ddl = static fn (): array => array_map('array_values', $admin->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item_variant' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC));
$idx = static fn (): array => array_map('array_values', $admin->query("SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item_variant' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetch_all(MYSQLI_ASSOC));
$fresh = [$ddl(), $idx()];
$admin->query("DROP TABLE {$p}t_item_variant");
$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0076_item_variant.php';
$migration->up(\mindstellar\database\Connection::getInstance());
pin('the migration builds the table struct.sql builds', $fresh, [$ddl(), $idx()]);
$migration->up(\mindstellar\database\Connection::getInstance());
pin('and runs again without error', $fresh, [$ddl(), $idx()]);

exit(harness_result());
