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
 * ListingBody: a listing body as the listing form posts it. A new listing maps each member
 * to its form field; an edit starts from the stored listing, so a member not sent keeps its
 * value; prices are written in the site's own decimal format; uploads are routes of their
 * own in the OpenAPI document.
 *
 * DB-free.  Usage: php tests/api-listing-body.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\Request;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\serializer\ViewContext;
use mindstellar\api\write\CustomFieldValues;
use mindstellar\api\write\ListingBody;
use mindstellar\api\write\OwnedListing;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\listing\ListingPolicy;

$form = new ListingBody(',', 'en_US');
$pick = static function (array $form, array $keys): array {
    $out = array_intersect_key($form, array_flip($keys));
    ksort($out);

    return $out;
};
$sorted = static function (array $a): array {
    ksort($a);

    return $a;
};

harness_section('a new listing');
$new = $form->create([
    'category_id'  => 7,
    'title'        => 'Bike',
    'description'  => 'A fast bike',
    'translations' => ['de_DE' => ['title' => 'Fahrrad']],
    'price'        => '12.50',
    'country'      => 'us',
    'region_id'    => 3,
    'city'         => 'Smallville',
    'lat'          => 40.5,
    'show_email'   => true,
    'custom_fields' => ['4' => 'red', '5' => true, 'x' => 'dropped'],
]);
pin('members map to the form\'s fields', $sorted([
    'catId' => '7', 'countryId' => 'US', 'regionId' => '3', 'city' => 'Smallville', 'd_coord_lat' => '40.5', 'showEmail' => '1',
]), $pick($new, ['catId', 'countryId', 'regionId', 'city', 'd_coord_lat', 'showEmail']));
pin('title and description go under their locale; a translation gets an empty description', [
    ['en_US' => 'Bike', 'de_DE' => 'Fahrrad'], ['en_US' => 'A fast bike', 'de_DE' => ''],
], [$new['title'], $new['description']]);
pin('the price is in the site\'s decimal format', '12,50', $new['price']);
pin('a number price too', ['9', '9,99'], [$form->create(['price' => 9])['price'], $form->create(['price' => 9.99])['price']]);
pin('custom fields by id; a non-id key is dropped', [4 => 'red', 5 => '1'], $new['meta']);

harness_section('an edit');
$stored = $form->stored(
    new OwnedListing(
        ['pk_i_id' => '1', 'fk_i_category_id' => '7', 'i_price' => '1500000000', 'fk_c_currency_code' => 'USD', 'fk_c_country_code' => 'US', 's_country' => 'United States',
         'fk_i_region_id' => '3', 's_region' => 'Alpha', 'fk_i_city_id' => '9', 's_city' => 'Aville', 's_city_area' => '', 's_address' => 'Main St 1',
         's_zip' => '12345', 'd_coord_lat' => null, 'd_coord_long' => null, 's_contact_phone' => '555', 'b_show_email' => '0'],
        ['en_US' => ['Car', 'A car'], 'de_DE' => ['Auto', 'Ein Auto']]
    ),
    [['fk_i_field_id' => '4', 's_multi' => '', 's_value' => 'blue'], ['fk_i_field_id' => '6', 's_multi' => 'from', 's_value' => '100'], ['fk_i_field_id' => '6', 's_multi' => 'to', 's_value' => '200']]
);
pin('the stored listing reads back as its form', $sorted([
    'catId' => '7', 'price' => '1500,00', 'currency' => 'USD', 'cityId' => '9', 'address' => 'Main St 1', 'contactPhone' => '555', 'showEmail' => '0',
]), $pick($stored, ['catId', 'price', 'currency', 'cityId', 'address', 'contactPhone', 'showEmail']));
pin('with every language and a date range field', [['en_US' => 'Car', 'de_DE' => 'Auto'], [4 => 'blue', 6 => ['from' => '100', 'to' => '200']]], [$stored['title'], $stored['meta']]);
$patched = $form->patch($stored, ['price' => '99', 'custom_fields' => ['4' => null, '7' => 'new'], 'city_id' => null, 'city' => 'Bville']);
pin('a patch changes only what it names', $sorted([
    'price' => '99', 'cityId' => '', 'city' => 'Bville', 'address' => 'Main St 1', 'title' => ['en_US' => 'Car', 'de_DE' => 'Auto'],
]), $pick($patched, ['price', 'cityId', 'city', 'address', 'title']));
pin('a null field removes it; others stay', [6 => ['from' => '100', 'to' => '200'], 7 => 'new'], $patched['meta']);
pin('a title in another locale leaves the default one', ['en_US' => 'Car', 'de_DE' => 'Wagen'], $form->patch($stored, ['locale' => 'de_DE', 'title' => 'Wagen'])['title']);
pin('a null price clears it', '', $form->patch($stored, ['price' => null])['price']);
$cleared = $form->patch($stored, ['address' => null, 'contact_phone' => null, 'translations' => ['de_DE' => null]]);
pin('null clears an optional member, and removes a language', ['', '', ['en_US' => 'Car', 'de_DE' => '']], [$cleared['address'], $cleared['contactPhone'], $cleared['title']]);
pin('a city id clears the stored city name', ['', '12'], [$form->patch($stored, ['city_id' => 12])['city'], $form->patch($stored, ['city_id' => 12])['cityId']]);

