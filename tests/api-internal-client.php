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
 * The in-process API client: it answers while the API is off, is never rate limited, gets no
 * CORS or cache headers, acts as the page's own user or admin, and still fires the hooks. An
 * HTTP request cannot pass for one. DB-free. Usage: php tests/api-internal-client.php
 */

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

use mindstellar\api\ApiCall;
use mindstellar\api\InternalClient;
use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apikey\ApiAccess;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\Credential;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\Scopes;
use mindstellar\utility\SystemClock;

$echo   = static fn (ApiCall $call): Response => Response::ok([
    'kind'  => $call->credential()->kind(),
    'user'  => $call->credential()->userId(),
    'admin' => $call->credential()->adminId(),
    'query' => $call->request()->query(),
    'input' => $call->input(),
]);
$routes = [
    'GET ping'        => ['handler' => $echo, 'auth' => RouteSpec::AUTH_NONE],
    'GET listings'    => ['handler' => $echo, 'auth' => RouteSpec::AUTH_PUBLIC, 'scope' => Scopes::PUBLIC_READ],
    'GET me'          => ['handler' => $echo, 'auth' => RouteSpec::AUTH_USER, 'scope' => 'account:read'],
    'PATCH me'        => ['handler' => $echo, 'auth' => RouteSpec::AUTH_USER, 'scope' => 'account:write'],
    'POST me/alerts'  => ['handler' => $echo, 'auth' => RouteSpec::AUTH_USER, 'scope' => 'alerts:write'],
    'POST contact'    => ['handler' => $echo, 'auth' => RouteSpec::AUTH_NONE],
    'GET admin/users' => ['handler' => $echo, 'auth' => RouteSpec::AUTH_ADMIN, 'scope' => 'admin:users'],
];
$hits   = 0;
$kernel = static function (bool $enabled) use ($routes, &$hits): Kernel {
    return api_test_kernel(
        new Router(new Validator(), $routes),
        api_test_authenticator(new ApiKeys(new class () implements \mindstellar\apikey\CredentialStore {
            public function findByTokenId(string $tokenId): ?\mindstellar\apikey\StoredKey
            {
                return null;
            }

            public function find(int $id): ?\mindstellar\apikey\StoredKey
            {
                return null;
            }

            public function insert(\mindstellar\apikey\StoredKey $key): int
            {
                return 0;
            }

            public function touch(int $id, string $ip, int $time): void
            {
            }

            public function revoke(int $id): bool
            {
                return false;
            }
        }, new Scopes(), new SystemClock())),
        new ApiSettings($enabled, corsOrigins: '*'),
        api_test_limiter(static function () use (&$hits): int {
            return ++$hits;
        })
    );
};
$scopes  = new Scopes();
$visitor = InternalClient::callerFor($scopes, 0, null);
$user    = InternalClient::callerFor($scopes, 7, null);
$admin   = InternalClient::callerFor($scopes, 0, ['pk_i_id' => '3', 'b_moderator' => '0']);
$client  = static fn (Kernel $k, Credential $as): InternalClient => new InternalClient($k, static fn (): Credential => $as, '203.0.113.5');

harness_section('the API switch');
$off = $kernel(false);
$r   = $client($off, $visitor)->call('GET', 'listings');
pin('with the API off, an internal GET listings answers 200', 200, $r['status']);
pin('over HTTP the same kernel refuses it', 403, $off->handle(new Request('GET', 'v1/listings', [], [], '203.0.113.5'))->status());

harness_section('no rate limit, CORS or cache headers');
$on   = $kernel(true);
$hits = 0;
$client($on, $visitor)->call('GET', 'listings');
$client($on, $user)->call('GET', 'me');
pin('internal calls never hit the limiter', 0, $hits);
$on->handle(new Request('GET', 'v1/ping', [], [], '203.0.113.5'));
check('an HTTP call on the same kernel does', $hits > 0);
$hits = 0;
$client($on, $visitor)->call('GET', 'ping');
pin('not even on a route that needs no credential', 0, $hits);
$headers = $client($on, $visitor)->call('GET', 'listings')['headers'];
$names   = array_map('strtolower', array_keys($headers));
pin('no Access-Control-* header', [], array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'access-control-'))));
pin('no Vary, Cache-Control or RateLimit header', [], array_values(array_intersect($names, ['vary', 'cache-control', 'ratelimit', 'ratelimit-policy', 'x-ratelimit-limit'])));
check('it still carries a request id', isset($headers['Request-Id']));

