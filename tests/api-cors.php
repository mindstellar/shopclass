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
 * CORS: listed origins answered exactly, `*` only for calls with no key or a public key,
 * the preflight (no credential, not counted), the headers a browser app may read, Vary,
 * the `api_cors_origins` filter, and nothing at all when no origin is listed.
 *
 * DB-free.  Usage: php tests/api-cors.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ApiCall;
use mindstellar\api\http\Cors;
use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\CredentialStore;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\StoredKey;
use mindstellar\utility\SystemClock;

/** One stored key at a time is enough here. */
final class CorsStore implements CredentialStore
{
    /** @var StoredKey[] */
    public array $keys = [];

    public function findByTokenId(string $tokenId): ?StoredKey
    {
        foreach ($this->keys as $key) {
            if ($key->tokenId() === $tokenId) {
                return $key;
            }
        }

        return null;
    }

    public function find(int $id): ?StoredKey
    {
        return $this->keys[$id] ?? null;
    }

    public function insert(StoredKey $key): int
    {
        $id              = count($this->keys) + 1;
        $this->keys[$id] = new StoredKey($id, $key->kind(), $key->tokenId(), $key->secretHash(), $key->name(), $key->scopes(), $key->owner());

        return $id;
    }

    public function touch(int $id, string $ip, int $time): void
    {
    }

    public function revoke(int $id): bool
    {
        return false;
    }
}

$store  = new CorsStore();
$keys   = new ApiKeys($store, new Scopes(), new SystemClock());
$admin  = $keys->create(CredentialKind::KEY, 'Admin', ['admin:users'], KeyOwner::admin(1))->token();
$public = $keys->create(CredentialKind::PUBLIC, 'App', ['listings:read'], KeyOwner::admin(1))->token();

$echo   = static fn (ApiCall $call): Response => Response::ok(['kind' => $call->credential()->kind()]);
$routes = [
    'GET site'   => ['handler' => $echo, 'auth' => RouteSpec::AUTH_PUBLIC],
    'GET users'  => ['handler' => $echo, 'auth' => RouteSpec::AUTH_ADMIN, 'scope' => 'admin:users'],
    'POST users' => ['handler' => $echo, 'auth' => RouteSpec::AUTH_ADMIN, 'scope' => 'admin:users'],
    'GET broken' => ['handler' => static function (): Response {
        throw new RuntimeException('database went away');
    }, 'auth' => RouteSpec::AUTH_ADMIN, 'scope' => 'admin:users'],
];
$counted = 0;
$kernel  = static function (string $origins) use ($routes, $keys, &$counted): Kernel {
    $settings = new ApiSettings(true, true, 120, 60, 30, 60, false, $origins);

    return api_test_kernel(
        new Router(new Validator(), $routes),
        api_test_authenticator($keys),
        $settings,
        api_test_limiter(static function () use (&$counted): int {
            return ++$counted;
        })
    );
};
$req = static function (string $method, string $path, string $origin = '', string $token = '', array $more = []): Request {
    $headers = $more;
    if ($origin !== '') {
        $headers['Origin'] = $origin;
    }
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    return new Request($method, $path, [], $headers, '198.51.100.9');
};

harness_section('which origin is allowed');
$listed = new Cors(['https://App.test/', '']);
$any    = new Cors(['*']);
$both   = new Cors(['*', 'https://app.test']);
pin('a listed origin is echoed, in any case and without a trailing slash', 'https://app.test', $listed->allowedOrigin($req('GET', 'v1', 'https://app.test')));
pin('an origin not listed gets nothing', null, $listed->allowedOrigin($req('GET', 'v1', 'https://evil.test')));
pin('no Origin header gets nothing', null, $listed->allowedOrigin($req('GET', 'v1')));
pin('* answers a call with no key', '*', $any->allowedOrigin($req('GET', 'v1', 'https://anyone.test')));
pin('and a call with a public key', '*', $any->allowedOrigin($req('GET', 'v1', 'https://anyone.test', $public)));
pin('but not a call with any other key', null, $any->allowedOrigin($req('GET', 'v1', 'https://anyone.test', $admin)));
pin('a keyed call from an exactly listed origin is answered', 'https://app.test', $both->allowedOrigin($req('GET', 'v1', 'https://app.test', $admin)));
pin('Basic auth in front is not a key', '*', $any->allowedOrigin($req('GET', 'v1', 'https://anyone.test', '', ['Authorization' => 'Basic eDp5'])));
check('nothing listed means CORS is off', !(new Cors(['', ' ']))->enabled());

