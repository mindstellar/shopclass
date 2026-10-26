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
 * The location endpoints in the requested language: names, fallback, 422, and ?q= matching a
 * name saved in any language.
 * Usage: php tests/models/api-locations.php
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

$admin = scratchdb_session('osc_models_api_locations');

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

$p        = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
$country  = seed_country($admin, 'US', 'United States');
$region   = seed_region($admin, $country, 'Alpha');
$city     = seed_city($admin, $region, 'Aville', $country);
seed_exec($admin, "INSERT INTO {$p}t_city_area (fk_i_city_id, s_name) VALUES (?, 'Downtown')", 'i', array($city));
$adminId  = seed_exec($admin, "INSERT INTO {$p}t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', ?, 'a@x.test', 0)", 's', array(str_repeat('x', 60)));

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
$adminKey  = $keys->create(CredentialKind::KEY, 'admin', array('admin:listings', 'admin:users'), KeyOwner::admin($adminId))->token();

$facts = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr'), 'de_DE' => array('name' => 'Deutsch', 'direction' => 'ltr')));
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

seed_locale($admin, 'de_DE', 'Deutsch');
$ch = seed_country($admin, 'CH', 'Switzerland');
$bern = seed_region($admin, $ch, 'Bern');
$zurich = seed_region($admin, $ch, 'Zurich');
$store = new LocationNameStore();
$store->set('country', 'US', 'de_DE', 'Vereinigte Staaten');
$store->set('country', 'CH', 'de_DE', 'Schweiz');
$store->set('region', (string) $region, 'de_DE', 'Zeta');
$store->set('city', (string) $city, 'de_DE', 'Aburg');
$store->set('region', (string) $zurich, 'de_DE', 'Zuerich');
harness_reset_container();
scratchdb_forget_cache();

$names = static fn (Response $r): array => array_column((array) ($r->body()['data'] ?? array()), 'name');

harness_section('names in the asked language');
pin('countries without a locale are the stored names', array('Switzerland', 'United States'), $names($get('countries', array(), $publicKey)));
pin('countries in German, sorted by the German name', array('Schweiz', 'Vereinigte Staaten'), $names($get('countries', array('locale' => 'de_DE'), $publicKey)));
pin('the code and slug do not change', array(array('code' => 'CH', 'slug' => 'ch'), array('code' => 'US', 'slug' => 'us')), array_map(static fn (array $c): array => array('code' => $c['code'], 'slug' => $c['slug']), $get('countries', array('locale' => 'de_DE'), $publicKey)->body()['data']));
pin('a language the site does not have is 422', 422, $get('countries', array('locale' => 'xx_XX'), $publicKey)->status());
pin('a locale the site has but the place has no name in falls back', array('Switzerland', 'United States'), $names($get('countries', array('locale' => 'en_US'), $publicKey)));
pin('regions in German; one without a German name keeps its stored name', array('Bern', 'Zuerich'), $names($get('countries/ch/regions', array('locale' => 'de_DE'), $publicKey)));
pin('regions without a locale', array('Bern', 'Zurich'), $names($get('countries/ch/regions', array(), $publicKey)));
pin('cities in German', array('Aburg'), $names($get('regions/' . $region . '/cities', array('locale' => 'de_DE'), $publicKey)));
pin('areas are not translated', array('Downtown'), $names($get('cities/' . $city . '/areas', array('locale' => 'de_DE'), $publicKey)));
pin('a translated list matches the schema', array(), $schemaErrors('RegionList', $get('countries/ch/regions', array('locale' => 'de_DE'), $publicKey)));

harness_section('?q= in any language');
pin('a German prefix finds the place whose only German name starts so', array('Zuerich'), $names($get('countries/ch/regions', array('q' => 'Zue', 'locale' => 'de_DE'), $publicKey)));
pin('it finds it without a locale too, shown by its stored name', array('Zurich'), $names($get('countries/ch/regions', array('q' => 'Zue'), $publicKey)));
pin('the stored prefix still works', array('Zuerich'), $names($get('countries/ch/regions', array('q' => 'Zur', 'locale' => 'de_DE'), $publicKey)));
pin('a prefix with no translated match returns what it did', array('Bern'), $names($get('countries/ch/regions', array('q' => 'Be'), $publicKey)));
pin('a prefix nobody has is empty', array(), $names($get('countries/ch/regions', array('q' => 'Nowhere', 'locale' => 'de_DE'), $publicKey)));
pin('countries by a German prefix', array('Schweiz'), $names($get('countries', array('q' => 'Schw', 'locale' => 'de_DE'), $publicKey)));
pin('a LIKE wildcard in q is plain text', array(), $names($get('countries/ch/regions', array('q' => '%', 'locale' => 'de_DE'), $publicKey)));

exit(harness_result());
