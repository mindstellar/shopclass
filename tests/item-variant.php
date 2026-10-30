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
 * cannot go below zero, the shop-mode refusal in the service and the API, the row cleanup on
 * delete, option rows (rules, ids kept across an edit, the stock and price hooks, the admin form
 * and API), and a fresh install matching an upgrade.
 * Usage: php tests/item-variant.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingValidator;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_item_variant');
scratchdb_pin_site_mode($admin, 'classifieds');
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
pin('a negative was price', ['/product/compare_at'], $codes($v->product(['sell' => true, 'fields' => ['i_compare_price' => -1]])));
pin('a was price that is not a number', ['/product/compare_at'], $codes($v->product(['sell' => true, 'fields' => ['i_compare_price' => 'cheap']])));
pin('a good was price passes', [], $v->product(['sell' => true, 'fields' => ['i_compare_price' => 9500000]]));
pin('a sold listing with no currency is refused', ['/currency'], $codes($v->productCurrency(['sell' => true], null)));
pin('and so is a blank one', ['/currency'], $codes($v->productCurrency(['sell' => true], ' ')));
pin('a sold listing with a currency passes', [], $v->productCurrency(['sell' => true], 'USD'));
pin('a listing not sold needs no currency', [], $v->productCurrency(['sell' => false], null));
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
    'currency'     => 'USD',
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
pin('with the sent values, and no price of its own', ['MUG-1', null, '4', '350', 'small', null], [$got[0]['s_sku'], $got[0]['i_price'], $got[0]['i_stock'], $got[0]['i_weight'], $got[0]['s_shipping_class'], $got[0]['s_options']]);
pin('and the listing is buyable', true, VariantStore::isBuyable($one));
$listingPrice = static fn (int $id): ?string => $admin->query("SELECT i_price FROM {$p}t_item WHERE pk_i_id = $id")->fetch_row()[0] ?? null;
$unit         = static fn (int $id): ?int => (new \mindstellar\billing\CartPricer())->quote([['variant' => (int) $rows($id)[0]['pk_i_id'], 'qty' => 1]], [])->lines()[0]['unit'] ?? null;
pin('the quote sells it at the listing price', 12000000, $unit($one));

$edit = static fn (int $id, array $extra): int => $service->update(
    ListingInput::fromArray($form(['id' => (string) $id, 'ownerId' => '0'] + $extra), $adminActor, false),
    $adminActor,
    false,
    false
)->id();
$edit($one, ['product' => ['sell' => '1', 'stock' => '9', 'sku' => '']]);
$got = $rows($one);
pin('an edit changes the row, not adds one', 1, count($got));
pin('a sent member changes, an empty one clears, one left out stays', ['9', null], [$got[0]['i_stock'], $got[0]['s_sku']]);
VariantStore::saveDefault($one, ['i_price' => 9500000]);
$edit($one, ['price' => '14', 'product' => ['sell' => '1', 'price' => '9.50']]);
pin('an admin save stores no price on the default row, even one posted', [null, '14000000'], [$rows($one)[0]['i_price'], $listingPrice($one)]);
pin('and the quote follows the new listing price', 14000000, $unit($one));
$edit($one, []);
pin('a form with no product leaves the row alone', '9', $rows($one)[0]['i_stock']);
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
$noCurrency = null;
try {
    $service->create(ListingInput::fromArray($form(['ownerId' => '0', 'currency' => '', 'product' => $sell]), $adminActor, true), $adminActor);
} catch (\mindstellar\exception\InvalidException $e) {
    $noCurrency = $codes($e->errors());
}
pin('a product saved with no currency is refused', ['/currency'], $noCurrency);

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
pin('with the sent values; the price goes to the listing', ['P-1', null, '2', '120', 'letter', '7250000'], [$got[0]['s_sku'], $got[0]['i_price'], $got[0]['i_stock'], $got[0]['i_weight'], $got[0]['s_shipping_class'], $listingPrice($plain)]);
$call('PATCH', 'admin/listings/' . $plain, ['price' => '8', 'product' => ['price' => '7.25']], $boss);
pin('a listing price sent too wins over product.price', [null, '8000000'], [$rows($plain)[0]['i_price'], $listingPrice($plain)]);
$call('PATCH', 'admin/listings/' . $plain, ['product' => ['price' => null]], $boss);
pin('product.price null changes nothing', [null, '8000000'], [$rows($plain)[0]['i_price'], $listingPrice($plain)]);
$call('PATCH', 'admin/listings/' . $plain, ['product' => ['price' => '7.25']], $boss);
pin('the response matches the schema', [], api_admin_schema_errors('ListingDocument', $call('GET', 'admin/listings/' . $plain, null, $boss)));

