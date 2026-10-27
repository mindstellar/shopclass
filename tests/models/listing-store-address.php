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
 * An admin adds a shop product with no location: it lands in the store's city. A typed
 * location, an edit with the location left out over the API, and classifieds are not touched.
 *
 * Usage:  php tests/models/listing-store-address.php       (standalone, own scratch database)
 *         php tests/run-models.php listing-store-address   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;

$admin = api_admin_boot('osc_models_listing_store_address');
$p     = DB_TABLE_PREFIX;
$en    = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$texas = seed_region($admin, 'US', 'Texas');
$austin = seed_city($admin, $texas, 'Austin', 'US');
$ohio   = seed_region($admin, 'US', 'Ohio');
$dayton = seed_city($admin, $ohio, 'Dayton', 'US');
$cat = seed_category($admin, 'Mugs', null, $en);
$set = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$set(['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'title_character_length' => '100',
    'description_character_length' => '5000', 'site_mode' => 'shop',
    'shop_country' => 'United States', 'shop_region' => 'Texas', 'shop_city' => 'Austin']);
$_SERVER['REMOTE_ADDR'] = '192.0.2.72';

$actor   = Actor::admin(1, '192.0.2.72');
$service = new ListingService();
$form    = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $cat,
    'countryId'    => '', 'country' => '', 'region' => '', 'regionId' => '', 'city' => '', 'cityId' => '',
    'title'        => ['en_US' => 'Blue mug'],
    'description'  => ['en_US' => 'A blue mug that holds a full cup of coffee.'],
    'price'        => '9',
    'contactName'  => 'Office',
    'contactEmail' => 'office@example.test',
    'contactPhone' => '5550199',
];
$place = static fn (int $id): array => array_values((array) $admin->query("SELECT s_country, s_region, s_city, fk_i_city_id FROM {$p}t_item_location WHERE fk_i_item_id = $id")->fetch_assoc());
$add   = static fn (array $extra = []): int => $service->create(ListingInput::fromArray($form($extra), $actor, true), $actor)->id();

harness_section('adding in shop mode');
pin('no location: the store city', array('United States', 'Texas', 'Austin', (string) $austin), $place($add()));
pin('a typed city is kept', array('United States', 'Ohio', 'Dayton', (string) $dayton), $place($add(['countryId' => 'US', 'regionId' => (string) $ohio, 'cityId' => (string) $dayton])));

harness_section('editing');
$id = $add(['countryId' => 'US', 'regionId' => (string) $ohio, 'cityId' => (string) $dayton]);
$bare = $form();
foreach (['countryId', 'country', 'region', 'regionId', 'city', 'cityId'] as $key) {
    unset($bare[$key]);
}
$data = ListingInput::fromArray(['id' => $id] + $bare, $actor, false);
pin('an edit with the location left out over the API is not given the store city', false, ($data['cityName'] ?? '') === 'Austin');
pin('an edit form with the location cleared takes the store address', 'Austin', ListingInput::fromArray(['id' => $id, 'city' => ''] + $form(), $actor, false)['cityName']);

harness_section('classifieds');
$set(['site_mode' => 'classifieds']);
$data = ListingInput::fromArray($form(), $actor, true);
pin('a classifieds listing gets no store address', array('', ''), array($data['countryName'] ?? '', $data['cityName'] ?? ''));

exit(harness_result());
