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
 * Listing rows carry the visitor's place names (web and API), and place deletes clear the names.
 * Usage: php tests/models/location-names-rows.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// This file loads page helpers that earlier files in the suite stub, so under the runner it
// runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $lnOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $lnOut, $lnCode);
    $lnOut = implode("\n", $lnOut);
    echo $lnOut, "\n";
    $lnFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $lnOut, $lnM) === 1;
    $lnFail  = $lnFound ? (int)$lnM[2] : 0;
    if (!$lnFound || ($lnCode !== 0 && $lnFail === 0)) {
        $lnFail = max(1, $lnFail);
    }
    $GLOBALS['okCount']   += $lnFound ? (int)$lnM[1] : 0;
    $GLOBALS['failCount'] += $lnFail;
    if ($lnFail > 0) {
        $GLOBALS['failLabels'][] = 'location-names-rows: ' . $lnFail . ' failed (exit ' . $lnCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_location_names_rows');

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

$GLOBALS['ln_upgrades'] = 0;
if (!function_exists('osc_prime_item_upgrades')) {
    function osc_prime_item_upgrades(array $items)
    {
        $GLOBALS['ln_upgrades']++;
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
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\location\LocationNameStore;
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
 * Fixture: one city with a German name, a listing in it, a free-text listing without ids.
 * ------------------------------------------------------------------------- */
$p       = DB_TABLE_PREFIX;
$en      = seed_locale($admin);
$de      = seed_locale($admin, 'de_DE', 'Deutsch');
$country = seed_country($admin, 'DE', 'Germany');
$region  = seed_region($admin, $country, 'Rhineland');
$city    = seed_city($admin, $region, 'Cologne', $country);
$cat     = seed_category($admin, 'Cars', null, $en);
$seller  = seed_user($admin, 'seller', 'seller@example.test');
$adminId = seed_exec($admin, "INSERT INTO {$p}t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', ?, 'a@x.test', 0)", 's', array(str_repeat('x', 60)));

$placed = static function (string $title, ?int $inRegion = null, ?int $inCity = null) use ($admin, $cat, $seller, $en, $country, $region, $city, $p): int {
    $id       = seed_item($admin, $cat, $seller, $title, 10.0, 1, 1, $en, $country);
    $inRegion ??= $region;
    $inCity   ??= $city;
    $admin->query("UPDATE {$p}t_item_location SET fk_i_region_id = $inRegion, s_region = 'Rhineland', fk_i_city_id = $inCity, s_city = 'Cologne', s_country = 'Germany' WHERE fk_i_item_id = $id");

    return $id;
};
$inCity = $placed('In Cologne');
$free   = seed_item($admin, $cat, $seller, 'Free text', 10.0, 1, 1, $en, $country);
$admin->query("UPDATE {$p}t_item_location SET fk_i_region_id = NULL, fk_i_city_id = NULL, s_region = '', s_city = 'Cologne' WHERE fk_i_item_id = $free");

foreach (array(
    'defaultResultsPerPage@search' => '12',
    'maxResultsPerPage@search'     => '50',
    'defaultOrderField@search'     => 'dt_pub_date',
    'defaultOrderType@search'      => '1',
    'language'                     => 'en_US',
    'pageTitle'                    => 'Test site',
    'currency'                     => 'USD',
    'rewriteEnabled'               => '0',
) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
harness_reset_container();
$_COOKIE['oc_userLocale'] = 'en_US';

$store = new LocationNameStore();
$store->set('city', $city, 'de_DE', 'Köln');
$store->set('region', $region, 'de_DE', 'Rheinland');
$store->set('country', 'DE', 'de_DE', 'Deutschland');
$count = static fn (): int => (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_location_name")->fetch_assoc()['c'];

harness_section('Item::extendRows()');
$rowsFor = static function (array $ids) use ($admin, $p): array {
    return $admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id IN (" . implode(',', $ids) . ') ORDER BY pk_i_id')->fetch_all(MYSQLI_ASSOC);
};
$item = Item::getInstance();
$pick = static fn (array $rows, int $id): array => array_values(array_filter($rows, static fn (array $r): bool => (int) $r['pk_i_id'] === $id))[0];
$de_  = $item->extendRows($rowsFor(array($inCity, $free)), 'de_DE');
$en_  = $item->extendRows($rowsFor(array($inCity, $free)), 'en_US');
$row  = $pick($de_, $inCity);
pin('de_DE shows the German city, region and country', array('Köln', 'Rheinland', 'Deutschland'), array($row['s_city'], $row['s_region'], $row['s_country']));
$row = $pick($en_, $inCity);
pin('en_US shows the stored names', array('Cologne', 'Rhineland', 'Germany'), array($row['s_city'], $row['s_region'], $row['s_country']));
pin('a free-text listing is unchanged in de_DE', 'Cologne', $pick($de_, $free)['s_city']);
pin('a free-text listing is unchanged in en_US', 'Cologne', $pick($en_, $free)['s_city']);

// Each listing sits in its own region and city, so the ids to look up grow with the row count.
$more = array();
for ($i = 0; $i < 8; $i++) {
    $r      = seed_region($admin, $country, 'Region ' . $i);
    $c      = seed_city($admin, $r, 'Town ' . $i, $country);
    $store->set('region', $r, 'de_DE', 'Region DE ' . $i);
    $store->set('city', $c, 'de_DE', 'Stadt ' . $i);
    $more[] = $placed('More ' . $i, $r, $c);
}
harness_assert_no_n_plus_1('extendRows() query count does not follow the row count', static function (int $n) use ($item, $rowsFor, $more): void {
    harness_reset_container();
    $item->extendRows($rowsFor(array_slice($more, 0, $n)), 'de_DE');
}, 2, 8);

harness_section('REST API');
$keys      = new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
$publicKey = $keys->create(CredentialKind::PUBLIC, 'app', array('listings:read'), KeyOwner::admin($adminId))->token();
$facts     = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr'), 'de_DE' => array('name' => 'Deutsch', 'direction' => 'ltr')));
$settings  = new ApiSettings(true, userKeys: true);
$kernel    = api_test_kernel(
    new Router(new Validator(Schema::components()), Router::core(), handlers: static function (string $class) use ($facts, $settings): object {
        $services = new ApiServices($settings, new Scopes(), new ApiCredential(), new UserRows(), new SystemClock(), api_test_limiter(), $facts, new TestLinks());

        return ($services->handlers())($class);
    }),
    api_test_authenticator($keys),
    $settings,
    validator: new Validator(Schema::components())
);
$get = static function (string $path, array $query = array()) use ($kernel, $publicKey): Response {
    $_GET = $query;
    Params::init();

    return $kernel->handle(new Request('GET', 'v1/' . $path, $query, array('Authorization' => 'Bearer ' . $publicKey), '127.0.0.1'));
};
$cityName = static fn (Response $r): array => array($r->status(), $r->body()['data']['location']['city']['name'] ?? null, $r->body()['data']['location']['region']['name'] ?? null);
pin('?locale=de_DE gives the German names', array(200, 'Köln', 'Rheinland'), $cityName($get('listings/' . $inCity, array('locale' => 'de_DE'))));
pin('no locale gives the stored names', array(200, 'Cologne', 'Rhineland'), $cityName($get('listings/' . $inCity)));
pin('a free-text listing keeps its city in de_DE', 'Cologne', $get('listings/' . $free, array('locale' => 'de_DE'))->body()['data']['location']['city']['name'] ?? null);

$get('listings', array('locale' => 'de_DE', 'limit' => '2'));
harness_assert_no_n_plus_1('the listings list query count does not follow the page size', static function (int $n) use ($get): void {
    harness_reset_container();
    scratchdb_forget_cache();
    $get('listings', array('locale' => 'de_DE', 'limit' => (string) $n));
}, 2, 8);

harness_section('Deletes clear the names');
$city2 = seed_city($admin, $region, 'Bonn', $country);
$store->set('city', $city2, 'de_DE', 'Bonn DE');
$store->set('city', $city2, 'en_US', 'Bonn EN');
$before = $count();
City::getInstance()->deleteByPrimaryKey($city2);
pin('City delete removes every language of the city', array($before - 2, array()), array($count(), $store->all('city', (string) $city2)));

$region2 = seed_region($admin, $country, 'Westphalia');
$cityA   = seed_city($admin, $region2, 'Muenster', $country);
$store->set('region', $region2, 'de_DE', 'Westfalen');
$store->set('city', $cityA, 'de_DE', 'Münster');
Region::getInstance()->deleteByPrimaryKey($region2);
pin('Region delete removes the region and its cities', array(array(), array()), array($store->all('region', (string) $region2), $store->all('city', (string) $cityA)));

$country2 = seed_country($admin, 'AT', 'Austria');
$region3  = seed_region($admin, $country2, 'Tyrol');
$store->set('country', 'AT', 'de_DE', 'Österreich');
$store->set('region', $region3, 'de_DE', 'Tirol');
Country::getInstance()->deleteByPrimaryKey('AT');
pin('Country delete removes the country and its regions', array(array(), array()), array($store->all('country', 'AT'), $store->all('region', (string) $region3)));
pin('other places keep their names', array('Köln'), array_values($store->all('city', (string) $city)));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
