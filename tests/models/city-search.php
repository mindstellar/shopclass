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
 * GET /cities: cities in any region by a typed prefix, in the asked language, with order,
 * filters, limits, the anonymous rate limit and a fixed query count.
 * Usage: php tests/models/city-search.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\serializer\Links;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\PageTokens;
use mindstellar\apikey\Scopes;
use mindstellar\location\LocationNameStore;
use mindstellar\location\LocationQuery;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

/**
 * fn(path, query): Response through the kernel ApiServices wires, for a site in English and German.
 */
function city_search_caller(array $headers, ?ApiSettings $settings = null, ?RateLimiter $limiter = null): Closure
{
    $facts = new SiteFacts('en_US', ['en_US' => ['name' => 'English', 'direction' => 'ltr'], 'de_DE' => ['name' => 'Deutsch', 'direction' => 'ltr']]);
    $links = new class () implements Links {
        public function listing(array $item): string
        {
            return 'http://localhost/item/' . $item['pk_i_id'];
        }

        public function photo(array $resource, string $variant): string
        {
            return 'http://localhost/photo/' . $resource['pk_i_id'];
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

        public function price(?int $micros, string $symbol, ?string $code = null): string
        {
            return '';
        }
    };

    return static function (string $path, array $query) use ($headers, $settings, $limiter, $facts, $links): Response {
        $services = new ApiServices($settings ?? new ApiSettings(true, userKeys: true), new Scopes(), new ApiCredential(), new UserRows(), new SystemClock(), $limiter ?? api_test_limiter(), $facts, $links);
        $_GET     = $query;
        Params::init();
        WebIdentity::forget();

        return $services->kernel()->handle(new Request('GET', 'v1/' . $path, $query, $headers, '192.0.2.70'));
    };
}

$admin = api_admin_boot('osc_models_city_search');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_locale($admin, 'de_DE', 'Deutsch');
seed_currency($admin);
foreach (['language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}

$in      = seed_country($admin, 'IN', 'India');
$de      = seed_country($admin, 'DE', 'Germany');
$mh      = seed_region($admin, $in, 'Maharashtra');
$kl      = seed_region($admin, $in, 'Kerala');
$goa     = seed_region($admin, $in, 'Goa', 0);
$bayern  = seed_region($admin, $de, 'Bavaria');
$nrw     = seed_region($admin, $de, 'North Rhine-Westphalia');
$city    = static fn (int $region, string $name, string $country, int $items = 0, int $active = 1): int => seed_exec(
    $admin,
    "INSERT INTO {$p}t_city (fk_i_region_id, s_name, s_slug, fk_c_country_code, b_active, i_num_items) VALUES (?, ?, ?, ?, ?, ?)",
    'isssii',
    [$region, $name, strtolower($name), $country, $active, $items]
);
$mumbai  = $city($mh, 'Mumbai', $in, 50);
$munnar  = $city($kl, 'Munnar', $in, 5);
$city($mh, 'Pune Cantonment', $in, 30);
$pune    = $city($mh, 'Pune', $in, 1);
$city($mh, 'Mundra', $in, 99, 0);
$city($goa, 'Murud', $in, 99);
$munich  = $city($bayern, 'Munich', $de, 2);
$city($mh, 'Zo_e', $in);
$city($mh, 'Zoie', $in);
$city($mh, 'Navi Mumbai', $in, 80);
$cologne = $city($nrw, 'Cologne', $de);
$koblenz = $city($nrw, 'Coblence', $de);
$store   = new LocationNameStore();
$store->set('city', (string) $munich, 'de_DE', 'München');
$store->set('city', (string) $cologne, 'de_DE', 'Köln');
$store->set('city', (string) $koblenz, 'de_DE', 'Koblenz');
$store->set('region', (string) $nrw, 'de_DE', 'Nordrhein-Westfalen');
$store->set('country', 'DE', 'de_DE', 'Deutschland');
$store->set('city', (string) $pune, 'de_DE', 'Altmunde');
scratchdb_forget_cache();
osc_reset_preferences();
harness_reset_container();

$guest = ['Origin' => 'http://localhost', 'X-Shopclass-Token' => (new PageTokens())->issueGuest()->token()];
$call  = city_search_caller($guest);
$get   = static fn (array $query): Response => $call('cities', $query);
$names = static fn (Response $r): array => array_column((array) ($r->body()['data'] ?? []), 'name');

harness_section('matching');
$r = $get(['q' => 'mu']);
pin('q=mu finds active cities in active regions of every region, biggest first', [200, ['Mumbai', 'Munnar', 'Munich']], [$r->status(), $names($r)]);
pin('each row has its region and country', [
    'id' => $mumbai, 'name' => 'Mumbai', 'region_id' => $mh, 'region' => 'Maharashtra', 'country_code' => 'IN', 'country' => 'India', 'label' => 'Mumbai, Maharashtra',
], $r->body()['data'][0] ?? null);
pin('Munnar comes from its own region', [$munnar, $kl, 'Kerala'], [$r->body()['data'][1]['id'] ?? null, $r->body()['data'][1]['region_id'] ?? null, $r->body()['data'][1]['region'] ?? null]);
pin('the answer matches its schema', [], api_admin_schema_errors('CitySuggestionList', $r));
pin('nothing more matches', 0, $r->body()['meta']['more'] ?? null);
pin('case does not matter', ['Mumbai', 'Munnar', 'Munich'], $names($get(['q' => 'MU'])));
pin('an exact name comes before a bigger one that starts with it', ['Pune', 'Pune Cantonment'], $names($get(['q' => 'pune'])));
pin('a name with the text in the middle is not a match', [], array_values(array_intersect(['Navi Mumbai', 'Altmunde', 'Pune'], $names($get(['q' => 'mu', 'locale' => 'de_DE', 'limit' => '20'])))));
pin('q is trimmed', $pune, $get(['q' => '  Pune  '])->body()['data'][0]['id'] ?? null);

harness_section('translated names');
$r = $get(['q' => 'Köl', 'locale' => 'de_DE']);
pin('a translated name is found and shown in that language', [[$cologne, 'Köln', 'Nordrhein-Westfalen', 'Deutschland', 'Köln, Nordrhein-Westfalen']], array_map(static fn (array $c): array => [$c['id'], $c['name'], $c['region'], $c['country'], $c['label']], $r->body()['data']));
pin('without a locale it is shown by its stored name', ['Cologne'], $names($get(['q' => 'Köl'])));
pin('a stored-name match is shown in the asked language too', 'München', $names($get(['q' => 'mu', 'locale' => 'de_DE']))[2] ?? null);
pin('a city found both ways is listed once', ['Munich'], $names($get(['q' => 'Mun', 'country' => 'DE'])));

harness_section('filters');
pin('country keeps one country\'s cities', ['Munich'], $names($get(['q' => 'mu', 'country' => 'DE'])));
pin('a lower-case code works', ['Munich'], $names($get(['q' => 'mu', 'country' => 'de'])));
pin('an unknown country is an empty list', [200, []], [($r = $get(['q' => 'mu', 'country' => 'ZZ']))->status(), $names($r)]);
$r = $get(['q' => 'mu', 'country' => 'XYZ']);
pin('a malformed country is 422 on /country', [422, '/country'], [$r->status(), $r->body()['errors'][0]['pointer'] ?? null]);
pin('one character finds nothing', [200, [], 0], [($r = $get(['q' => 'm']))->status(), $names($r), $r->body()['meta']['more'] ?? null]);
pin('no q finds nothing', [200, []], [($r = $get([]))->status(), $names($r)]);
pin('% in q is a plain character', [], $names($get(['q' => '%u'])));
pin('_ in q is a plain character', ['Zo_e'], $names($get(['q' => 'Zo_'])));

harness_section('translated names in scope');
for ($i = 0; $i <= LocationQuery::SEARCH_MORE_CAP; $i++) {
    $store->set('city', (string) $city($bayern, 'Hidden ' . $i, $de, 0, 0), 'de_DE', 'Qa' . $i);
}
// The last id and the last name, so it is last whichever index the names read walks.
$quiet = 99999;
seed_exec($admin, "INSERT INTO {$p}t_city (pk_i_id, fk_i_region_id, s_name, s_slug, fk_c_country_code, b_active) VALUES (?, ?, 'Quietpur', 'quietpur', ?, 1)", 'iis', [$quiet, $mh, $in]);
$store->set('city', (string) $quiet, 'de_DE', 'Qaz');
scratchdb_forget_cache();
pin('inactive translated matches do not crowd out an active one', [$quiet], array_column($get(['q' => 'Qa', 'limit' => '1', 'locale' => 'de_DE'])->body()['data'], 'id'));
pin('a country filter applies to the translated read too', [$quiet], array_column($get(['q' => 'Qa', 'country' => 'IN', 'limit' => '1'])->body()['data'], 'id'));

harness_section('limit');
foreach (['Murbad', 'Mulshi', 'Murtizapur'] as $i => $name) {
    $city($mh, $name, $in, 10 + $i);
}
$r = $get(['q' => 'mu', 'limit' => '3']);
pin('limit=3 gives 3', 3, count($r->body()['data'] ?? []));
pin('meta.more counts the rest', [3, 3], [$r->body()['meta']['more'] ?? null, $r->body()['meta']['limit'] ?? null]);
pin('the default is 8', 6, count($get(['q' => 'mu'])->body()['data']));
pin('limit 21 is 422', 422, $get(['q' => 'mu', 'limit' => '21'])->status());
pin('limit 20 is fine', 200, $get(['q' => 'mu', 'limit' => '20'])->status());
pin('a translated-only match counts in more', [1, 1], [count(($r = $get(['q' => 'Kö', 'limit' => '1']))->body()['data']), $r->body()['meta']['more'] ?? null]);

$capIds = [];
for ($i = 0; $i < LocationQuery::SEARCH_MORE_CAP + 30; $i++) {
    $capIds[] = $city($mh, 'Capville ' . $i, $in);
    $other    = $city($mh, 'Other ' . $i, $in);
    $capIds[] = $other;
    $store->set('city', (string) $other, 'de_DE', 'Capville T' . $i);
}
scratchdb_forget_cache();
pin('meta.more stops at the cap when more cities match', [1, LocationQuery::SEARCH_MORE_CAP], [count(($r = $get(['q' => 'Capville', 'limit' => '1']))->body()['data']), $r->body()['meta']['more'] ?? null]);
$ids = implode(',', array_map('intval', $capIds));
seed_exec($admin, "DELETE FROM {$p}t_location_name WHERE e_place_type = 'city' AND s_place_id IN ({$ids})", '', []);
seed_exec($admin, "DELETE FROM {$p}t_city WHERE pk_i_id IN ({$ids})", '', []);
scratchdb_forget_cache();
pin('the cap rows are gone again', [], $names($get(['q' => 'Capville'])));

harness_section('who may call');
$regions = $call('regions/' . $mh . '/cities', ['q' => 'mu']);
$r       = $get(['q' => 'mu']);
pin('a guest page token reads it, cached like the other location reads', [200, $regions->header('Cache-Control'), $regions->header('Vary')], [$r->status(), $r->header('Cache-Control'), $r->header('Vary')]);
$counts  = [];
$limiter = new RateLimiter(static function (string $bucket, string $key) use (&$counts): int {
    return $counts[$bucket . '|' . $key] = ($counts[$bucket . '|' . $key] ?? 0) + 1;
}, new SystemClock());
$small = city_search_caller($guest, new ApiSettings(true, 120, 2, userKeys: true), $limiter);
$small('cities', ['q' => 'mu']);
pin('two calls are within the anonymous limit', 200, $small('cities', ['q' => 'mu'])->status());
pin('a guest over api_rate_limit_anon is 429', 429, $small('cities', ['q' => 'mu'])->status());
check('it is counted in the per-address anonymous bucket', array_filter(array_keys($counts), static fn (string $k): bool => str_starts_with($k, 'api_anon|')) !== []);

harness_section('queries');
$places = new LocationQuery();
pin('a search is three statements', 3, harness_query_count(static function () use ($places): void {
    $places->searchCities('mu', null, 8);
}));
pin('with a country too', 3, harness_query_count(static function () use ($places): void {
    $places->searchCities('mu', 'IN', 8);
}));
harness_assert_no_n_plus_1('the route\'s query count does not follow the limit', static function (int $n) use ($get): void {
    harness_reset_container();
    $get(['q' => 'mu', 'limit' => (string) $n, 'locale' => 'de_DE']);
}, 2, 8);

harness_section('documented');
$doc = OpenApi::core()->build();
pin('the OpenAPI document lists GET /cities with its list', '#/components/schemas/CitySuggestionList', $doc['paths']['/cities']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);

exit(harness_result());
