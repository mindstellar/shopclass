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
 * The API serializers on row fixtures: what each view exposes, price and time formats, fieldsets and schemas.
 * Usage: php tests/api-serializers.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ProblemException;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\read\ListingRelations;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\CategorySerializer;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\api\serializer\ExtensionMembers;
use mindstellar\api\serializer\Extensions;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\Links;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\LocationSerializer;
use mindstellar\api\serializer\SparseFieldset;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\serializer\ViewContext;
use mindstellar\apikey\Credential;
use mindstellar\apikey\CredentialKind;
use mindstellar\comment\CommentStatus;
use mindstellar\listing\ListingStatus;

date_default_timezone_set('Europe/Berlin');

/** Plain-string links, so the output is predictable. */
final class FakeLinks implements Links
{
    public function listing(array $item): string
    {
        return 'https://site.test/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'https://site.test/' . $resource['s_path'] . $resource['pk_i_id'] . ($variant === '' ? '' : '_' . $variant) . '.' . $resource['s_extension'];
    }

    public function user(int $id, string $username): string
    {
        return 'https://site.test/user/' . $id;
    }

    public function avatar(int $userId): string
    {
        return 'https://site.test/avatar/' . $userId . '.png';
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'https://site.test/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return $micros === null ? 'Check with seller' : number_format($micros / 1000000, 2) . ' ' . $symbol;
    }
}

$warnings  = [];
$warn      = static function (string $m) use (&$warnings): void {
    $warnings[] = $m;
};
$links     = new FakeLinks();
$ext       = new Extensions(new ExtensionMembers(), $warn);
$fields    = new CustomFieldSerializer();
$listings  = new ListingSerializer($links, $ext, $fields);
$validator = new Validator(Schema::components());

$anonymous = Credential::anonymous(['listings:read']);
$publicKey = new Credential(CredentialKind::PUBLIC, ['listings:read'], null, 1, 5);
$owner     = new Credential(CredentialKind::USER, ['listings:read'], 7);
$stranger  = new Credential(CredentialKind::USER, ['listings:read'], 8);
$admin     = new Credential(CredentialKind::KEY, ['admin:listings'], null, 1, 6);
$userAdmin = new Credential(CredentialKind::KEY, ['admin:users'], null, 1, 7);
$readOnly  = new Credential(CredentialKind::KEY, ['listings:read'], null, 1, 8);
$moderator = new Credential(CredentialKind::KEY, ['admin:listings', 'admin:comments'], null, 2, 9, null, '', true);

$catalog = new CategoryCatalog([
    ['pk_i_id' => '1', 'fk_i_parent_id' => null, 'i_position' => '0', 'i_num_items' => '4', 'b_price_enabled' => '1', 's_name' => 'Vehicles', 's_slug' => 'vehicles', 's_description' => '',
     'locale' => ['en_US' => ['s_name' => 'Vehicles', 's_slug' => 'vehicles', 's_description' => ''], 'de_DE' => ['s_name' => 'Fahrzeuge', 's_slug' => 'fahrzeuge', 's_description' => 'Autos']]],
    ['pk_i_id' => '2', 'fk_i_parent_id' => '1', 'i_position' => '0', 'i_num_items' => '3', 'b_price_enabled' => '1', 's_name' => 'Cars', 's_slug' => 'cars', 's_description' => '',
     'locale' => ['en_US' => ['s_name' => 'Cars', 's_slug' => 'cars', 's_description' => '']]],
    ['pk_i_id' => '3', 'fk_i_parent_id' => null, 'i_position' => '1', 'i_num_items' => '0', 'b_price_enabled' => '0', 's_name' => 'Jobs', 's_slug' => 'jobs', 's_description' => '',
     'locale' => ['en_US' => ['s_name' => 'Jobs', 's_slug' => 'jobs', 's_description' => '']]],
]);

