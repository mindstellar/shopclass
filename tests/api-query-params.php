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
 * A read refuses a query parameter it does not take with 422, naming it; `api_key` always
 * passes, and every parameter a core read handler uses is declared on its route.
 *
 * DB-free, no network.  Usage: php tests/api-query-params.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\routing\RouteTable;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\Scopes;
use mindstellar\utility\SystemClock;

$store = new class () implements \mindstellar\apiaccess\CredentialStore {
    public function findByTokenId(string $tokenId): ?\mindstellar\apiaccess\StoredKey
    {
        return null;
    }
    public function find(int $id): ?\mindstellar\apiaccess\StoredKey
    {
        return null;
    }
    public function insert(\mindstellar\apiaccess\StoredKey $key): int
    {
        return 1;
    }
    public function touch(int $id, string $ip, int $time): void
    {
    }
    public function revoke(int $id): bool
    {
        return false;
    }
};

harness_section('the Kernel');
$spec      = RouteSpec::read([stdClass::class, 'x'], 'T', 'A read', 'Problem', ['limit' => ['type' => 'integer', 'minimum' => 1]]);
$spec      = ['handler' => static fn (Request $r, Credential $c, array $a): Response => Response::ok(['query' => $r->query()]), 'auth' => RouteSpec::AUTH_NONE] + $spec;
$validator = new Validator([]);
$kernel    = api_test_kernel(new Router($validator, ['GET things' => $spec]), api_test_authenticator(new ApiKeys($store, new Scopes(), new SystemClock())), validator: $validator);
$call      = static fn (array $query): Response => $kernel->handle(new Request('GET', 'v1/things', $query, [], '127.0.0.1'));

$r = $call(['limit' => '5']);
pin('a declared parameter passes, typed', [200, ['limit' => 5]], [$r->status(), $r->body()['data']['query'] ?? null]);
$r = $call(['bogus' => '1']);
pin('an unknown one is 422 and names it', [422, '/bogus', 'query'], [$r->status(), $r->body()['errors'][0]['pointer'] ?? null, $r->body()['errors'][0]['in'] ?? null]);
pin('api_key passes though no route declares it', 200, $call(['api_key' => 'scp_x.y'])->status());
pin('a declared one beside an unknown one is still 422', 422, $call(['limit' => '5', 'bogus' => '1'])->status());

harness_section('core routes');
$reads = array_filter(RouteTable::core(), static fn (string $k): bool => str_starts_with($k, 'GET '), ARRAY_FILTER_USE_KEY);
pin('every core read refuses unknown parameters', [], array_keys(array_filter($reads, static fn (array $s): bool => ($s['query']['additionalProperties'] ?? true) !== false)));
$declared = static fn (string $key): array => array_keys($reads[$key]['query']['properties']);
$paging   = ['limit', 'cursor'];
pin('listing search declares its filters, sort, paging, count, view and include', [], array_diff(
    ['q', 'category', 'country', 'region', 'city', 'city_area', 'user', 'locale', 'price_min', 'price_max', 'with_photos', 'premium', 'custom_field', 'sort', 'order', 'limit', 'cursor', 'count', 'fields', 'include'],
    $declared('GET listings')
));
pin('a seller\'s listings declare the same, bar user', [], array_diff(
    ['q', 'category', 'country', 'region', 'city', 'city_area', 'locale', 'price_min', 'price_max', 'with_photos', 'premium', 'custom_field', 'sort', 'order', 'limit', 'cursor', 'count', 'fields', 'include'],
    $declared('GET users/{id}/listings')
));
pin('every paged list declares limit and cursor', [], array_filter(
    ['GET listings/{id}/comments', 'GET countries', 'GET countries/{code}/regions', 'GET regions/{id}/cities', 'GET cities/{id}/areas', 'GET admin/listings', 'GET admin/comments', 'GET admin/users'],
    static fn (string $k): bool => array_diff($paging, $declared($k)) !== []
));
pin('every list that counts declares count', [], array_filter(
    ['GET listings/{id}/comments', 'GET admin/listings', 'GET admin/comments', 'GET admin/users'],
    static fn (string $k): bool => !in_array('count', $declared($k), true)
));
pin('reads that build a view declare locale and fields', [], array_filter(
    ['GET listings/{id}', 'GET categories', 'GET categories/{category}', 'GET users/{id}', 'GET account', 'GET admin/listings', 'GET admin/listings/{id}', 'GET admin/users', 'GET admin/users/{id}'],
    static fn (string $k): bool => array_diff(['locale', 'fields'], $declared($k)) !== []
));
pin('reads that take include declare it', [], array_filter(
    ['GET listings', 'GET listings/{id}', 'GET users/{id}/listings', 'GET admin/listings', 'GET admin/listings/{id}'],
    static fn (string $k): bool => !in_array('include', $declared($k), true)
));
pin('the category tree and field list declare their own', [[true], [true]], [
    [in_array('tree', $declared('GET categories'), true)], [in_array('category', $declared('GET custom-fields'), true)],
]);

exit(harness_result());