$r       = $call('GET', 'listings/' . $plain, null, $tomKey);
$product = $r->body()['data']['product'] ?? null;
pin('the public view shows the product', true, $product['buyable'] ?? null);
pin('one variant, with stock reduced to in_stock', [1, true, 'P-1', null, 120, 'letter', false], [
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

$shown = ListingInput::productForm($plain);
pin('the admin form reads the default row', [true, 'P-1', '2', '120', 'letter'], [$shown['sell'], $shown['sku'], $shown['stock'], $shown['weight'], $shown['shippingClass']]);
pin('a listing with no row shows an unticked form', [false, ''], [ListingInput::productForm($mine)['sell'], ListingInput::productForm(0)['sku']]);
$mode = Preference::getInstance()->get('site_mode');
Preference::getInstance()->set('site_mode', 'shop');
pin('a new product in shop mode starts for sale', [true, false], [ListingInput::productForm(0)['sell'], ListingInput::productForm($mine)['sell']]);
Preference::getInstance()->set('site_mode', $mode === null || $mode === '' ? 'classifieds' : $mode);
pin('a new listing in classifieds mode does not', false, ListingInput::productForm(0)['sell']);

$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => null], $boss);
pin('product null stops selling', [200, []], [$r->status(), $rows($plain)]);

harness_section('was price');
$variant = static fn (int $id): array => $call('GET', 'listings/' . $id, null, $tomKey)->body()['data']['product']['variants'][0] ?? [];
$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => ['price' => '7.25', 'compare_at' => '9.50']], $boss);
pin('an admin saves compare_at', [200, '9500000'], [$r->status(), $rows($plain)[0]['i_compare_price']]);
pin('the public view shows it, on sale', ['9.50', true], [$variant($plain)['compare_at'] ?? null, $variant($plain)['on_sale'] ?? null]);
pin('the response matches the schema', [], api_admin_schema_errors('ListingDocument', $call('GET', 'admin/listings/' . $plain, null, $boss)));
$call('PATCH', 'admin/listings/' . $plain, ['product' => ['compare_at' => '7.25']], $boss);
pin('equal to the price is not on sale', ['7.25', false], [$variant($plain)['compare_at'] ?? null, $variant($plain)['on_sale'] ?? null]);
$call('PATCH', 'admin/listings/' . $plain, ['product' => ['compare_at' => '5']], $boss);
pin('below the price is not on sale', false, $variant($plain)['on_sale'] ?? null);
$call('PATCH', 'admin/listings/' . $plain, ['product' => ['price' => null, 'compare_at' => '12']], $boss);
pin('a variant with no price compares to the listing price (7.25)', [null, '12.00', true], [$variant($plain)['price'], $variant($plain)['compare_at'], $variant($plain)['on_sale']]);
$r = $call('PATCH', 'admin/listings/' . $plain, ['product' => ['compare_at' => 'cheap']], $boss);
pin('a bad compare_at is refused', 422, $r->status());
$call('PATCH', 'admin/listings/' . $plain, ['product' => ['compare_at' => null]], $boss);
pin('null clears it', [null, false, null], [$variant($plain)['compare_at'], $variant($plain)['on_sale'], $rows($plain)[0]['i_compare_price']]);
$r = $call('PATCH', 'listings/' . $mine, ['product' => ['compare_at' => '99']], $userKey);
pin('a user cannot set it', [422, []], [$r->status(), $rows($mine)]);
$data = ListingInput::fromArray($form(['product' => $sell + ['compareAt' => '12.00']]), $userActor, true);
pin('a user\'s form carries no product, so no was price', false, array_key_exists('product', $data));
$data = ListingInput::fromArray($form(['ownerId' => '0', 'product' => $sell + ['compareAt' => '12.00']]), $adminActor, true);
pin('an admin\'s form carries it', 12000000, $data['product']['fields']['i_compare_price'] ?? null);
VariantStore::saveDefault($mine, ['i_price' => 5000000, 'i_compare_price' => 8000000]);
pin('the admin form reads it back', '8.00', ListingInput::productForm($mine)['compareAt']);
VariantStore::removeDefault($mine);