$item = [
    'pk_i_id' => '42', 'fk_i_user_id' => '7', 'fk_i_category_id' => '2', 'dt_pub_date' => '2026-10-03 14:00:00', 'dt_mod_date' => null,
    'i_price' => '12500000', 'fk_c_currency_code' => 'EUR', 's_contact_name' => 'Ana', 's_contact_email' => 'ana@example.test',
    's_contact_phone' => '+49 30 1234', 's_ip' => '203.0.113.9', 'b_premium' => '0', 'b_enabled' => '1', 'b_active' => '1',
    'b_spam' => '0', 's_secret' => 'SECRET123', 'b_show_email' => '0', 'dt_expiration' => '2099-01-01 00:00:00',
    's_title' => 'Red car', 's_description' => 'Runs well.',
    'locale' => ['en_US' => ['s_title' => 'Red car', 's_description' => 'Runs well.', 's_category_name' => 'Cars'], 'de_DE' => ['s_title' => 'Rotes Auto', 's_description' => 'Fährt gut.']],
    'fk_c_country_code' => 'de', 's_country' => 'Germany', 'fk_i_region_id' => '5', 's_region' => 'Berlin', 'fk_i_city_id' => '9', 's_city' => 'Berlin',
    's_city_area' => '', 's_address' => 'Alexanderplatz 1', 's_zip' => '10178', 'd_coord_lat' => '52.521918', 'd_coord_long' => '13.413215',
    'i_num_views' => '17', 'i_num_spam' => '2', 'i_num_bad_classified' => '0', 'i_num_repeated' => '0', 'i_num_offensive' => '1',
    'i_num_expired' => '0', 'i_num_premium_views' => '0',
];
$relations = new ListingRelations(
    $catalog,
    [42 => [['pk_i_id' => '100', 'fk_i_item_id' => '42', 's_path' => 'oc-content/uploads/0/', 's_extension' => 'jpg']]],
    [7 => ['pk_i_id' => '7', 's_name' => 'Ana Example', 's_username' => 'ana']],
    [42 => [
        ['fk_i_item_id' => '42', 'pk_i_id' => '3', 's_value' => '1', 's_multi' => '', 's_name' => 'Garage kept', 's_slug' => 'garage', 'e_type' => 'CHECKBOX', 's_meta' => null],
        ['fk_i_item_id' => '42', 'pk_i_id' => '4', 's_value' => '2010', 's_multi' => '', 's_name' => 'Year', 's_slug' => 'year', 'e_type' => 'NUMBER', 's_meta' => '{"locale":{"de_DE":{"s_name":"Baujahr"}}}'],
        ['fk_i_item_id' => '42', 'pk_i_id' => '5', 's_value' => '1767225600', 's_multi' => 'from', 's_name' => 'Available', 's_slug' => 'available', 'e_type' => 'DATEINTERVAL', 's_meta' => null],
        ['fk_i_item_id' => '42', 'pk_i_id' => '5', 's_value' => '1769904000', 's_multi' => 'to', 's_name' => 'Available', 's_slug' => 'available', 'e_type' => 'DATEINTERVAL', 's_meta' => null],
    ]],
    ['EUR' => ['pk_c_code' => 'EUR', 's_name' => 'Euro', 's_description' => '€']]
);
$ctx = static fn (Credential $c, string $locale = 'en_US', ?SparseFieldset $f = null, array $include = []): ViewContext => new ViewContext($c, $locale, $f, $include);

harness_section('Format');
pin('a price in millionths is a decimal string with two places', '12.50', Format::amount('12500000'));
pin('extra precision is kept', '12.345678', Format::amount(12345678));
pin('zero is 0.00', '0.00', Format::amount(0));
pin('a whole amount keeps two places', '1.00', Format::amount(1000000));
pin('no price is null', null, Format::amount(null));
pin('a stored local time is sent in UTC', '2026-10-03T12:00:00Z', Format::time('2026-10-03 14:00:00'));
pin('the never-expires date is null', null, Format::time('9999-12-31 23:59:59'));
pin('the empty access date is null', null, Format::time('1000-01-01 00:00:00'));
pin('an empty string is null, never ""', null, Format::text('  '));