harness_section('an HTTP request cannot pass for one');
$http = new Request('GET', 'v1/listings', [], ['X-Shopclass-Internal' => '1'], '203.0.113.5');
check('a constructed request is not internal and has no caller', !$http->isInternal() && $http->caller() === null);
check('nor is a copy of one', !$http->withQuery(['a' => '1'])->isInternal() && !$http->asRead()->isInternal() && !$http->forUpload()->isInternal());
$takes = array_filter(
    (new ReflectionMethod(Request::class, '__construct'))->getParameters(),
    static fn (ReflectionParameter $p): bool => str_contains((string) $p->getType(), 'Credential')
);
pin('the constructor takes no credential', [], array_values($takes));
check('fromGlobals() never sets the caller', !str_contains(harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/api/Request.php', 'fromGlobals'), 'caller'));
check('an internal request keeps its caller through a copy', Request::internal('GET', 'listings', [], [], $user)->withQuery([])->caller() === $user);

harness_section('who it acts as');
pin('a visitor reads public data only', [CredentialKind::ANONYMOUS, Scopes::PUBLIC], [$visitor->kind(), $visitor->scopes()]);
$me = $client($on, $user)->call('GET', 'me');
pin('an AUTH_USER route acts as the web user', [200, 'session', 7], [$me['status'], $me['body']['data']['kind'], $me['body']['data']['user']]);
pin('a visitor gets 403 there', 403, $client($on, $visitor)->call('GET', 'me')['status']);
pin('an admin route from a public page answers 403, for the web user', 403, $client($on, $user)->call('GET', 'admin/users')['status']);
pin('and for a visitor', 403, $client($on, $visitor)->call('GET', 'admin/users')['status']);
$asAdmin = $client($on, $admin)->call('GET', 'admin/users');
pin('the admin on an admin page reaches it as that admin', [200, 3], [$asAdmin['status'], $asAdmin['body']['data']['admin']]);
$moderator = InternalClient::callerFor($scopes, 0, ['pk_i_id' => '4', 'b_moderator' => '1']);
check('a moderator is a moderator', $moderator->isModerator() && !$moderator->has('admin:users'));
pin('an admin row wins over a web user', 3, InternalClient::callerFor($scopes, 7, ['pk_i_id' => '3', 'b_moderator' => '0'])->adminId());

harness_section('a signed-in caller only reads');
$write = static fn (Credential $as, string $method, string $path): array => (static fn (array $r): array => [$r['status'], $r['body']['code'] ?? null])($client($on, $as)->call($method, $path, [], ['pattern' => 'bike']));
pin('the web user cannot POST', [403, 'wrong_credential'], $write($user, 'POST', 'me/alerts'));
pin('nor PATCH', [403, 'wrong_credential'], $write($user, 'patch', 'me'));
pin('nor the admin on an admin page', [403, 'wrong_credential'], $write($admin, 'DELETE', 'admin/users'));
pin('a visitor still writes to a public route', [200, null], $write($visitor, 'POST', 'contact'));

harness_section('query and body');
$sent = $client($on, $visitor)->call('POST', 'contact', ['a' => '1'], ['pattern' => 'bike', 'é' => "\xff"]);
pin('the body arrives as JSON input', 'bike', $sent['body']['data']['input']['pattern'] ?? null);
pin('the query arrives', ['a' => '1'], $sent['body']['data']['query']);
pin('a query string on the path is read, the query array winning', ['limit' => '5', 'q' => 'x'], $client($on, $visitor)->call('GET', 'listings?limit=9&q=x', ['limit' => '5'])['body']['data']['query']);
pin('scalar query values arrive as strings, as over HTTP', ['id' => '12', 'n' => ['1', '2.5'], 'on' => '1', 'off' => '0'], $client($on, $visitor)->call('GET', 'listings', ['id' => 12, 'n' => [1, 2.5], 'on' => true, 'off' => false, 'gone' => null])['body']['data']['query']);
pin('an unknown route is a 404 problem body, not an exception', [404, 'not_found'], (static fn (array $r): array => [$r['status'], $r['body']['code'] ?? null])($client($on, $visitor)->call('GET', 'nope')));

harness_section('hooks');
$fired = [];
$hook  = static function ($request, $route, $credential) use (&$fired): void {
    $fired[] = [$request->isInternal(), $route->path(), $credential->userId()];
};
osc_add_hook('api_request_before', $hook);
$client($on, $user)->call('GET', 'me');
osc_remove_hook('api_request_before', $hook);
pin('api_request_before still fires, with the internal request', [[true, 'me', 7]], $fired);

harness_section('the helpers');
$k = $kernel(false);
ApiAccess::connect(static function (): void {
}, static function (): void {
}, static function (): void {
}, static fn (string $m, string $p, array $q, array $b): array => $client($k, $user)->call($m, $p, $q, $b));
$got = osc_api_get('me');
pin('osc_api_get() answers status, body and headers', [200, 7], [$got['status'], $got['body']['data']['user']]);
check('with headers as an array', is_array($got['headers']));
pin('osc_api_call() refuses a signed-in write', 403, osc_api_call('POST', 'me/alerts', [], ['pattern' => 'bike'])['status']);
check('the client never forgets the web identity', !str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/api/InternalClient.php'), 'forget('));

exit(harness_result());