harness_section('shop mode');
if (!function_exists('osc_content_path')) {
    function osc_content_path(): string
    {
        return sys_get_temp_dir() . '/osc-item-variant/';
    }
}
Preference::getInstance()->set('site_mode', 'shop');
$refused = null;
try {
    (new ListingService())->create(ListingInput::fromArray($form(), $userActor, true), $userActor);
} catch (\mindstellar\exception\ForbiddenException $e) {
    $refused = $e->reason();
}
pin('the service refuses a user', \mindstellar\exception\ForbiddenException::DISABLED, $refused);
check('the service lets an admin post', (new ListingService())->create(ListingInput::fromArray($form(['ownerId' => '0']), $adminActor, true), $adminActor)->id() > 0);
$region = seed_region($admin, 'US', 'Texas');
$r      = $call('POST', 'listings', [
    'category_id' => $cat, 'title' => 'Shop mug', 'description' => 'A hand made ceramic mug, dishwasher safe.', 'price' => '12',
    'currency'    => 'USD', 'country' => 'US', 'region_id' => $region, 'city_id' => seed_city($admin, $region, 'Austin', 'US'),
], $userKey);
pin('the API refuses a user key with 403', '403 feature_disabled', api_admin_code($r));
Preference::getInstance()->set('site_mode', 'classifieds');

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

