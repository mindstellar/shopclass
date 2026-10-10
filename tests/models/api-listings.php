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
 * The read endpoints end to end through Kernel::handle() on a seeded site, with real keys.
 * Usage: php tests/models/api-listings.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// This file loads page helpers that earlier files in the suite stub, so under the runner it
// runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $alOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $alOut, $alCode);
    $alOut = implode("\n", $alOut);
    echo $alOut, "\n";
    $alFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $alOut, $alM) === 1;
    $alFail  = $alFound ? (int)$alM[2] : 0;
    if (!$alFound || ($alCode !== 0 && $alFail === 0)) {
        $alFail = max(1, $alFail);
    }
    $GLOBALS['okCount']   += $alFound ? (int)$alM[1] : 0;
    $GLOBALS['failCount'] += $alFail;
    if ($alFail > 0) {
        $GLOBALS['failLabels'][] = 'api-listings: ' . $alFail . ' failed (exit ' . $alCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_api_listings');

foreach (array(
    'OSC_CACHE_TTL' => 60,
    'WEB_PATH'      => 'http://localhost/',
    'REL_WEB_URL'   => '/',
    'PLUGINS_PATH'  => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'      => false,
    'OSC_DEBUG'     => false,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

$GLOBALS['al_upgrades'] = 0;
if (!function_exists('osc_prime_item_upgrades')) {
    function osc_prime_item_upgrades(array $items)
    {
        $GLOBALS['al_upgrades']++;
    }
}
if (!function_exists('osc_remove_slash')) {
    function osc_remove_slash($var)
    {
        return is_string($var) ? stripslashes($var) : $var;
    }
}
if (!function_exists('osc_prune_array')) {
    function osc_prune_array(&$input)
    {
        \mindstellar\utility\Utils::pruneArray($input);
    }
}
if (!function_exists('osc_is_ssl')) {
    function osc_is_ssl()
    {
        return false;
    }
}

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\Kernel;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Definitions;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

/** Links without the theme helpers. */
final class TestLinks implements Links
{
    public function listing(array $item): string
    {
        return 'http://localhost/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'http://localhost/' . $resource['s_path'] . $resource['pk_i_id'] . ($variant === '' ? '' : '_' . $variant) . '.' . $resource['s_extension'];
    }

    public function user(int $id, string $username): string
    {
        return 'http://localhost/user/' . $id;
    }

    public function avatar(int $userId): string
    {
        return 'http://localhost/avatar/' . $userId;
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'http://localhost/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return number_format((int) $micros / 1000000, 2) . ' ' . $symbol;
    }
}

/* ----------------------------------------------------------------------------
 * Fixture: Vehicles > Cars (with a custom field), Bikes, Boats (empty); one region and city;
 * 25 live cars (five share one publish time, so paging must break ties on the id), a live
 * bike, and one listing in each hidden state; photos, field values and comments.
 * ------------------------------------------------------------------------- */
$p        = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
$country  = seed_country($admin, 'US', 'United States');
$region   = seed_region($admin, $country, 'Alpha');
$city     = seed_city($admin, $region, 'Aville', $country);
$area     = seed_exec($admin, "INSERT INTO {$p}t_city_area (fk_i_city_id, s_name) VALUES (?, 'Downtown')", 'i', array($city));
$vehicles = seed_category($admin, 'Vehicles', null, $locale);
$cars     = seed_category($admin, 'Cars', $vehicles, $locale);
$bikes    = seed_category($admin, 'Bikes', $vehicles, $locale);
seed_category($admin, 'Boats', null, $locale);
$seller   = seed_user($admin, 'seller', 'seller@example.test');
$other    = seed_user($admin, 'other', 'other@example.test');
$blocked  = seed_user($admin, 'blocked', 'blocked@example.test', 1, 0);
$adminId  = seed_exec($admin, "INSERT INTO {$p}t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', ?, 'a@x.test', 0)", 's', array(str_repeat('x', 60)));

$fieldId = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position) VALUES ('Colour', 'colour', 'TEXT', 0, 1, 1)", '', array());
seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($cars, $fieldId));

$live = array();
for ($i = 0; $i < 25; $i++) {
    $id = seed_item($admin, $cars, $seller, 'Car ' . $i, 1000.0 + ($i % 7) * 100, 1, 1, $locale, $country);
    // Items 10..14 share one publish time; the rest are a minute apart.
    $minutes = ($i >= 10 && $i < 15) ? 10 : $i;
    $admin->query("UPDATE {$p}t_item SET dt_pub_date = DATE_SUB('2026-10-01 12:00:00', INTERVAL $minutes MINUTE) WHERE pk_i_id = $id");
    $admin->query("UPDATE {$p}t_item_location SET fk_i_region_id = $region, s_region = 'Alpha', fk_i_city_id = $city, s_city = 'Aville' WHERE fk_i_item_id = $id");
    seed_photo($admin, $id, array('s_name' => 'p'));
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value) VALUES (?, ?, ?)", 'iis', array($id, $fieldId, $i % 2 ? 'red' : 'blue'));
    $live[] = $id;
}
$bike     = seed_item($admin, $bikes, $other, 'Racing bike', 800.0, 1, 1, $locale, $country);
$pending  = seed_item($admin, $cars, $seller, 'Pending car', 5.0, 0, 1, $locale, $country);
$disabled = seed_item($admin, $cars, $seller, 'Disabled car', 5.0, 1, 0, $locale, $country);
$spam     = seed_item($admin, $cars, $seller, 'Spam car', 5.0, 1, 1, $locale, $country);
$expired  = seed_item($admin, $cars, $seller, 'Expired car', 5.0, 1, 1, $locale, $country);
$admin->query("UPDATE {$p}t_item SET b_spam = 1 WHERE pk_i_id = $spam");
$admin->query("UPDATE {$p}t_item SET dt_expiration = '2020-01-01 00:00:00' WHERE pk_i_id = $expired");
$admin->query("UPDATE {$p}t_item SET s_contact_phone = '555-0100', b_show_email = 0 WHERE pk_i_id = {$live[0]}");
$admin->query("UPDATE {$p}t_user SET i_items = 30 WHERE pk_i_id = $seller");
foreach (array(array(1, 1, 0), array(1, 1, 0), array(1, 1, 0), array(0, 1, 0), array(1, 1, 1)) as $n => [$active, $enabled, $isSpam]) {
    seed_exec(
        $admin,
        "INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam) VALUES (?, NOW(), ?, 'Bo', 'bo@example.test', 'Hello', ?, ?, ?)",
        'isiii',
        array($live[0], 'Comment ' . $n, $enabled, $active, $isSpam)
    );
}
// Live cars, newest first, the id breaking ties: the order every created/desc page follows.
$expectedOrder = array_map('intval', array_column(
    $admin->query("SELECT pk_i_id FROM {$p}t_item WHERE fk_i_category_id = $cars AND b_active = 1 AND b_enabled = 1 AND b_spam = 0 AND dt_expiration > NOW() ORDER BY dt_pub_date DESC, pk_i_id DESC")->fetch_all(MYSQLI_ASSOC),
    'pk_i_id'
));