harness_section('listing: public view');
$public = $listings->one($item, $relations, $ctx($anonymous));
$json   = json_encode($public);
pin('ids are integers', 42, $public['id']);
pin('the status of a live listing', 'active', $public['status']);
pin('a listing price has amount, currency and formatted text', ['amount' => '12.50', 'currency' => 'EUR', 'formatted' => '12.50 €'], $public['price']);
pin('the category with its path, root first', ['id' => 2, 'slug' => 'cars', 'name' => 'Cars', 'path' => [['id' => 1, 'slug' => 'vehicles', 'name' => 'Vehicles']]], $public['category']);
pin('the contact e-mail is hidden when the seller does not show it', null, $public['contact']['email']);
pin('the phone is shown, as the theme shows it', '+49 30 1234', $public['contact']['phone']);
pin('the seller links to the public profile', ['id' => 7, 'name' => 'Ana Example', 'username' => 'ana', 'url' => 'https://site.test/user/7'], $public['seller']);
pin('the location', ['code' => 'DE', 'name' => 'Germany'], $public['location']['country']);
pin('an empty city area is null, as an empty city is', null, $public['location']['city_area']);
pin('a city area is {id, name}, as a city is', ['id' => 7, 'name' => 'Mitte'], $listings->one(['fk_i_city_area_id' => '7', 's_city_area' => 'Mitte'] + $item, $relations, $ctx($anonymous))['location']['city_area']);
pin('coordinates are numbers', 52.521918, $public['location']['lat']);
pin('photos carry each size', ['id' => 100, 'thumbnail' => 'https://site.test/oc-content/uploads/0/100_thumbnail.jpg', 'preview' => 'https://site.test/oc-content/uploads/0/100_preview.jpg', 'normal' => 'https://site.test/oc-content/uploads/0/100.jpg', 'original' => null], $public['photos'][0]);
pin('times are UTC', '2026-10-03T12:00:00Z', $public['published_at']);
pin('no update yet is null', null, $public['updated_at']);
check('the IP is never in the public view', !str_contains($json, '203.0.113.9') && !array_key_exists('ip', $public));
check('the edit secret is never in the public view', !str_contains($json, 'SECRET123'));
check('report counters stay out', !array_key_exists('stats', $public) && !array_key_exists('show_email', $public));
check('custom fields and translations need include=', !array_key_exists('custom_fields', $public) && !array_key_exists('translations', $public));
pin('a public key gets the public view too', $public, $listings->one($item, $relations, $ctx($publicKey)));
pin('another user gets the public view', $public, $listings->one($item, $relations, $ctx($stranger)));
pin('the public view matches the Listing schema', [], $validator->check(Schema::ref('Listing'), $public));

$shown = $listings->one(['b_show_email' => '1'] + $item, $relations, $ctx($anonymous));
pin('the e-mail is sent when the seller shows it', 'ana@example.test', $shown['contact']['email']);
$hiding = new ListingSerializer($links, $ext, $fields, true, true);
pin('api_hide_phone leaves the phone out of the public view', null, $hiding->one($item, $relations, $ctx($anonymous))['contact']['phone']);
pin('originals are linked when the site keeps them', 'https://site.test/oc-content/uploads/0/100_original.jpg', $hiding->one($item, $relations, $ctx($anonymous))['photos'][0]['original']);

harness_section('listing: owner and admin views');
$mine = $hiding->one($item, $relations, $ctx($owner));
pin('the owner always sees the e-mail', 'ana@example.test', $mine['contact']['email']);
pin('the owner sees the phone, even when it is hidden from the public', '+49 30 1234', $mine['contact']['phone']);
check('the owner sees the show-email switch', !$mine['show_email']);
check('the owner does not see the IP or the counters', !array_key_exists('ip', $mine) && !array_key_exists('stats', $mine));
check('nor the secret', !str_contains((string) json_encode($mine), 'SECRET123'));
$all = $hiding->one($item, $relations, $ctx($admin));
pin('admins see the IP', '203.0.113.9', $all['ip']);
pin('an admin sees the report counters', 2, $all['stats']['spam']);
check('an admin view never carries the secret', !str_contains((string) json_encode($all), 'SECRET123'));
pin('the owner view matches the schema', [], $validator->check(Schema::ref('Listing'), $mine));
pin('the admin view matches the schema', [], $validator->check(Schema::ref('Listing'), $all));
pin('an admin key without admin:listings gets the public view', $hiding->one($item, $relations, $ctx($anonymous)), $hiding->one($item, $relations, $ctx($readOnly)));
check('an admin key holding only admin:users gets the public listing view', !array_key_exists('ip', $hiding->one($item, $relations, $ctx($userAdmin))));
pin('a moderator key with admin:listings moderates, so it gets the admin view', '203.0.113.9', $hiding->one($item, $relations, $ctx($moderator))['ip']);