// Two buyers at stock 1 in two processes, both calling adjustStock(). A row lock held here
// makes both wait, so they run against the same stock once it is released.
$race   = VariantStore::saveDefault($plain, ['i_stock' => 1]);
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$admin->begin_transaction();
$admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $race FOR UPDATE");
$buyers = [];
$outs   = [];
for ($i = 0; $i < 2; $i++) {
    $buyers[] = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/lib/variant-stock-child.php', $dbName, (string) $race], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outs[$i] = $pipes;
}
$watch   = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $dbName, (int) ini_get('mysqli.default_port'));
$waiting = 0;
for ($try = 0; $try < 400 && $waiting < 2; $try++) {
    usleep(50000);
    // The process list, not INNODB_TRX: that one can drop rows while other tests run beside this one.
    $waiting = (int) $watch->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = '{$dbName}' AND COMMAND = 'Query' AND INFO LIKE 'UPDATE {$p}t_item_variant%'")->fetch_row()[0];
}
$watch->close();
$admin->commit();
$printed = '';
foreach ($buyers as $i => $proc) {
    $printed .= stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]);
    proc_close($proc);
}
pin('both buyers waited on the row', 2, $waiting);
pin('two buyers at stock 1: one wins, one is refused', [1, 1], [substr_count($printed, 'won'), substr_count($printed, 'lost')]);
pin('and the stock ends at 0', '0', (string) $admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $race")->fetch_row()[0]);

harness_section('option rows: the rules');
$tee    = static fn (array $extra = []): array => ['sell' => true, 'fields' => [], 'variants' => $extra];
$row    = static fn (array $options, array $fields = []): array => ['options' => $options, 'fields' => $fields];
$combos = [];
foreach (['Sunflower', 'Cherry'] as $colour) {
    foreach (['S', 'M', 'L', 'XL'] as $size) {
        $combos[] = $row(['Colour' => $colour, 'Size' => $size]);
    }
}
pin('eight good rows pass', [], $v->product($tee($combos)));
pin('no rows', ['/product/variants'], $codes($v->product($tee([]))));
pin('more than 100 rows', ['/product/variants'], $codes($v->product($tee(array_map(static fn (int $i): array => $row(['Size' => 'S' . $i]), range(1, 101))))));
pin('100 rows pass', [], $v->product($tee(array_map(static fn (int $i): array => $row(['Size' => 'S' . ($i % 30), 'Fit' => 'F' . intdiv($i, 30)]), range(0, 99)))));
pin('four option names', ['/product/options'], $codes($v->product($tee([$row(['A' => '1', 'B' => '1', 'C' => '1', 'D' => '1'])]))));
pin('three pass', [], $v->product($tee([$row(['A' => '1', 'B' => '1', 'C' => '1'])])));
pin('a name over 30 characters', ['/product/options'], $codes($v->product($tee([$row([str_repeat('n', 31) => 'x'])]))));
pin('a name of 30 passes', [], $v->product($tee([$row([str_repeat('n', 30) => 'x'])])));
pin('names that differ only by case', [['/product/options', 'taken']], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $v->product($tee([$row(['Size' => 'M', 'SIZE' => 'M'])]))));
pin('names that differ only by accents', [['/product/options', 'taken']], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $v->product($tee([$row(['Café' => 'M', 'CAFE' => 'M'])]))));
pin('an empty name', ['/product/options'], $codes($v->product($tee([$row(['' => 'x'])]))));
pin('a value over 40 characters', ['/product/variants/0/options'], $codes($v->product($tee([$row(['Size' => str_repeat('v', 41)])]))));
pin('a value of 40 passes, counted in characters', [], $v->product($tee([$row(['Size' => str_repeat('é', 40)])])));
pin('a spaces-only name', ['/product/options'], $codes($v->product($tee([$row(['  ' => 'x'])]))));
pin('names and values are trimmed before they are compared', [['/product/variants/1/options', 'taken']], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $v->product($tee([$row(['Size' => 'S']), $row([' Size ' => ' S '])]))));
pin('an empty value', ['/product/variants/0/options'], $codes($v->product($tee([$row(['Size' => ' '])]))));
pin('31 values under one name', ['/product/options'], $codes($v->product($tee(array_map(static fn (int $i): array => $row(['Size' => 'S' . $i]), range(1, 31))))));
pin('30 pass', [], $v->product($tee(array_map(static fn (int $i): array => $row(['Size' => 'S' . $i]), range(1, 30)))));
pin('a row missing a name', ['/product/variants/1/options'], $codes($v->product($tee([$row(['Colour' => 'Red', 'Size' => 'S']), $row(['Colour' => 'Red'])]))));
pin('a row with a name the first lacks', ['/product/variants/1/options'], $codes($v->product($tee([$row(['Colour' => 'Red']), $row(['Colour' => 'Red', 'Size' => 'S'])]))));
pin('a row with an extra name is a mismatch, not a duplicate', [['/product/variants/1/options', 'mismatch']], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $v->product($tee([$row(['Colour' => 'Red', 'Size' => 'S']), $row(['Colour' => 'Blue', 'Size' => 'M', 'Fit' => 'Slim'])]))));
pin('a duplicate combination, whatever the key order', ['/product/variants/2/options'], $codes($v->product($tee([$row(['Colour' => 'Red', 'Size' => 'S']), $row(['Colour' => 'Red', 'Size' => 'M']), $row(['Size' => 'S', 'Colour' => 'Red'])]))));
pin('a bad row field names its row', ['/product/variants/1/sku', '/product/variants/1/stock'], $codes($v->product($tee([$row(['Size' => 'S']), $row(['Size' => 'M'], ['s_sku' => str_repeat('x', 65), 'i_stock' => -1])]))));
pin('a photo that is not the listing\'s', ['/product/variants/0/photo_id'], $codes($v->product($tee([$row(['Size' => 'S'], ['fk_i_resource_id' => 77])]), [5, 6])));
pin('a photo that is passes', [], $v->product($tee([$row(['Size' => 'S'], ['fk_i_resource_id' => 6])]), [5, 6]));
pin('a photo id that is not a number', ['/product/variants/0/photo_id'], $codes($v->product($tee([$row(['Size' => 'S'], ['fk_i_resource_id' => 'six'])]), [5, 6])));
pin('a row message says which row', 'Variant 2: SKU too long.', $v->product($tee([$row(['Size' => 'S']), $row(['Size' => 'M'], ['s_sku' => str_repeat('x', 65)])]))[0]['message'] ?? null);