harness_section('uploads');
$doc  = OpenApi::core()->build();
$post = $doc['paths']['/listings/{id}/photos']['post'];
pin('a photo upload takes a multipart form or the image itself', ['multipart/form-data', 'image/*'], array_keys($post['requestBody']['content']));
check('and can answer 413, 415 and 422', isset($post['responses']['413'], $post['responses']['415'], $post['responses']['422']));
pin('POST /listings takes a JSON body', ['application/json'], array_keys($doc['paths']['/listings']['post']['requestBody']['content']));
pin('PATCH takes a JSON Merge Patch too', ['application/json', 'application/merge-patch+json'], array_keys($doc['paths']['/listings/{id}']['patch']['requestBody']['content']));
$missing = [];
foreach ($doc['paths'] as $path => $operations) {
    foreach ($operations as $method => $operation) {
        if (isset($operation['responses']['201']) && !isset($operation['responses']['201']['headers']['Location'])) {
            $missing[] = strtoupper($method) . ' ' . $path;
        }
    }
}
pin('every 201 documents its Location, but sign-up, whose account cannot be read yet', ['POST /users'], $missing);

harness_section('custom fields');
$fields = new CustomFieldValues(static fn (int $category): array => $category === 5
    ? [['pk_i_id' => '1', 'e_type' => 'TEXT'], ['pk_i_id' => '3', 'e_type' => 'DATEINTERVAL'], ['pk_i_id' => '4', 'e_type' => 'CHECKBOX'], ['pk_i_id' => '5', 'e_type' => 'DATE']]
    : []);
pin('only the category\'s fields; text purified as the form\'s', [1 => 'red', 3 => ['from' => '1', 'to' => '9'], 4 => '1'], $fields->clean(5, [
    '1' => '<script>alert(1)</script>red', '2' => 'other category', '3' => ['from' => 1, 'to' => '9', 'x' => 'y'], '4' => true, 'x' => 'no id',
]));
pin('a date arrives as a day or an RFC 3339 time and is kept as Unix time', [3 => ['from' => '1767225600', 'to' => '1769904000']], $fields->clean(5, ['3' => ['from' => '2026-01-01', 'to' => '2026-02-01T00:00:00Z']]));
$bad = null;
try {
    $fields->clean(5, ['3' => ['from' => '2026-13-45', 'to' => '']]);
} catch (\mindstellar\api\ProblemException $e) {
    $bad = [$e->response()->status(), $e->response()->body()['errors'][0]['pointer'] ?? null];
}
pin('a date that cannot be read is 422 at its pointer, never saved as a wrong number', [422, '/custom_fields/3/from'], $bad);
pin('a single date, and a date-time with fractions of a second, are read too', [5 => '1767225600'], $fields->clean(5, ['5' => '2026-01-01T00:00:00.250Z']));
pin('a value of the wrong shape is dropped', [], $fields->clean(5, ['1' => ['a', 'b'], '3' => 'not a range']));
pin('a category with no fields takes none', [], $fields->clean(6, ['1' => 'red']));

harness_section('who sees a listing');
$live    = ['fk_i_user_id' => '9', 'b_enabled' => '1', 'b_active' => '1', 'b_spam' => '0', 'b_premium' => '0', 'dt_expiration' => '2020-01-01 00:00:00'];
$pending = ['b_active' => '0'] + $live;
pin('an expired listing is still shown on its own, as its page shows it', true, ListingPolicy::canView($live, Credential::anonymous()->actor('')));
pin('a pending one is not', false, ListingPolicy::canView($pending, Credential::anonymous()->actor('')));
pin('except to its owner', true, ListingPolicy::canView($pending, (new Credential(CredentialKind::USER, ['listings:read'], 9))->actor('')));
pin('and to an admin key with admin:listings', [true, false], [
    ListingPolicy::canView($pending, (new Credential(CredentialKind::KEY, ['admin:listings'], null, 1))->actor('', ViewContext::LISTINGS_SCOPE)),
    ListingPolicy::canView($pending, (new Credential(CredentialKind::KEY, ['admin:users'], null, 1))->actor('', ViewContext::LISTINGS_SCOPE)),
]);
$files = Request::uploadedFiles([
    'photo' => ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3],
    'many'  => ['name' => ['a', 'b'], 'type' => ['', ''], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [0, 0], 'size' => [1, 1]],
]);
pin('a request keeps single-file uploads only', ['photo'], array_keys($files));

exit(harness_result());