harness_section('listing: status, price, text');
$now = strtotime('2026-10-03 12:00:00');
pin('spam wins over everything', 'spam', ListingStatus::of(['b_spam' => '1', 'b_enabled' => '0'] + $item, $now));
pin('a listing with b_enabled 0 has the status disabled', 'disabled', ListingStatus::of(['b_enabled' => '0'] + $item, $now));
pin('not yet activated is pending', 'pending', ListingStatus::of(['b_active' => '0'] + $item, $now));
pin('past its expiry', 'expired', ListingStatus::of(['dt_expiration' => '2026-01-01 00:00:00'] + $item, $now));
pin('a premium listing stays live past its expiry', 'active', ListingStatus::of(['dt_expiration' => '2026-01-01 00:00:00', 'b_premium' => '1'] + $item, $now));
pin('a comment: spam, blocked, waiting, live', ['spam', 'disabled', 'pending', 'active'], [
    CommentStatus::of(['b_spam' => '1', 'b_enabled' => '0', 'b_active' => '0']),
    CommentStatus::of(['b_spam' => '0', 'b_enabled' => '0', 'b_active' => '1']),
    CommentStatus::of(['b_spam' => '0', 'b_enabled' => '1', 'b_active' => '0']),
    CommentStatus::of(['b_spam' => '0', 'b_enabled' => '1', 'b_active' => '1']),
]);
$statusQuery = ['type' => 'object', 'properties' => ['status' => Schema::listOf(ListingStatus::ALL)]];
pin('a status filter takes one status, a comma list or repeated ones', [[], [], []], [
    (new Validator())->check($statusQuery, ['status' => 'spam']),
    (new Validator())->check($statusQuery, ['status' => 'spam, expired']),
    (new Validator())->check($statusQuery, ['status' => ['spam', 'expired']]),
]);
pin('a status filter refuses an unknown value in any form', ['pattern', 'pattern', 'enum'], array_map(
    static fn ($status): string => (new Validator())->check($statusQuery, ['status' => $status])[0]['code'] ?? '',
    ['gone', 'spam,gone', ['spam', 'gone']]
));
pin('no price is null', null, $listings->one(['i_price' => null] + $item, $relations, $ctx($anonymous))['price']);
pin('a free listing is 0.00', '0.00', $listings->one(['i_price' => '0'] + $item, $relations, $ctx($anonymous))['price']['amount']);
pin('a category that hides prices sends none', null, $listings->one(['fk_i_category_id' => '3'] + $item, $relations, $ctx($anonymous))['price']);
$german = $listings->one($item, $relations, $ctx($anonymous, 'de_DE'));
pin('the asked locale\'s text', ['de_DE', 'Rotes Auto'], [$german['locale'], $german['title']]);
pin('a locale the listing lacks falls back to one it has', ['en_US', 'Red car'], (static function () use ($listings, $item, $relations, $ctx, $anonymous): array {
    $out = $listings->one($item, $relations, $ctx($anonymous, 'fr_FR'));

    return [$out['locale'], $out['title']];
})());
pin('a missing seller is null', null, $listings->one(['fk_i_user_id' => '99'] + $item, $relations, $ctx($anonymous))['seller']);
pin('an unknown category still names its id', ['id' => 77, 'slug' => null, 'name' => null, 'path' => []], $listings->one(['fk_i_category_id' => '77'] + $item, $relations, $ctx($anonymous))['category']);