harness_section('option rows: saveSet');
harness_reset_container();
$fired = [];
osc_add_hook('variant_stock_changed', static function (int $id, int $item, ?int $from, ?int $to) use (&$fired): void {
    $fired[] = ['stock', $id, $item, $from, $to];
});
osc_add_hook('variant_price_changed', static function (int $id, int $item, ?int $from, ?int $to) use (&$fired): void {
    $fired[] = ['price', $id, $item, $from, $to];
});
$set = seed_item($admin, $cat, $sue, 'Tee', 10.0);
VariantStore::saveDefault($set, ['i_stock' => 4]);
$set8 = array_map(static fn (array $r, int $i): array => ['options' => $r['options'], 'fields' => ['s_sku' => 'TEE-' . $i, 'i_price' => 20000000, 'i_stock' => $i]], $combos, array_keys($combos));
$ids  = VariantStore::saveSet($set, $set8);
$got  = $rows($set);
pin('eight rows saved, the default row gone', [8, 0], [count($got), count(array_filter($got, static fn (array $r): bool => $r['s_options'] === null))]);
pin('options stored in the listing\'s order', '{"Colour":"Sunflower","Size":"S"}', $got[0]['s_options']);
pin('positions follow the row order', range(0, 7), array_map('intval', array_column($got, 'i_position')));
pin('ids come back in order', array_map('intval', array_column($got, 'pk_i_id')), $ids);
pin('new rows fire no hooks', [], $fired);
pin('optionsOf, first-seen order', [['name' => 'Colour', 'values' => ['Sunflower', 'Cherry']], ['name' => 'Size', 'values' => ['S', 'M', 'L', 'XL']]], VariantStore::optionsOf($got));

// Reorder, drop Sunflower XL, add Navy M, change two rows.
$edit2 = array_reverse($set8);
array_shift($edit2);
$edit2[0]['fields']['i_stock'] = 9;
$edit2[1]['fields']['i_price'] = 15000000;
$edit2[] = ['options' => ['Size' => 'M', 'Colour' => 'Navy'], 'fields' => ['i_stock' => 1]];
$fired   = [];
$ids2    = VariantStore::saveSet($set, $edit2);
$byOpt   = static function (int $item) use ($rows): array {
    $out = [];
    foreach ($rows($item) as $r) {
        $out[$r['s_options']] = $r;
    }

    return $out;
};
$after = $byOpt($set);
pin('an unchanged combination keeps its id', $ids[1], (int) $after['{"Colour":"Sunflower","Size":"M"}']['pk_i_id']);
pin('a row sent with its keys in another order keeps its id too', true, isset($after['{"Colour":"Navy","Size":"M"}']) && !in_array((int) $after['{"Colour":"Navy","Size":"M"}']['pk_i_id'], $ids, true));
pin('the dropped row is deleted', [8, false], [count($after), isset($after['{"Colour":"Cherry","Size":"XL"}'])]);
pin('positions renumber to the new order', [0, 6], [(int) $after['{"Colour":"Cherry","Size":"L"}']['i_position'], (int) $after['{"Colour":"Sunflower","Size":"S"}']['i_position']]);
pin('the hooks fire once per changed row, with from and to', [['stock', $ids[6], $set, 6, 9], ['price', $ids[5], $set, 20000000, 15000000]], $fired);

$ids3 = VariantStore::saveSet($set, array_map(static fn (array $r): array => ['options' => array_reverse($r['options'], true)] + $r, $edit2));
pin('rows sent with their options in another key order keep their ids', array_map('intval', $ids2), array_map('intval', $ids3));
$was = array_keys($after);
sort($was);
$ids4 = VariantStore::saveSet($set, array_map(static fn (array $r): array => ['options' => array_combine(array_map(static fn (string $k): string => " $k ", array_keys($r['options'])), array_map(static fn (string $v): string => " $v ", $r['options']))] + $r, $edit2));
pin('padded names and values are stored trimmed, on the same rows', [array_map('intval', $ids3), $was], [array_map('intval', $ids4), (static function () use ($rows, $set): array {
$o = array_column($rows($set), 's_options');
sort($o);
return $o;
})()]);