foreach (array(
    'defaultResultsPerPage@search' => '12',
    'maxResultsPerPage@search'     => '50',
    'defaultOrderField@search'     => 'dt_pub_date',
    'defaultOrderType@search'      => '1',
    'enabled_comments'             => '1',
    'comments_per_page'            => '10',
    'enabled_users'                => '1',
    'language'                     => 'en_US',
    'pageTitle'                    => 'Test site',
    'currency'                     => 'USD',
    'rewriteEnabled'               => '0',
) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
$_COOKIE['oc_userLocale'] = 'en_US';

/* ----------------------------------------------------------------------------
 * The kernel: the real route table and keys, with handlers built over a test kit.
 * ------------------------------------------------------------------------- */
$keys      = new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
$publicKey = $keys->create(CredentialKind::PUBLIC, 'app', array('listings:read'), KeyOwner::admin($adminId))->token();
$sellerKey = $keys->create(CredentialKind::KEY, 'seller', array('listings:read'), KeyOwner::user($seller))->token();
$otherKey  = $keys->create(CredentialKind::KEY, 'other', array('listings:read'), KeyOwner::user($other))->token();
$adminKey  = $keys->create(CredentialKind::KEY, 'admin', array('admin:listings', 'admin:users'), KeyOwner::admin($adminId))->token();
$narrowKey = $keys->create(CredentialKind::KEY, 'reader', array('listings:read'), KeyOwner::admin($adminId))->token();

$facts = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')));
$validator = new Validator(Schema::components());
// Core handlers are built over test services, as the site's are.
$makeKernel = static function (ApiSettings $settings, ?Validator $with = null, ?SiteFacts $siteFacts = null) use ($keys, $validator, $facts): Kernel {
    $with ??= $validator;
    $facts = $siteFacts ?? $facts;

    return api_test_kernel(
        new Router($with, Router::core(), handlers: static function (string $class) use ($facts, $settings): object {
            $services = new ApiServices($settings, new Scopes(), new ApiCredential(), new UserRows(), new SystemClock(), api_test_limiter(), $facts, new TestLinks());

            return ($services->handlers())($class);
        }),
        api_test_authenticator($keys),
        $settings,
        validator: $with
    );
};
$kernel = $makeKernel(new ApiSettings(true, userKeys: true));

/**
 * One GET through the kernel.
 *
 * @param array<string,mixed> $query
 */
$get = static function (string $path, array $query = array(), ?string $token = null, ?Kernel $k = null) use ($kernel): Response {
    $headers = $token === null ? array() : array('Authorization' => 'Bearer ' . $token);
    $_GET    = $query;
    Params::init();

    return ($k ?? $kernel)->handle(new Request('GET', 'v1/' . $path, $query, $headers, '127.0.0.1'));
};
$ids = static fn (Response $r): array => array_map(static fn (array $l): int => $l['id'], (array) ($r->body()['data'] ?? array()));
$schemaErrors = static fn (string $schema, Response $r): array => $validator->check(Schema::ref($schema), $r->body());

harness_section('anonymous reads');
$r = $get('listings');
pin('anonymous reads are off: 401 with a Bearer challenge', array(401, 'unauthorized', 'Bearer'), array($r->status(), $r->body()['code'], $r->header('WWW-Authenticate')));
$open = $makeKernel(new ApiSettings(true, true, userKeys: true));
$r    = $get('categories', array(), null, $open);
pin('with anonymous reads on, a public endpoint answers', 200, $r->status());
pin('an anonymous public endpoint may be cached publicly', 'public, max-age=60, stale-while-revalidate=60', $r->header('Cache-Control'));
check('a user-keyed answer is never cached publicly', str_starts_with((string) $get('categories', array(), $sellerKey)->header('Cache-Control'), 'private'));

harness_section('site');
$r = $get('', array(), $publicKey);
pin('the site root', array(200, 'Test site', 'en_US', array('users' => true, 'registration' => false, 'comments' => true)), array($r->status(), $r->body()['data']['name'], $r->body()['data']['default_locale'], $r->body()['data']['features']));
check('the software version is not shown to callers', !(array_key_exists('version', $r->body()['data'])));
pin('what the API allows here, as this test site sets it', array('registration' => false, 'personal_keys' => true, 'photo_urls' => false, 'public_reads' => false), $r->body()['data']['api'] ?? null);
$api = static fn (ApiSettings $settings): ?array => $get('', array(), $publicKey, $makeKernel($settings))->body()['data']['api'] ?? null;
pin('switched on in the API settings', array('registration' => false, 'personal_keys' => true, 'photo_urls' => true, 'public_reads' => true), $api(new ApiSettings(true, true, userKeys: true, registration: true, photoUrls: true)));
Preference::getInstance()->set('enabled_user_registration', '1');
osc_reset_preferences();
check('sign-up through the API also needs the site to take sign-ups', $api(new ApiSettings(true, userKeys: true, registration: true))['registration'] === true);
Preference::getInstance()->set('enabled_user_registration', '0');
osc_reset_preferences();
pin('links to the collections', 'http://localhost/api/v1/listings', $r->body()['data']['links']['listings']);
pin('api_version is the version the request asked for', 'v1', $r->body()['data']['api_version']);
pin('matches the schema', array(), $schemaErrors('SiteDocument', $r));
$lazyBuilds = 0;
$lazy       = Definitions::lazy(Schema::names(), static function () use (&$lazyBuilds): array {
    $lazyBuilds++;

    return Schema::components();
});
$r    = $get('currencies', array(), $publicKey, $makeKernel(new ApiSettings(true, userKeys: true), new Validator($lazy)));
pin('the currency list matches the stored currencies', array(array('code' => 'USD', 'name' => 'US Dollar', 'symbol' => 'US Dollar')), $r->body()['data']);
check('a request whose route follows no $ref never builds the component schemas', $lazyBuilds === 0);
Preference::getInstance()->set('pageTitle', 'Renamed site');
pin('the site root follows a settings change at once', 'Renamed site', $get('', array(), $publicKey)->body()['data']['name']);
Preference::getInstance()->set('pageTitle', 'Test site');

harness_section('categories and fields');
$r = $get('categories', array(), $publicKey);
pin('every enabled category, parents first', array('vehicles', 'cars', 'bikes', 'boats'), array_column($r->body()['data'], 'slug'));
pin('matches the schema', array(), $schemaErrors('CategoryList', $r));
$r = $get('categories', array('tree' => '1'), $publicKey);
pin('the category tree lists the children by slug', array('cars', 'bikes'), array_column($r->body()['data'][0]['children'], 'slug'));
$r = $get('categories/cars', array(), $publicKey);
pin('one category by slug, with its custom fields', array($cars, $vehicles, array('colour')), array($r->body()['data']['id'], $r->body()['data']['parent_id'], array_column($r->body()['data']['custom_fields'], 'slug')));
pin('one category is also found by its id', 'cars', $get('categories/' . $cars, array(), $publicKey)->body()['data']['slug']);
pin('an unknown category is 404', 404, $get('categories/nosuch', array(), $publicKey)->status());
pin('fields of a category', array('colour'), array_column($get('custom-fields', array('category' => 'cars'), $publicKey)->body()['data'], 'slug'));
pin('fields of an unknown category is 422', 422, $get('custom-fields', array('category' => 'nosuch'), $publicKey)->status());
pin('fields= trims a category', array('id', 'slug'), array_keys($get('categories/cars', array('fields' => 'slug'), $publicKey)->body()['data']));

harness_section('locations');
pin('the country list has code, name and slug', array(array('code' => 'US', 'name' => 'United States', 'slug' => 'us')), $get('countries', array(), $publicKey)->body()['data']);
pin('a country\'s regions', array('Alpha'), array_column($get('countries/us/regions', array(), $publicKey)->body()['data'], 'name'));
pin('an unknown country is 404', 404, $get('countries/zz/regions', array(), $publicKey)->status());
pin('a region\'s cities', array('Aville'), array_column($get('regions/' . $region . '/cities', array(), $publicKey)->body()['data'], 'name'));
pin('?q= narrows by prefix', array(), $get('regions/' . $region . '/cities', array('q' => 'B'), $publicKey)->body()['data']);
pin('a city\'s areas', array(array('id' => $area, 'city_id' => $city, 'name' => 'Downtown')), $get('cities/' . $city . '/areas', array(), $publicKey)->body()['data']);
pin('an unknown region is 404', 404, $get('regions/99999/cities', array(), $publicKey)->status());
pin('an unknown city is 404', 404, $get('cities/99999/areas', array(), $publicKey)->status());
$second = seed_region($admin, $country, 'Beta');
$r      = $get('countries/us/regions', array('limit' => 1), $publicKey);
pin('location lists are paged', array(array('Alpha'), null), array(array_column($r->body()['data'], 'name'), $r->body()['meta']['total']));
parse_str((string) parse_url((string) $r->body()['links']['next'], PHP_URL_QUERY), $nextQuery);
pin('the next page has the rest', array('Beta'), array_column($get('countries/us/regions', $nextQuery, $publicKey)->body()['data'], 'name'));
pin('a known region with no cities is an empty list', array(200, array()), (static function () use ($get, $second, $publicKey): array {
    $r = $get('regions/' . $second . '/cities', array(), $publicKey);

    return array($r->status(), $r->body()['data']);
})());
pin('a location list matches the schema', array(), $schemaErrors('RegionList', $r));

harness_section('search');
$r = $get('listings', array('category' => 'cars', 'limit' => 10), $publicKey);
pin('a page of live listings, newest first', array_slice($expectedOrder, 0, 10), $ids($r));
pin('no total unless asked', null, $r->body()['meta']['total']);
$r = $get('listings', array('category' => 'cars', 'limit' => 10, 'count' => 'true'), $publicKey);
pin('the total counts every match when count=true', 25, $r->body()['meta']['total']);
check('a next link while there are more', is_string($r->body()['links']['next']));
pin('the page matches the schema', array(), $schemaErrors('ListingPage', $r));
pin('each listing on a page reads as its own GET does', array_map(
    static fn (array $one): array => $get('listings/' . $one['id'], array(), $publicKey)->body()['data'],
    $r->body()['data']
), $r->body()['data']);
pin('the parent category includes its children', 26, $get('listings', array('category' => 'vehicles', 'limit' => 1, 'count' => 'true'), $publicKey)->body()['meta']['total']);
pin('an unknown category is refused, not widened', 422, $get('listings', array('category' => 'nosuch'), $publicKey)->status());
pin('a slug path names its last slug, as search URLs do', 25, $get('listings', array('category' => 'vehicles/cars', 'limit' => 1, 'count' => 'true'), $publicKey)->body()['meta']['total']);
pin('one unknown category in a list is refused too', 422, $get('listings', array('category' => 'cars,nosuch'), $publicKey)->status());
pin('a limit past the site cap is refused', 422, $get('listings', array('limit' => 51), $publicKey)->status());
$badUser = $get('listings', array('user' => 'abc'), $publicKey);
pin('a user filter that is not an id is refused', 422, $badUser->status());
pin('the refused user filter is named', array('/user', 'query'), array($badUser->body()['errors'][0]['pointer'] ?? null, $badUser->body()['errors'][0]['in'] ?? null));
pin('a schema-invalid sort is refused', 422, $get('listings', array('sort' => 'secret'), $publicKey)->status());
pin('a pattern search', array($bike), $ids($get('listings', array('q' => 'racing'), $publicKey)));
pin('the user filter finds that seller listings', 1, $get('listings', array('user' => (string) $other, 'count' => 'true'), $publicKey)->body()['meta']['total']);
pin('a custom field filter', 12, $get('listings', array('category' => 'cars', 'custom_field' => array((string) $fieldId => 'red'), 'count' => 'true'), $publicKey)->body()['meta']['total']);

$walk = static function (array $query) use ($get, $publicKey, $ids): array {
    $seen  = array();
    $pages = 0;
    $r     = $get('listings', $query, $publicKey);
    while (true) {
        $pages++;
        $seen = array_merge($seen, $ids($r));
        $next = $r->body()['links']['next'] ?? null;
        if ($next === null || $pages > 20) {
            break;
        }
        parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);
        $r = $get('listings', $nextQuery, $publicKey);
        if ($r->status() !== 200) {
            $seen[] = 'HTTP ' . $r->status();
            break;
        }
    }

    return array($pages, $seen);
};
[$pages, $seen] = $walk(array('category' => 'cars', 'limit' => 10));
pin('three keyset pages cover every listing once, in order, across a tie', array(3, $expectedOrder), array($pages, $seen));
[$pages, $seen] = $walk(array('category' => 'cars', 'limit' => 10, 'sort' => 'id', 'order' => 'asc'));
$byId = $expectedOrder;
sort($byId);
pin('sort=id pages by keyset too', array(3, $byId), array($pages, $seen));
// Three cars without a price; the rest share seven prices, so the id breaks most ties.
// Two a page puts a page boundary inside the NULL group in both directions.
$unpriced = "{$live[2]}, {$live[5]}, {$live[17]}";
$prices   = $admin->query("SELECT pk_i_id, i_price FROM {$p}t_item WHERE pk_i_id IN ($unpriced)")->fetch_all(MYSQLI_ASSOC);
$admin->query("UPDATE {$p}t_item SET i_price = NULL WHERE pk_i_id IN ($unpriced)");
foreach (array('asc', 'desc') as $order) {
    $byPrice = array_map('intval', array_column($admin->query("SELECT pk_i_id FROM {$p}t_item WHERE pk_i_id IN (" . implode(',', $live) . ") ORDER BY i_price $order, pk_i_id $order")->fetch_all(MYSQLI_ASSOC), 'pk_i_id'));
    [$pages, $seen] = $walk(array('category' => 'cars', 'limit' => 2, 'sort' => 'price', 'order' => $order));
    pin("sort=price $order pages by keyset through ties and NULL prices, each listing once, in order", array((int) ceil(count($byPrice) / 2), $byPrice), array($pages, $seen));
    $r = $get('listings', array('category' => 'cars', 'limit' => 2, 'sort' => 'price', 'order' => $order), $publicKey);
    parse_str((string) parse_url((string) $r->body()['links']['next'], PHP_URL_QUERY), $nextQuery);
    pin("page 2 of sort=price $order by cursor is the offset page 2", array_slice($byPrice, 2, 2), $ids($get('listings', $nextQuery, $publicKey)));
}
foreach ($prices as $row) {
    $admin->query("UPDATE {$p}t_item SET i_price = " . (int) $row['i_price'] . ' WHERE pk_i_id = ' . (int) $row['pk_i_id']);
}
$r    = $get('listings', array('category' => 'cars', 'limit' => 10), $publicKey);
$next = (string) $r->body()['links']['next'];
parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);
pin('a later keyset page reports no total (it would count only what is left)', null, $get('listings', $nextQuery, $publicKey)->body()['meta']['total']);
pin('a cursor reused with other filters is refused', 400, $get('listings', array('category' => 'bikes', 'cursor' => $nextQuery['cursor']), $publicKey)->status());