harness_section('listing: includes and sparse fieldsets');
$full = $listings->one($item, $relations, $ctx($anonymous, 'de_DE', null, ['custom_fields', 'translations']));
pin('include=custom_fields types each value', [
    ['id' => 3, 'slug' => 'garage', 'name' => 'Garage kept', 'type' => 'checkbox', 'value' => true],
    ['id' => 4, 'slug' => 'year', 'name' => 'Baujahr', 'type' => 'number', 'value' => 2010],
    ['id' => 5, 'slug' => 'available', 'name' => 'Available', 'type' => 'dateinterval', 'value' => ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-02-01T00:00:00Z']],
], $full['custom_fields']);
pin('include=translations lists every locale', ['en_US' => ['title' => 'Red car', 'description' => 'Runs well.'], 'de_DE' => ['title' => 'Rotes Auto', 'description' => 'Fährt gut.']], $full['translations']);
pin('with includes it still matches the schema', [], $validator->check(Schema::ref('Listing'), $full));
$sparse = SparseFieldset::parse('title, price', ListingSerializer::MEMBERS, new ExtensionMembers(), 'listing');
pin('fields= keeps the asked members and always the id', ['id', 'title', 'price'], array_keys($listings->one($item, $relations, $ctx($anonymous, 'en_US', $sparse))));
$problem = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (ProblemException $e) {
        return $e->response()->status() . ' ' . $e->response()->body()['code'];
    }

    return null;
};
pin('an unknown member is a 422 at /fields', '422 validation_failed', $problem(static fn () => SparseFieldset::parse('title,secret', ListingSerializer::MEMBERS, new ExtensionMembers(), 'listing')));
pin('a nested path is a 422 too', '422 validation_failed', $problem(static fn () => SparseFieldset::parse('price.amount', ListingSerializer::MEMBERS, new ExtensionMembers(), 'listing')));
pin('an empty value selects everything', null, SparseFieldset::parse(' ', ListingSerializer::MEMBERS, new ExtensionMembers(), 'listing'));

harness_section('the schema and the serializers agree');
$schemas = Schema::components();
$sorted  = static function (array $v): array {
    sort($v);

    return $v;
};
pin('Listing members', $sorted(ListingSerializer::MEMBERS), $sorted(array_keys($schemas['Listing']['properties'])));
pin('User members', $sorted(UserSerializer::MEMBERS), $sorted(array_keys($schemas['User']['properties'])));
pin('Category members', $sorted(CategorySerializer::MEMBERS), $sorted(array_keys($schemas['Category']['properties'])));
$broken = [];
foreach ($schemas as $name => $schema) {
    if ($validator->schemaProblems($schema) !== []) {
        $broken[$name] = $validator->schemaProblems($schema);
    }
}
pin('every component uses only keywords the validator checks', [], $broken);

harness_section('user');
$user = [
    'pk_i_id' => '7', 's_name' => 'Ana Example', 's_username' => 'ana', 's_email' => 'ana@example.test', 's_website' => '',
    's_phone_land' => '030 1', 's_phone_mobile' => '0170 2', 'b_enabled' => '1', 'b_active' => '1', 'fk_c_country_code' => 'DE',
    's_country' => 'Germany', 's_address' => 'Street 1', 's_zip' => '10115', 'fk_i_region_id' => '5', 's_region' => 'Berlin',
    'fk_i_city_id' => null, 's_city' => '', 'b_company' => '0', 'i_items' => '3', 'dt_reg_date' => '2026-01-01 10:00:00',
    'dt_access_date' => '2026-10-01 09:00:00', 's_access_ip' => '198.51.100.4', 's_password' => 'HASH', 's_secret' => 'USERSECRET',
    'd_coord_lat' => null, 'd_coord_long' => null,
];
$users       = new UserSerializer($links, $ext);
$profile     = $users->one($user, $ctx($anonymous));
$profileJson = (string) json_encode($profile);
pin('the public profile has only the public members', UserSerializer::PUBLIC_MEMBERS, array_keys($profile));
check('no e-mail, phone, address or IP for others', !str_contains($profileJson, 'example.test') && !str_contains($profileJson, '0170') && !str_contains($profileJson, '198.51'));
check('never the password hash or secret', !str_contains($profileJson, 'HASH') && !str_contains($profileJson, 'USERSECRET'));
pin('an empty city is null', null, $profile['location']['city']);
pin('the public profile matches the schema', [], $validator->check(Schema::ref('User'), $profile));
$self = $users->one($user, $ctx($owner));
pin('the user sees their own e-mail', 'ana@example.test', $self['email']);
pin('a confirmed user is active, an unconfirmed one pending, a blocked one disabled', ['active', 'pending', 'disabled'], [
    $self['status'],
    $users->one(['b_active' => '0'] + $user, $ctx($owner))['status'],
    $users->one(['b_enabled' => '0'] + $user, $ctx($owner))['status'],
]);
pin('the public profile has no status', null, $profile['status'] ?? null);
pin('the owner sees the last access time and IP', ['2026-10-01T07:00:00Z', '198.51.100.4'], [$self['last_access_at'], $self['last_access_ip']]);
pin('admin keys with admin:users get the same', $self['phone_mobile'], $users->one($user, $ctx($userAdmin))['phone_mobile']);
pin('an admin key without admin:users gets the public profile', UserSerializer::PUBLIC_MEMBERS, array_keys($users->one($user, $ctx($admin))));
pin('as does a moderator', UserSerializer::PUBLIC_MEMBERS, array_keys($users->one($user, $ctx($moderator))));
pin('a least-privilege listings:read admin key gets the public profile members', UserSerializer::PUBLIC_MEMBERS, array_keys($users->one($user, $ctx($readOnly))));
check('another user does not', !array_key_exists('email', $users->one($user, $ctx($stranger))));
check('even the full profile never carries the password', !str_contains((string) json_encode($self), 'HASH'));
pin('the full profile matches the schema', [], $validator->check(Schema::ref('User'), $self));