$fired = [];
VariantStore::saveSet($set, $edit2);
pin('saving the same rows again fires nothing', [], $fired);
$edit3                         = $edit2;
$edit3[0]['fields']['i_price'] = null;
VariantStore::saveSet($set, $edit3);
pin('a price cleared to the listing price reports the price it sells at', [['price', $ids[6], $set, 20000000, 10000000]], $fired);

$fired = [];
VariantStore::adjustStock($ids[6], -2);
pin('adjustStock fires the stock hook', [['stock', $ids[6], $set, 9, 7]], $fired);
$fired = [];
VariantStore::adjustStock($ids[6], -99);
pin('a refused change fires nothing', [], $fired);

$fired = [];
VariantStore::saveDefault($set, ['i_stock' => 3]);
pin('saveDefault removes the option rows', [1, null], [count($rows($set)), $rows($set)[0]['s_options']]);
pin('a new default row fires nothing', [], $fired);
VariantStore::saveDefault($set, ['i_stock' => 0]);
pin('a kept default row fires the stock hook', [['stock', (int) $rows($set)[0]['pk_i_id'], $set, 3, 0]], $fired);

harness_section('option rows: the admin form and API');
$photoA = (int) Db::table($p . 't_resource')->insert(['s_owner_type' => \ItemResource::OWNER, 'i_owner_id' => $set, 's_name' => 'a', 's_extension' => 'jpg', 's_content_type' => 'image/jpeg', 's_path' => 'oc-content/uploads/0/', 'dt_created' => date('Y-m-d H:i:s')]);
$photoB = (int) Db::table($p . 't_resource')->insert(['s_owner_type' => \ItemResource::OWNER, 'i_owner_id' => $set, 's_name' => 'b', 's_extension' => 'jpg', 's_content_type' => 'image/jpeg', 's_path' => 'oc-content/uploads/0/', 'dt_created' => date('Y-m-d H:i:s')]);
$other  = (int) Db::table($p . 't_resource')->insert(['s_owner_type' => \ItemResource::OWNER, 'i_owner_id' => $plain, 's_name' => 'c', 's_extension' => 'jpg', 's_content_type' => 'image/jpeg', 's_path' => 'oc-content/uploads/0/', 'dt_created' => date('Y-m-d H:i:s')]);
$posted = ['sell' => '1', 'hasOptions' => '1', 'optionNames' => [' Colour ', 'Size'], 'variants' => [
    ['options' => ['Sunflower', 'S'], 'sku' => 'T-1', 'price' => '25', 'stock' => '12', 'photoId' => (string) $photoA],
    ['options' => ['Sunflower', ' M  '], 'sku' => 'T-2', 'price' => '25', 'stock' => '3', 'photoId' => (string) $photoA],
    ['options' => ['Cherry', 'S'], 'sku' => 'T-3', 'stock' => '0', 'photoId' => (string) $photoB, 'compareAt' => '30'],
]];
$data = ListingInput::fromArray($form(['ownerId' => '0', 'product' => $posted]), $adminActor, true);
pin('the form posts names and aligned values; spaces trimmed and collapsed', [['Colour' => 'Sunflower', 'Size' => 'S'], ['Colour' => 'Sunflower', 'Size' => 'M']], array_column(array_slice($data['product']['variants'] ?? [], 0, 2), 'options'));
$third = $data['product']['variants'][2]['fields'] ?? [];
ksort($third);
pin('and the row fields as the default row reads them', ['fk_i_resource_id' => $photoB, 'i_compare_price' => 30000000, 'i_stock' => 0, 's_sku' => 'T-3'], $third);
pin('a user\'s form carries no rows', false, array_key_exists('product', ListingInput::fromArray($form(['product' => $posted]), $userActor, true)));
$edit($set, ['product' => $posted]);
pin('an admin edit saves the option rows', ['{"Colour":"Sunflower","Size":"S"}', '{"Colour":"Sunflower","Size":"M"}', '{"Colour":"Cherry","Size":"S"}'], array_column($rows($set), 's_options'));
$bad = null;
try {
    $edit($set, ['product' => ['variants' => [['options' => ['Red', 'S'], 'photoId' => (string) $other]]] + $posted]);
} catch (\mindstellar\exception\InvalidException $e) {
    $bad = $codes($e->errors());
}
pin('a photo of another listing refuses the save', ['/product/variants/0/photo_id'], $bad);
pin('and the rows are as they were', 3, count($rows($set)));
$bad = null;
try {
    (new ListingService())->create(ListingInput::fromArray($form(['ownerId' => '0', 'product' => $posted]), $adminActor, true), $adminActor);
} catch (\mindstellar\exception\InvalidException $e) {
    $bad = $codes($e->errors());
}
pin('a new listing has no photos for a row to name', ['/product/variants/0/photo_id', '/product/variants/1/photo_id', '/product/variants/2/photo_id'], $bad);