harness_section('preflight');
$k       = $kernel("https://app.test\n*");
$counted = 0;
$r       = $k->handle($req('OPTIONS', 'v1/users', 'https://app.test', '', ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization, idempotency-key']));
pin('a preflight is 204 with no body', [204, ''], [$r->status(), $r->prepare('OPTIONS')['body']]);
pin('it echoes the origin', 'https://app.test', $r->header('Access-Control-Allow-Origin'));
pin('it lists the methods the path answers', 'GET, POST, OPTIONS', $r->header('Access-Control-Allow-Methods'));
$allowHeaders = array_map('trim', explode(',', (string) $r->header('Access-Control-Allow-Headers')));
check('it allows Authorization, Content-Type, Idempotency-Key and If-None-Match', array_diff(['Authorization', 'Content-Type', 'Idempotency-Key', 'If-None-Match'], $allowHeaders) === []);
pin('a browser may reuse it for ten minutes', '600', $r->header('Access-Control-Max-Age'));
pin('credentials are never allowed', null, $r->header('Access-Control-Allow-Credentials'));
pin('it needs no credential and is not counted', 0, $counted);
pin('it varies on Origin', 'Origin', $r->header('Vary'));
$r = $k->handle($req('OPTIONS', 'v1/users', 'https://other.test', '', ['Access-Control-Request-Method' => 'GET']));
pin('a preflight from another origin is answered by * (no credential rides on it)', '*', $r->header('Access-Control-Allow-Origin'));
$r = $kernel('https://app.test')->handle($req('OPTIONS', 'v1/users', 'https://other.test', '', ['Access-Control-Request-Method' => 'GET']));
pin('with no * it gets no grant, only Allow', [204, null, 'GET, POST, HEAD, OPTIONS'], [$r->status(), $r->header('Access-Control-Allow-Origin'), $r->header('Allow')]);
pin('a plain OPTIONS lists Allow', 'GET, POST, HEAD, OPTIONS', $k->handle($req('OPTIONS', 'v1/users'))->header('Allow'));
pin('OPTIONS on an unknown path is 404', 404, $k->handle($req('OPTIONS', 'v1/nothing', 'https://app.test', '', ['Access-Control-Request-Method' => 'GET']))->status());

harness_section('actual calls');
$r = $k->handle($req('GET', 'v1/users', 'https://app.test', $admin));
pin('a keyed call from the listed origin is granted', 'https://app.test', $r->header('Access-Control-Allow-Origin'));
$expose = array_map('trim', explode(',', (string) $r->header('Access-Control-Expose-Headers')));
check('the app may read ETag, Location, the rate limit headers, Deprecation and Sunset', array_diff(['ETag', 'Location', 'RateLimit', 'RateLimit-Policy', 'Retry-After', 'Deprecation', 'Sunset'], $expose) === []);
pin('a cacheable answer varies on Authorization, the page token and Origin', 'Authorization, X-Shopclass-Token, Origin', $r->header('Vary'));
pin('a keyed call from another origin is not granted, though * is listed', null, $k->handle($req('GET', 'v1/users', 'https://other.test', $admin))->header('Access-Control-Allow-Origin'));
pin('an anonymous call from another origin gets *', '*', $k->handle($req('GET', 'v1/site', 'https://other.test'))->header('Access-Control-Allow-Origin'));
pin('so does a public key', '*', $k->handle($req('GET', 'v1/site', 'https://other.test', $public))->header('Access-Control-Allow-Origin'));
$r = $k->handle($req('GET', 'v1/users', 'https://app.test'));
pin('a refusal is granted too, so the app can read the problem', [401, 'https://app.test', 'Origin'], [$r->status(), $r->header('Access-Control-Allow-Origin'), $r->header('Vary')]);
pin('a write varies on Origin only', 'Origin', $k->handle($req('POST', 'v1/users', 'https://app.test', $admin))->header('Vary'));

$logged = ini_set('error_log', '/dev/null');
$r      = $k->handle($req('GET', 'v1/broken', 'https://app.test', $admin));
ini_set('error_log', (string) $logged);
pin('an unexpected error is a 500 problem that still carries CORS and rate-limit headers', [500, 'server_error', 'https://app.test', true], [
    $r->status(), $r->body()['code'] ?? null, $r->header('Access-Control-Allow-Origin'), $r->header('RateLimit') !== null,
]);
check('...and never the error text', !str_contains((string) json_encode($r->body()), 'database went away'));

harness_section('the api_cors_origins filter');
$r = api_with_filter('api_cors_origins', static fn (array $origins, Request $request): array => array_merge($origins, ['https://partner.test']), static fn () => $kernel('')->handle($req('GET', 'v1/users', 'https://partner.test', $admin)));
pin('a plugin can add an origin', 'https://partner.test', $r->header('Access-Control-Allow-Origin'));

harness_section('CORS off');
$r = $kernel('')->handle($req('GET', 'v1/users', 'https://app.test', $admin));
pin('with no origin listed there is no grant and no Vary: Origin', [null, null, 'Authorization, X-Shopclass-Token'], [$r->header('Access-Control-Allow-Origin'), $r->header('Access-Control-Expose-Headers'), $r->header('Vary')]);

exit(harness_result());