harness_section('category');
$categories = new CategorySerializer($ext, $fields);
$flat       = $categories->flat($catalog, $ctx($anonymous));
pin('flat, parents first, with parent ids', [[1, null], [2, 1], [3, null]], array_map(static fn (array $c): array => [$c['id'], $c['parent_id']], $flat));
$tree = $categories->tree($catalog, $ctx($anonymous, 'de_DE'));
pin('a tree nests children, in the asked locale', ['Fahrzeuge', 'Cars'], [$tree[0]['name'], $tree[0]['children'][0]['name']]);
pin('the translated description', 'Autos', $tree[0]['description']);
pin('a category matches the schema', [], $validator->check(Schema::ref('Category'), $tree[0]));
pin('lookup by slug in any locale', 1, (int) $catalog->lookup('fahrzeuge', 'en_US')['pk_i_id']);
pin('lookup by id', 'cars', $catalog->lookup('2', 'en_US')['s_slug']);
pin('an unknown slug is null', null, $catalog->lookup('boats', 'en_US'));
$withFields = $categories->one((array) $catalog->find(2), $ctx($anonymous), [
    ['pk_i_id' => '4', 's_slug' => 'year', 's_name' => 'Year', 'e_type' => 'DROPDOWN', 'b_required' => '1', 'b_searchable' => '0', 's_options' => 'a, b,,c'],
]);
pin('a single category lists its fields', [['id' => 4, 'slug' => 'year', 'name' => 'Year', 'type' => 'dropdown', 'required' => true, 'searchable' => false, 'options' => ['a', 'b', 'c']]], $withFields['custom_fields']);

harness_section('comments and locations');
$comment = (new CommentSerializer())->one([
    'pk_i_id' => '3', 'fk_i_item_id' => '42', 's_title' => 'Hi', 's_body' => 'Still there?', 's_author_name' => 'Bo',
    's_author_email' => 'bo@example.test', 'fk_i_user_id' => null, 'dt_pub_date' => '2026-10-02 10:00:00',
]);
check('a comment never carries the author\'s e-mail', !str_contains((string) json_encode($comment), 'bo@example.test'));
pin('a comment matches the schema', [], $validator->check(Schema::ref('Comment'), $comment));
$places = new LocationSerializer();
pin('a country row becomes code, name and a null slug when empty', ['code' => 'DE', 'name' => 'Germany', 'slug' => null], $places->country(['pk_c_code' => 'de', 's_name' => 'Germany', 's_slug' => '']));
pin('a currency row becomes code, name and symbol', ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'], $places->currency(['pk_c_code' => 'EUR', 's_name' => 'Euro', 's_description' => '€']));

exit(harness_result());