$shown = ListingInput::productForm($set);
pin('the admin form reads the rows back', [true, ['Colour', 'Size'], ['Cherry', 'S'], 'T-3', '30.00', (string) $photoB], [$shown['hasOptions'], array_column($shown['options'], 'name'), $shown['variants'][2]['values'], $shown['variants'][2]['sku'], $shown['variants'][2]['compareAt'], $shown['variants'][2]['photoId']]);
pin('and offers the listing\'s photos', [$photoA, $photoB], array_keys($shown['photos']));
pin('a plain listing shows no options', [false, []], [ListingInput::productForm($plain)['hasOptions'], ListingInput::productForm($plain)['variants']]);

$r       = $call('GET', 'listings/' . $set, null, $tomKey);
$product = $r->body()['data']['product'] ?? [];
pin('the API shows options in first-seen order', [['name' => 'Colour', 'values' => ['Sunflower', 'Cherry']], ['name' => 'Size', 'values' => ['S', 'M']]], $product['options'] ?? null);
pin('each variant carries its photo', [$photoA, $photoA, $photoB], array_map(static fn (array $v): ?int => $v['photo']['id'] ?? null, $product['variants'] ?? []));
pin('beside photo_id', [$photoA, $photoA, $photoB], array_column($product['variants'] ?? [], 'photo_id'));
pin('the response matches the schema', [], api_admin_schema_errors('ListingDocument', $r));
$r = $call('GET', 'listings/' . $plain, null, $tomKey);
pin('a plain product has options []', [], $r->body()['data']['product']['options'] ?? null);
$first = $r->body()['data']['product']['variants'][0] ?? [];
pin('and a variant with no photo has photo null', [true, null], [array_key_exists('photo', $first), $first['photo'] ?? null]);
$r = $call('GET', 'listings/' . $set, null, $tomKey, [], ['fields' => 'id,product']);
pin('a fieldset with product but not photos still gets the photo', [$photoA, false], [$r->body()['data']['product']['variants'][0]['photo']['id'] ?? null, array_key_exists('photos', $r->body()['data'] ?? [])]);