harness_section('search_conditions');
$calls     = array();
$condition = static function ($params, $search, $context) use (&$calls, $live) {
    $calls[] = array($context, $params['sCategory'] ?? null, Params::getParam('sCategory'));
    $search->addConditions(DB_TABLE_PREFIX . 't_item.pk_i_id <> ' . (int) $live[24]);
};
osc_add_hook('search_conditions', $condition);
$r = $get('listings', array('category' => 'cars', 'limit' => 50, 'count' => 'true'), $publicKey);
osc_remove_hook('search_conditions', $condition);
pin('a plugin listener runs with context api and the search page\'s parameters, also through Params', array(array('api', array((string) $cars), array((string) $cars))), $calls);
pin('the request is put back after it', '', Params::getParam('sCategory'));
pin('the search_conditions hook condition applies to the answer', array(24, false), array($r->body()['meta']['total'], in_array($live[24], $ids($r), true)));
$backend = static function ($answer, $search, $params) use ($admin, $p, $live) {
    return array('items' => array($admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = " . (int) $live[3])->fetch_assoc()), 'total' => 1);
};
osc_add_filter('search_results', $backend);
$r = $get('listings', array('category' => 'cars'), $publicKey);
osc_remove_filter('search_results', $backend);
pin('a search_results backend may answer with bare rows; they are read as any page\'s', array(array($live[3]), $get('listings/' . $live[3], array(), $publicKey)->body()['data']), array($ids($r), $r->body()['data'][0] ?? null));

harness_section('one listing');
$views = static fn (int $id): int => (int) $admin->query("SELECT i_num_views FROM {$p}t_item_stats WHERE fk_i_item_id = $id")->fetch_row()[0];
$r     = $get('listings/' . $live[0], array('include' => 'custom_fields'), $publicKey);
$data  = $r->body()['data'];
pin('a live listing', array(200, $live[0], 'active', 'Car 0', 'cars', 'vehicles'), array($r->status(), $data['id'], $data['status'], $data['title'], $data['category']['slug'], $data['category']['path'][0]['slug']));
pin('its price as a decimal string', array('amount' => '1000.00', 'currency' => 'USD', 'formatted' => '1,000.00 US Dollar'), $data['price']);
pin('the contact e-mail stays hidden, the phone shows', array(null, '555-0100'), array($data['contact']['email'], $data['contact']['phone']));
pin('a listing seller member has id, name, username and url', array('id' => $seller, 'name' => 'seller', 'username' => 'seller', 'url' => 'http://localhost/user/' . $seller), $data['seller']);
pin('custom fields with include=custom_fields', array(array('id' => $fieldId, 'slug' => 'colour', 'name' => 'Colour', 'type' => 'text', 'value' => 'blue')), $data['custom_fields']);
pin('the location', array('Alpha', 'Aville'), array($data['location']['region']['name'], $data['location']['city']['name']));
pin('matches the schema', array(), $schemaErrors('ListingDocument', $r));
pin('reading it through the API counts no view', 0, $views($live[0]));
$out = $r->prepare('GET', '');
check('a read carries an ETag', isset($out['headers']['ETag']));
pin('If-None-Match with it answers 304 with no body', array(304, ''), (static function () use ($r, $out): array {
    $again = $r->prepare('GET', $out['headers']['ETag']);

    return array($again['status'], $again['body']);
})());
pin('an expired listing answers as its page does: shown, as expired', array(array(200, 'expired'), 200), array(
    (static fn ($r): array => array($r->status(), $r->body()['data']['status'] ?? null))($get('listings/' . $expired, array(), $publicKey)),
    $get('listings/' . $expired, array(), $otherKey)->status(),
));
foreach (array('pending' => $pending, 'disabled' => $disabled, 'spam' => $spam) as $state => $id) {
    pin("a $state listing is 404 to the public", 404, $get('listings/' . $id, array(), $publicKey)->status());
    pin("and to another user", 404, $get('listings/' . $id, array(), $otherKey)->status());
    pin("the owner sees it, as $state", array(200, $state), (static function () use ($get, $id, $sellerKey): array {
        $r = $get('listings/' . $id, array(), $sellerKey);

        return array($r->status(), $r->body()['data']['status'] ?? null);
    })());
    pin("an admin sees it", 200, $get('listings/' . $id, array(), $adminKey)->status());
}
pin('the owner sees the contact e-mail', 'contact@example.test', $get('listings/' . $live[0], array(), $sellerKey)->body()['data']['contact']['email']);
$admin->query("UPDATE {$p}t_item SET s_contact_phone = '555-0100', b_show_email = 1 WHERE pk_i_id = $expired");
pin('an expired listing shows no contact e-mail or phone', array(null, null), array_values(array_intersect_key(
    $get('listings/' . $expired, array(), $publicKey)->body()['data']['contact'],
    array('email' => 0, 'phone' => 0)
)));
$gated = $makeKernel(new ApiSettings(true, userKeys: true), null, new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')), contactNeedsSignIn: true));
$noComments = $makeKernel(new ApiSettings(true, userKeys: true), null, new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')), commentsEnabled: false));
pin('with comments off, reading them is 403 feature_disabled, as posting one is', 403, $get('listings/' . $live[0] . '/comments', array(), $publicKey, $noComments)->status());
$admin->query("UPDATE {$p}t_item SET b_show_email = 1 WHERE pk_i_id = {$live[0]}");
pin('when only signed-in users may contact, a public key sees no e-mail or phone', array(null, null), array_values(array_intersect_key(
    $get('listings/' . $live[0], array(), $publicKey, $gated)->body()['data']['contact'],
    array('email' => 1, 'phone' => 1)
)));
pin('a signed-in user sees the contact e-mail and phone', array('contact@example.test', '555-0100'), array_values(array_intersect_key(
    $get('listings/' . $live[0], array(), $otherKey, $gated)->body()['data']['contact'],
    array('email' => 1, 'phone' => 1)
)));
$admin->query("UPDATE {$p}t_item SET b_show_email = 0 WHERE pk_i_id = {$live[0]}");
check('only admins see the IP', !isset($get('listings/' . $live[0], array(), $sellerKey)->body()['data']['ip']) && $get('listings/' . $live[0], array(), $adminKey)->body()['data']['ip'] === '127.0.0.1');
pin('an admin key without admin:listings sees no hidden listing', 404, $get('listings/' . $pending, array(), $narrowKey)->status());
check('nor the IP of a live one', !isset($get('listings/' . $live[0], array(), $narrowKey)->body()['data']['ip']));
pin('nor a hidden listing\'s photos or comments', array(404, 404), array($get('listings/' . $pending . '/photos', array(), $narrowKey)->status(), $get('listings/' . $pending . '/comments', array(), $narrowKey)->status()));
pin('an unknown locale is refused', 422, $get('listings/' . $live[0], array('locale' => 'fr_FR'), $publicKey)->status());
pin('an unknown listing is 404', 404, $get('listings/999999', array(), $publicKey)->status());
pin('an unknown include is 422, as any unknown query value', 422, $get('listings/' . $live[0], array('include' => 'secrets'), $publicKey)->status());
$dbDown = static function (string $table, callable $fn) use ($admin, $p) {
    $admin->query("RENAME TABLE {$p}{$table} TO {$p}{$table}_off");
    try {
        return $fn();
    } finally {
        $admin->query("RENAME TABLE {$p}{$table}_off TO {$p}{$table}");
    }
};
$down = static fn (Response $r): array => array($r->status(), $r->prepare()['headers']['Cache-Control'] ?? null);
pin('a database error on the listing row is a 500 with no-store, not a 404', array(500, 'private, no-store'), $dbDown('t_item_description', static fn (): array => $down($get('listings/' . $live[0], array(), null, $open))));
pin('a database error on its photos is a 500, not a 200 a cache could keep without them', array(500, 'private, no-store'), $dbDown('t_resource', static fn (): array => $down($get('listings/' . $live[0], array(), null, $open))));
pin('the same on the photos endpoint', array(500, 'private, no-store'), $dbDown('t_resource', static fn (): array => $down($get('listings/' . $live[0] . '/photos', array(), null, $open))));
pin('a database error on the seller is a 500', array(500, 'private, no-store'), $dbDown('t_user', static fn (): array => $down($get('listings/' . $live[0], array(), null, $open))));
pin('a database error on custom fields is a 500', array(500, 'private, no-store'), $dbDown('t_item_meta', static fn (): array => $down($get('listings/' . $live[0], array('include' => 'custom_fields'), null, $open))));
pin('the listing answers again once the database is back', 200, $get('listings/' . $live[0], array(), null, $open)->status());

harness_section('photos and comments');
$r = $get('listings/' . $live[0] . '/photos', array(), $publicKey);
pin('a listing photos list has the thumbnail URL', array(1, 'http://localhost/oc-content/uploads/0/' . $r->body()['data'][0]['id'] . '_thumbnail.jpg'), array(count($r->body()['data']), $r->body()['data'][0]['thumbnail']));
pin('a hidden listing\'s photos are 404', 404, $get('listings/' . $pending . '/photos', array(), $publicKey)->status());
$r = $get('listings/' . $live[0] . '/comments', array('limit' => 2, 'count' => 'true'), $publicKey);
pin('approved comments only, oldest first, paged', array(array('Comment 0', 'Comment 1'), 3), array(array_column($r->body()['data'], 'title'), $r->body()['meta']['total']));
check('a comment never carries the author\'s e-mail', !str_contains((string) json_encode($r->body()), 'bo@example.test'));
parse_str((string) parse_url((string) $r->body()['links']['next'], PHP_URL_QUERY), $nextQuery);
$r = $get('listings/' . $live[0] . '/comments', $nextQuery, $publicKey);
pin('the next page has the rest, and is not counted again', array(array('Comment 2'), null, null), array(array_column($r->body()['data'], 'title'), $r->body()['links']['next'], $r->body()['meta']['total']));
pin('the page matches the schema', array(), $schemaErrors('CommentPage', $r));

harness_section('users');
$r = $get('users/' . $seller, array(), $publicKey);
pin('a public profile', array(200, 'seller', 30), array($r->status(), $r->body()['data']['username'], $r->body()['data']['listings_count'] ?? null));
check('without the e-mail', !isset($r->body()['data']['email']));
pin('matches the schema', array(), $schemaErrors('UserDocument', $r));
pin('the user themself gets the e-mail', 'seller@example.test', $get('users/' . $seller, array(), $sellerKey)->body()['data']['email']);
pin('a disabled user is 404', 404, $get('users/' . $blocked, array(), $publicKey)->status());
pin('an admin key with admin:users sees a disabled user', 200, $get('users/' . $blocked, array(), $adminKey)->status());
pin('an admin key without admin:users does not', array(404, 404), array($get('users/' . $blocked, array(), $narrowKey)->status(), $get('users/' . $blocked . '/listings', array(), $narrowKey)->status()));
check('an admin key without admin:users gets only the public profile of others', !isset($get('users/' . $seller, array(), $narrowKey)->body()['data']['email']));
$r = $get('users/' . $other . '/listings', array(), $publicKey);
pin('a user\'s live listings', array($bike), $ids($r));
pin('the user filter is refused: it is the path, not a parameter', 422, $get('users/' . $other . '/listings', array('user' => (string) $seller), $publicKey)->status());

harness_section('the seller\'s own listings');
$sellerAccount = $keys->create(CredentialKind::KEY, 'seller account', array('account:read'), KeyOwner::user($seller))->token();
$otherAccount  = $keys->create(CredentialKind::KEY, 'other account', array('account:read'), KeyOwner::user($other))->token();
$r = $get('account/listings', array('status' => 'pending,disabled,spam,expired'), $sellerAccount);
pin('every status the listings page shows, newest first', array(200, array($expired, $spam, $disabled, $pending)), array($r->status(), $ids($r)));
pin('each with its status', array('expired', 'spam', 'disabled', 'pending'), array_column($r->body()['data'] ?? array(), 'status'));
pin('matches the schema', array(), $schemaErrors('ListingPage', $r));
$r = $get('account/listings', array('count' => 'true', 'limit' => '2'), $sellerAccount);
pin('no status: all of them, counted, paged by id', array(array($expired, $spam), 29, true), array($ids($r), $r->body()['meta']['total'] ?? null, is_string($r->body()['links']['next'] ?? null)));
pin('status=active: the live ones only', array(25, $live[24]), (static fn (array $ids): array => array(count($ids), $ids[0] ?? null))($ids($get('account/listings', array('status' => 'active', 'limit' => '50'), $sellerAccount))));
pin('another user sees only their own', array($bike), $ids($get('account/listings', array(), $otherAccount)));
pin('a key without account:read is refused', 403, $get('account/listings', array(), $sellerKey)->status());
pin('a public key is refused', 403, $get('account/listings', array(), $publicKey)->status());
pin('an unknown status is 422', 422, $get('account/listings', array('status' => 'sold'), $sellerAccount)->status());

harness_section('queries');
// Warm: what a request caches once (category tree, currencies) is settled; the search cache
// is dropped so the measured call runs the search itself.
$get('listings', array('category' => 'bikes'), $publicKey);
$count = static function (array $query) use ($get, $publicKey): int {
    osc_invalidate_search_cache();

    return harness_query_count(static fn () => $get('listings', $query, $publicKey));
};
$five   = $count(array('category' => 'cars', 'limit' => 5));
$twenty = $count(array('category' => 'cars', 'limit' => 20));
echo "  a 20-listing page, warm: $twenty queries\n";
pin('a 20-listing page costs the same queries as a 5-listing page', $five, $twenty);
pin('a warm 20-listing page costs 6 queries: key, search, texts in the asked language, stats and locations, photos, sellers', 6, $twenty);
$get('listings/' . $live[0], array(), $publicKey);
pin('GET /listings/{id} with a public key: 6 queries (key, t_item, texts, stats and location, photos, seller)', 6, harness_query_count(static fn () => $get('listings/' . $live[0], array(), $publicKey)));
pin('a listing it may not see: 2 queries (key, t_item), its texts are not read', 2, harness_query_count(static fn () => $get('listings/' . $spam, array(), $publicKey)));
pin('count=true adds the count query', $twenty + 1, $count(array('category' => 'cars', 'limit' => 20, 'count' => 'true')));
pin('include=custom_fields adds one query, whatever the page size', array($twenty + 1, $twenty + 1), array(
    $count(array('category' => 'cars', 'limit' => 5, 'include' => 'custom_fields')), $count(array('category' => 'cars', 'limit' => 20, 'include' => 'custom_fields')),
));
pin('fields=id,title skips photos and sellers', $twenty - 2, $count(array('category' => 'cars', 'limit' => 20, 'fields' => 'id,title')));
$r = $get('listings', array('category' => 'cars', 'limit' => 20), $publicKey);
parse_str((string) parse_url((string) $r->body()['links']['next'], PHP_URL_QUERY), $nextQuery);
pin('a keyset page after the first skips the count, even when asked', $twenty, $count($nextQuery + array('count' => 'true')));
$r = $get('listings', array('category' => 'cars', 'limit' => 20, 'sort' => 'price'), $publicKey);
parse_str((string) parse_url((string) $r->body()['links']['next'], PHP_URL_QUERY), $nextQuery);
pin('a later sort=price page costs what the first does, with no count', array($twenty, null), array($count($nextQuery + array('count' => 'true')), $get('listings', $nextQuery + array('count' => 'true'), $publicKey)->body()['meta']['total']));

// Cold: a new kernel and kit, an empty object cache and no category or currency in memory,
// as on the first request of a process.
$cold = static function () use ($admin, $makeKernel, $publicKey): int {
    foreach (array('Category' => 'instance', 'Currency' => '_currencies') as $class => $property) {
        $reset = new ReflectionProperty($class, $property);
        $reset->setAccessible(true);
        $reset->setValue(null, null);
    }
    scratchdb_forget_cache();

    return harness_query_count(static function () use ($makeKernel, $publicKey): void {
        $kernel = $makeKernel(new ApiSettings(true, userKeys: true));
        $_GET   = array('category' => 'cars', 'limit' => 20);
        Params::init();
        $kernel->handle(new Request('GET', 'v1/listings', $_GET, array('Authorization' => 'Bearer ' . $publicKey), '127.0.0.1'));
    });
};
$coldCount = $cold();
echo "  a 20-listing page, cold: $coldCount queries\n";
pin('a cold 20-listing page adds the category rows (one read for the catalog and search) and the currencies: 8 queries', 8, $coldCount);

harness_section('text language');
// A title only in French and a description only in English: both come from French, the language with the title.
seed_locale($admin, 'fr_FR', 'French');
$admin->query("UPDATE {$p}t_item_description SET s_title = '', s_description = 'English only' WHERE fk_i_item_id = $bike");
seed_exec($admin, "INSERT INTO {$p}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, 'fr_FR', 'Velo', '')", 'i', array($bike));
scratchdb_forget_cache();
$text = static fn (array $l): array => array($l['locale'] ?? null, $l['title'] ?? null, $l['description'] ?? null);
pin('one listing asked in en_US: title and description both from fr_FR', array('fr_FR', 'Velo', ''), $text((array) ($get('listings/' . $bike, array(), $publicKey)->body()['data'] ?? array())));
pin('a search page asked in en_US: the same', array('fr_FR', 'Velo', ''), $text((array) ($get('listings', array('category' => 'bikes'), $publicKey)->body()['data'][0] ?? array())));

exit(harness_result());