$keep = (int) $rows($set)[1]['pk_i_id'];
$r    = $call('PATCH', 'admin/listings/' . $set, ['product' => ['variants' => [
    ['options' => ['Colour' => 'Sunflower', 'Size' => 'M'], 'sku' => 'T-2', 'price' => '22.50', 'stock' => 5, 'photo_id' => $photoA],
    ['options' => ['Colour' => 'Navy', 'Size' => 'M'], 'stock' => 2],
]]], $boss);
pin('an admin PATCH replaces the rows', [200, 2], [$r->status(), count($rows($set))]);
pin('and keeps the id of a kept combination', $keep, (int) $rows($set)[0]['pk_i_id']);
pin('with the sent values', ['T-2', '22500000', '5', (string) $photoA], [$rows($set)[0]['s_sku'], $rows($set)[0]['i_price'], $rows($set)[0]['i_stock'], $rows($set)[0]['fk_i_resource_id']]);
$quoted = (new \mindstellar\billing\CartPricer())->quote([['variant' => (int) $rows($set)[0]['pk_i_id'], 'qty' => 1], ['variant' => (int) $rows($set)[1]['pk_i_id'], 'qty' => 1]], [])->lines();
pin('an option row\'s price beats the listing price, a blank one inherits it', [22500000, (int) $listingPrice($set)], array_column($quoted, 'unit'));
$r = $call('PATCH', 'admin/listings/' . $set, ['product' => ['variants' => [['options' => ['Colour' => 'Navy']], ['options' => ['Colour' => 'Navy']]]]], $boss);
pin('a duplicate combination is a 422 with its path', [422, ['/product/variants/1/options']], [$r->status(), array_column($r->body()['errors'] ?? [], 'pointer')]);
$r = $call('PATCH', 'admin/listings/' . $set, ['product' => ['variants' => [['options' => ['Colour' => 'Navy'], 'photo_id' => $other]]]], $boss);
pin('a photo of another listing is a 422 with its path', [422, ['/product/variants/0/photo_id']], [$r->status(), array_column($r->body()['errors'] ?? [], 'pointer')]);
$r = $call('PATCH', 'admin/listings/' . $set, ['product' => ['variants' => [['sku' => 'no options']]]], $boss);
pin('a row without options is a 422', 422, $r->status());
pin('and the rows are as they were', 2, count($rows($set)));
$before = $rows($mine);
$r      = $call('PATCH', 'listings/' . $mine, ['product' => ['variants' => [['options' => ['Colour' => 'Navy']]]]], $userKey);
pin('a user\'s PATCH with rows is refused', [422, $before], [$r->status(), $rows($mine)]);
$r = $call('PATCH', 'admin/listings/' . $set, ['product' => ['sku' => 'ONE']], $boss);
pin('the default members turn it back into one default row', [200, 1, null, 'ONE'], [$r->status(), count($rows($set)), $rows($set)[0]['s_options'], $rows($set)[0]['s_sku']]);
$call('PATCH', 'admin/listings/' . $set, ['product' => ['variants' => [['options' => ['Colour' => 'Navy']]]]], $boss);
$r = $call('PATCH', 'admin/listings/' . $set, ['product' => null], $boss);
pin('product null removes the option rows too', [200, []], [$r->status(), $rows($set)]);

harness_section('delete and upgrade');
VariantStore::saveDefault($one, ['s_sku' => 'DEL-1']);
pin('the listing has a row to lose', 1, count($rows($one)));
Item::getInstance()->deleteByPrimaryKey($one);
pin('deleting the listing removes its variants', [], $rows($one));
$gone = $service->create(ListingInput::fromArray($form(['ownerId' => '0']), $adminActor, true), $adminActor)->id();
VariantStore::saveDefault($gone, ['s_sku' => 'DEL-2']);
\mindstellar\database\Db::execute('DELETE FROM ' . $p . 't_item WHERE pk_i_id = ?', [$gone]);
pin('a listing deleted in SQL takes its variants too', [], $rows($gone));

$ddl = static fn (): array => array_map('array_values', $admin->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item_variant' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC));
$idx = static fn (): array => array_map('array_values', $admin->query("SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item_variant' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetch_all(MYSQLI_ASSOC));
$fks   = static fn (): array => array_map('array_values', $admin->query("SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '{$p}t_item_variant' ORDER BY k.COLUMN_NAME")->fetch_all(MYSQLI_ASSOC));
pin('rows cascade with the listing and a photo delete clears the link', [['fk_i_item_id', $p . 't_item', 'CASCADE'], ['fk_i_resource_id', $p . 't_resource', 'SET NULL']], $fks());
$fresh = [$ddl(), $idx(), $fks()];
$admin->query("DROP TABLE {$p}t_item_variant");
$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0076_item_variant.php';
$migration->up(\mindstellar\database\Connection::getInstance());
pin('the first migration leaves the table without the was price', false, in_array('i_compare_price', array_column($ddl(), 0), true));
$compare = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0078_item_variant_compare_price.php';
$compare->up(\mindstellar\database\Connection::getInstance());
pin('the migrations build the table struct.sql builds', $fresh, [$ddl(), $idx(), $fks()]);
$migration->up(\mindstellar\database\Connection::getInstance());
$compare->up(\mindstellar\database\Connection::getInstance());
pin('and run again without error', $fresh, [$ddl(), $idx(), $fks()]);

exit(harness_result());
