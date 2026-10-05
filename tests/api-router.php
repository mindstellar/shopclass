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
 * The API route table and the kernel's dispatch: matching, {id} and {slug} without a second
 * decode, 404 vs 405 with Allow, unknown versions and refused paths, schemas checked when a
 * route is built, the plugin `ext/<slug>/` rule, core routes that cannot be replaced, the
 * `api_routes` filter and osc_api_register_route().
 *
 * DB-free.  Usage: php tests/api-router.php
 */

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

if (!function_exists('osc_base_url')) {
    function osc_base_url()
    {
        return 'https://shop.test/';
    }
}
if (!function_exists('osc_rewrite_enabled')) {
    function osc_rewrite_enabled()
    {
        return $GLOBALS['rewrite'] ?? true;
    }
}

use mindstellar\api\ApiServices;
use mindstellar\api\ApiSettings;
use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialStore;
use mindstellar\api\auth\Scopes;
use mindstellar\api\auth\StoredKey;
use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\routing\RouteTable;
use mindstellar\api\schema\Validator;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$validator = new Validator(['Thing' => ['type' => 'object']]);
$logged    = [];
$log       = static function (string $m) use (&$logged): void {
    $logged[] = $m;
};

$handler = static fn (Request $r, Credential $c, array $args): Response => Response::ok(['args' => $args]);
$none    = ['handler' => $handler, 'auth' => RouteSpec::AUTH_NONE];
$core    = [
    'GET '                             => $none,
    'GET listings'                     => $none,
    'GET listings/{id}'                => $none,
    'DELETE listings/{id}'             => $none,
    'GET categories/{slug}'            => $none,
    'GET listings/{id}/photos/{photo}' => $none,
    'GET openapi.json'                 => $none,
];

harness_section('RouteSpec');
$spec = new RouteSpec('get', '/listings/{id}/', ['handler' => $handler, 'scope' => 'listings:read']);
pin('method upper, path trimmed', 'GET listings/{id}', $spec->key());
pin('auth defaults to public', RouteSpec::AUTH_PUBLIC, $spec->auth());
pin('{id} matches digits', ['id' => '12'], $spec->match('listings/12'));
pin('{id} refuses letters', null, $spec->match('listings/abc'));
$slug = new RouteSpec('GET', 'c/{slug}', ['handler' => $handler]);
pin('{slug} takes the already decoded segment as it is', ['slug' => 'a%20b'], $slug->match('c/a%20b'));
pin('{slug} does not cross a slash', null, $slug->match('c/a/b'));
pin('a dotted static segment matches', [], (new RouteSpec('GET', 'openapi.json', ['handler' => $handler]))->match('openapi.json'));
$bad = static function (string $method, string $path, array $spec) use ($validator): string {
    try {
        (new RouteSpec($method, $path, $spec))->check($validator);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return 'accepted';
};
pin('an unknown method is refused', 'TRACE x: unsupported method.', $bad('TRACE', 'x', ['handler' => $handler]));
pin('an unknown auth level is refused', 'GET x: unknown auth level root.', $bad('GET', 'x', ['handler' => $handler, 'auth' => 'root']));
pin('a route without a handler is refused', 'GET x: no handler.', $bad('GET', 'x', []));
pin('a path with a space is refused', 'GET x y: invalid path.', $bad('GET', 'x y', ['handler' => $handler]));
pin('a write cannot be public', 'POST x: a write cannot be public; use user, admin, or none for a throttled sign-in.', $bad('POST', 'x', ['handler' => $handler, 'auth' => RouteSpec::AUTH_PUBLIC]));
pin('a write with no auth given is admin only', [RouteSpec::AUTH_ADMIN, RouteSpec::AUTH_ADMIN], [
    (new RouteSpec('POST', 'x', ['handler' => $handler]))->auth(), (new RouteSpec('DELETE', 'x', ['handler' => $handler]))->auth(),
]);
pin('a write may need no credential, for a throttled sign-in', RouteSpec::AUTH_NONE, (new RouteSpec('POST', 'x', ['handler' => $handler, 'auth' => RouteSpec::AUTH_NONE]))->auth());
pin('a dot-dot path is refused', 'GET x/../y: invalid path.', $bad('GET', 'x/../y', ['handler' => $handler]));
pin('a schema keyword outside the subset is refused when checked', 'GET x: body schema: unsupported keyword oneOf.', $bad('GET', 'x', ['handler' => $handler, 'body' => ['oneOf' => []]]));
pin('a $ref that does not resolve is refused when checked', 'GET x: query schema: unknown reference #/components/schemas/Nope.', $bad('GET', 'x', ['handler' => $handler, 'query' => ['$ref' => '#/components/schemas/Nope']]));
pin('a $ref that resolves is accepted', 'accepted', $bad('GET', 'x', ['handler' => $handler, 'body' => ['$ref' => '#/components/schemas/Thing']]));
$missing = new RouteSpec('GET', 'x', ['handler' => ['NoSuch\\Controller', 'show']]);
pin('a [class, method] handler is not loaded when the route is built', false, class_exists('NoSuch\\Controller', false));
pin('...a missing one is refused by check()', 'GET x: no handler.', $bad('GET', 'x', ['handler' => ['NoSuch\\Controller', 'show']]));
pin('...and fails on its first call', 'LogicException', (static function () use ($missing): string {
    try {
        $missing->call(new Request('GET', 'v1/x'), Credential::anonymous(), []);
    } catch (\LogicException $e) {
        return 'LogicException';
    }

    return 'answered';
})());

final class InstanceController
{
    private int $calls = 0;

    public function show(Request $r, Credential $c, array $a): Response
    {
        return Response::ok(['calls' => ++$this->calls]);
    }
}
$route = new RouteSpec('GET', 'x', ['handler' => [InstanceController::class, 'show']]);
$route->call(new Request('GET', 'v1/x'), Credential::anonymous(), []);
pin('a [class, method] handler gets one instance, made on the first call', 2, $route->call(new Request('GET', 'v1/x'), Credential::anonymous(), [])->body()['data']['calls']);

final class KitController
{
    public function __construct(private string $kit)
    {
    }

    public function show(Request $r, Credential $c, array $a): Response
    {
        return Response::ok(['kit' => $this->kit]);
    }
}
$built   = [];
$factory = static function (string $class) use (&$built): object {
    $built[] = $class;

    return new $class('site kit');
};
$route = new RouteSpec('GET', 'x', ['handler' => [KitController::class, 'show']], $factory);
pin('a handler factory builds the instance', 'site kit', $route->call(new Request('GET', 'v1/x'), Credential::anonymous(), [])->body()['data']['kit']);
$withKit = new Router($validator, ['GET kit' => ['handler' => [KitController::class, 'show'], 'auth' => RouteSpec::AUTH_NONE]], $log, $factory);
$withKit->addPlugin('GET', 'ext/acme/thing', ['handler' => [InstanceController::class, 'show'], 'auth' => RouteSpec::AUTH_NONE]);
$built = [];
$withKit->match('GET', 'kit')->route()->call(new Request('GET', 'v1/kit'), Credential::anonymous(), []);
$plugin = $withKit->match('GET', 'ext/acme/thing')->route()->call(new Request('GET', 'v1/ext/acme/thing'), Credential::anonymous(), []);
pin('the router hands core handlers to the factory, and builds plugin classes with no arguments', [[KitController::class], 1], [$built, $plugin->body()['data']['calls']]);
pin('RouteSpec::read() is a public read with its 200 and problem answers', [
    'auth' => RouteSpec::AUTH_PUBLIC, 'scope' => Scopes::PUBLIC_READ, 'tags' => ['Things'],
    'responses' => [200 => ['$ref' => '#/components/schemas/Thing'], 404 => ['$ref' => '#/components/schemas/Problem']],
], array_intersect_key(
    RouteSpec::read(handler: [KitController::class, 'show'], tag: 'Things', summary: 'A thing', response: 'Thing', errors: [404]),
    ['auth' => 1, 'scope' => 1, 'tags' => 1, 'responses' => 1]
));

harness_section('Router');
$router = new Router($validator, $core);
pin('the version root matches', [], $router->match('GET', '')->args());
pin('a static path matches', 'GET listings', $router->match('GET', 'listings')->route()->key());
pin('an id route gives its args', ['id' => '7', 'photo' => '3'], $router->match('GET', 'listings/7/photos/3')->args());
pin('HEAD is answered by the GET route', 'GET listings/{id}', $router->match('HEAD', 'listings/7')->route()->key());
pin('a method the path lacks does not match', null, $router->match('POST', 'listings/7'));
pin('methodsFor lists GET, DELETE and HEAD', ['GET', 'DELETE', 'HEAD'], $router->methodsFor('listings/7'));
pin('an unknown path has no methods', [], $router->methodsFor('nothing'));
$coreTable = new Router(new Validator(\mindstellar\api\schema\Schema::components()), RouteTable::core());
$routeTable = [];
$adminTable = [];
foreach ($coreTable->all() as $key => $route) {
    $line = $route->auth() . ' ' . ($route->scope() ?? '-');
    if (str_contains($key, ' admin/')) {
        $adminTable[$key] = $line;
    } else {
        $routeTable[$key] = $line;
    }
}
ksort($routeTable);
ksort($adminTable);
pin('the core table needs no database; every route names its auth and scope', [
    'DELETE account/alerts/{id}'        => 'user alerts:write',
    'DELETE account/keys/{id}'          => 'user account:write',
    'DELETE account/sessions/{session}' => 'user account:write',
    'DELETE comments/{id}'              => 'user comments:write',
    'DELETE listings/{id}'              => 'user listings:delete',
    'DELETE listings/{id}/photos/{photo}' => 'user listings:write',
    'GET '                              => 'public listings:read',
    'GET account'                       => 'user account:read',
    'GET account/alerts'                => 'user alerts:write',
    'GET account/alerts/{id}'           => 'user alerts:write',
    'GET account/keys'                  => 'user account:write',
    'GET account/keys/{id}'             => 'user account:write',
    'GET account/sessions'              => 'user account:read',
    'GET auth/session'                  => 'user account:read',
    'GET categories'                    => 'public listings:read',
    'GET categories/{category}'         => 'public listings:read',
    'GET cities/{id}/areas'             => 'public listings:read',
    'GET comments/{id}'                 => 'public listings:read',
    'GET countries'                     => 'public listings:read',
    'GET countries/{code}/regions'      => 'public listings:read',
    'GET currencies'                    => 'public listings:read',
    'GET fields'                        => 'public listings:read',
    'GET listings'                      => 'public listings:read',
    'GET listings/{id}'                 => 'public listings:read',
    'GET listings/{id}/comments'        => 'public listings:read',
    'GET listings/{id}/photos'          => 'public listings:read',
    'GET listings/{id}/photos/{photo}'  => 'public listings:read',
    'GET openapi.json'                  => 'none -',
    'GET regions/{id}/cities'           => 'public listings:read',
    'GET users/{id}'                    => 'public listings:read',
    'GET users/{id}/listings'           => 'public listings:read',
    'PATCH account'                     => 'user account:write',
    'PATCH listings/{id}'               => 'user listings:write',
    'POST account/alerts'               => 'user alerts:write',
    'POST account/keys'                 => 'user account:write',
    'POST account/password'             => 'user account:write',
    'POST account/sign-out-everywhere'  => 'user account:write',
    'POST auth/revoke'                  => 'user -',
    'POST auth/token'                   => 'none -',
    'POST listings'                     => 'user listings:write',
    'POST listings/{id}/comments'       => 'user comments:write',
    'POST listings/{id}/photos'         => 'user listings:write',
    'POST photos'                       => 'user listings:write',
    'POST users'                        => 'none -',
], $routeTable);
pin('every admin route needs an admin key and names an admin scope', [
    'DELETE admin/areas/{id}'                     => 'admin admin:taxonomy',
    'DELETE admin/categories/{id}'                => 'admin admin:taxonomy',
    'DELETE admin/cities/{id}'                    => 'admin admin:taxonomy',
    'DELETE admin/comments/{id}'                  => 'admin admin:comments',
    'DELETE admin/currencies/{code}'              => 'admin admin:taxonomy',
    'DELETE admin/fields/{id}'                    => 'admin admin:taxonomy',
    'DELETE admin/keys/{id}'                      => 'admin admin:keys',
    'DELETE admin/listings/{id}'                  => 'admin admin:listings',
    'DELETE admin/regions/{id}'                   => 'admin admin:taxonomy',
    'DELETE admin/users/{id}'                     => 'admin admin:users',
    'DELETE admin/users/{id}/sessions/{session}'  => 'admin admin:users',
    'DELETE admin/webhooks/{webhook}'             => 'admin admin:webhooks',
    'GET admin/areas/{id}'                        => 'admin admin:taxonomy',
    'GET admin/categories'                        => 'admin admin:taxonomy',
    'GET admin/categories/{id}'                   => 'admin admin:taxonomy',
    'GET admin/cities/{id}'                       => 'admin admin:taxonomy',
    'GET admin/comments'                          => 'admin admin:comments',
    'GET admin/comments/{id}'                     => 'admin admin:comments',
    'GET admin/currencies/{code}'                 => 'admin admin:taxonomy',
    'GET admin/fields/{id}'                       => 'admin admin:taxonomy',
    'GET admin/jobs'                              => 'admin admin:settings',
    'GET admin/keys'                              => 'admin admin:keys',
    'GET admin/keys/{id}'                         => 'admin admin:keys',
    'GET admin/listings'                          => 'admin admin:listings',
    'GET admin/listings/{id}'                     => 'admin admin:listings',
    'GET admin/regions/{id}'                      => 'admin admin:taxonomy',
    'GET admin/settings'                          => 'admin admin:settings',
    'GET admin/users'                             => 'admin admin:users',
    'GET admin/users/{id}'                        => 'admin admin:users',
    'GET admin/users/{id}/sessions'               => 'admin admin:users',
    'GET admin/webhook-events'                    => 'admin admin:webhooks',
    'GET admin/webhooks'                          => 'admin admin:webhooks',
    'GET admin/webhooks/{webhook}'                => 'admin admin:webhooks',
    'GET admin/webhooks/{webhook}/deliveries'     => 'admin admin:webhooks',
    'PATCH admin/areas/{id}'                      => 'admin admin:taxonomy',
    'PATCH admin/categories/{id}'                 => 'admin admin:taxonomy',
    'PATCH admin/cities/{id}'                     => 'admin admin:taxonomy',
    'PATCH admin/comments/{id}'                   => 'admin admin:comments',
    'PATCH admin/currencies/{code}'               => 'admin admin:taxonomy',
    'PATCH admin/fields/{id}'                     => 'admin admin:taxonomy',
    'PATCH admin/listings/{id}'                   => 'admin admin:listings',
    'PATCH admin/regions/{id}'                    => 'admin admin:taxonomy',
    'PATCH admin/settings'                        => 'admin admin:settings',
    'PATCH admin/users/{id}'                      => 'admin admin:users',
    'PATCH admin/webhooks/{webhook}'              => 'admin admin:webhooks',
    'POST admin/areas'                            => 'admin admin:taxonomy',
    'POST admin/categories'                       => 'admin admin:taxonomy',
    'POST admin/cities'                           => 'admin admin:taxonomy',
    'POST admin/comments/{id}/activate'           => 'admin admin:comments',
    'POST admin/comments/{id}/deactivate'         => 'admin admin:comments',
    'POST admin/comments/{id}/disable'            => 'admin admin:comments',
    'POST admin/comments/{id}/enable'             => 'admin admin:comments',
    'POST admin/currencies'                       => 'admin admin:taxonomy',
    'POST admin/fields'                           => 'admin admin:taxonomy',
    'POST admin/keys'                             => 'admin admin:keys',
    'POST admin/keys/{id}/rotate'                 => 'admin admin:keys',
    'POST admin/listings/{id}/activate'           => 'admin admin:listings',
    'POST admin/listings/{id}/bump'               => 'admin admin:listings',
    'POST admin/listings/{id}/deactivate'         => 'admin admin:listings',
    'POST admin/listings/{id}/disable'            => 'admin admin:listings',
    'POST admin/listings/{id}/enable'             => 'admin admin:listings',
    'POST admin/listings/{id}/premium'            => 'admin admin:listings',
    'POST admin/listings/{id}/spam'               => 'admin admin:listings',
    'POST admin/listings/{id}/unpremium'          => 'admin admin:listings',
    'POST admin/listings/{id}/unspam'             => 'admin admin:listings',
    'POST admin/regions'                          => 'admin admin:taxonomy',
    'POST admin/users/{id}/activate'              => 'admin admin:users',
    'POST admin/users/{id}/deactivate'            => 'admin admin:users',
    'POST admin/users/{id}/disable'               => 'admin admin:users',
    'POST admin/users/{id}/enable'                => 'admin admin:users',
    'POST admin/users/{id}/sign-out-everywhere'   => 'admin admin:users',
    'POST admin/webhooks'                         => 'admin admin:webhooks',
    'POST admin/webhooks/{webhook}/rotate-secret' => 'admin admin:webhooks',
    'POST admin/webhooks/{webhook}/test'          => 'admin admin:webhooks',
], $adminTable);
$secret = array_keys(array_filter($coreTable->all(), static fn (RouteSpec $r): bool => !$r->replayable()));
sort($secret);
pin('answers holding a secret are never stored for an Idempotency-Key', ['POST account/keys', 'POST account/password', 'POST admin/keys', 'POST admin/keys/{id}/rotate', 'POST admin/webhooks', 'POST admin/webhooks/{webhook}/rotate-secret', 'POST auth/token'], $secret);
$threwPublic = false;
try {
    new RouteSpec('POST', 'x', RouteSpec::write([RouteSpec::class, 'key'], 'X', 'x', RouteSpec::AUTH_PUBLIC, null));
} catch (\InvalidArgumentException $e) {
    $threwPublic = true;
}
check('a public write is refused', $threwPublic);
pin('the OpenAPI document needs no credential', RouteSpec::AUTH_NONE, $coreTable->match('GET', 'openapi.json')->route()->auth());

harness_section('plugin routes');
$old    = $none + ['deprecated' => '2026-10-04', 'sunset' => '2027-04-01'];
$logged = [];
$built  = api_with_filter('api_routes', static fn (array $routes): array => $routes + [
    'GET ext/acme/offers/{id}' => $none,
    'GET offers'               => $none,
    'GET ext/Acme/x'           => $none,
    'GET ext/acme/broken'      => ['handler' => 'no_such_function'],
    'GET listings'             => $none,
    'GET runs/{id}'            => $old,
    'GET listings/{id}'        => $old,
], static fn () => Router::build($validator, $core, $log, null, '2026-10-04'));
pin('an ext/<slug>/ route is added', ['id' => '5'], $built->match('GET', 'ext/acme/offers/5')->args());
pin('a path outside ext/ is dropped', null, $built->match('GET', 'offers'));
pin('a slug in capitals is dropped', null, $built->match('GET', 'ext/Acme/x'));
pin('a deprecated route with a sunset may keep an old path outside ext/', ['id' => '7'], $built->match('GET', 'runs/7')?->args());
check('...but cannot replace a core route either', (bool) array_filter($logged, static fn (string $m): bool => str_contains($m, 'GET listings/{id} refused: it would replace a core route')));
check('each refusal is logged once, naming the rule', count($logged) === 5 && str_contains($logged[0], 'ext/<plugin-slug>/'));
check('a core route cannot be replaced', $built->isCore('GET listings')
    && (bool) array_filter($logged, static fn (string $m): bool => str_contains($m, 'GET listings refused: plugin paths')));
$logged = [];
$built  = api_with_filter('api_routes', static fn (array $routes): array => $routes + [
    'GET archive/{id}'                => $none + ['deprecated' => '2026-10-04'],
    'GET gone/{id}'                   => $none + ['deprecated' => '2026-01-01', 'sunset' => '2026-10-04'],
    'PUT listings/{id}'               => $old,
    'POST listings/{slug}'            => $old,
    'PATCH categories/{x}'            => $old,
    'PUT listings/{external_id}'      => $old + ['where' => ['external_id' => '(?![0-9]+(?:/|$))[^/]+']],
    'POST listings:batch'             => $old,
], static fn () => Router::build($validator, $core, $log, null, '2026-10-04'));
$refusedFor = static fn (string $key): string => (string) (array_values(array_filter($logged, static fn (string $m): bool => str_contains($m, $key . ' refused')))[0] ?? '');
check('a deprecated old path needs a sunset date', str_contains($refusedFor('GET archive/{id}'), 'deprecated with a sunset date'));
check('and is refused from its sunset day', str_contains($refusedFor('GET gone/{id}'), 'sunset date has passed') && $built->match('GET', 'gone/1') === null);
check('an old path cannot add a method on a core resource path', str_contains($refusedFor('PUT listings/{id}'), 'core route GET listings/{id} answers that path')
    && $built->match('PUT', 'listings/7') === null);
check('nor a pattern that takes a core id too', $refusedFor('POST listings/{slug}') !== '' && $refusedFor('PATCH categories/{x}') !== '');
pin('a pattern that refuses digits keeps clear of the core ids', [['external_id' => 'abc'], null, []], [
    $built->match('PUT', 'listings/abc')?->args(), $built->match('PUT', 'listings/7'), $refusedFor('PUT listings/{external_id}') === '' ? [] : [$refusedFor('PUT listings/{external_id}')],
]);
pin('and a path no core route has is kept', 'POST listings:batch', $built->match('POST', 'listings:batch')?->route()->key());
pin('those five refusals and nothing else', 5, count($logged));
$router2 = new Router($validator, ['GET ext/core/thing' => $none], $log);
$logged  = [];
check('a plugin cannot replace even an ext/ core route', !$router2->addPlugin('GET', 'ext/core/thing', $none) && str_contains($logged[0], 'replace a core route'));

osc_api_register_route('GET', '/ext/acme/hello/', $none);
$logged = [];
$built  = Router::build($validator, $core, $log);
pin('osc_api_register_route() adds through the api_routes filter', 'GET ext/acme/hello', $built->match('GET', 'ext/acme/hello')->route()->key());
$built = api_with_filter('api_routes', static function (array $routes): array {
    unset($routes['GET ext/acme/hello']);

    return $routes;
}, static fn () => Router::build($validator, $core, $log));
pin('and a later filter can remove it', null, $built->match('GET', 'ext/acme/hello'));
check('Router keeps no static registry', (new ReflectionClass(Router::class))->getStaticProperties() === []);

harness_section('Kernel dispatch');
$store = new class () implements CredentialStore {
    public function findByTokenId(string $tokenId): ?StoredKey
    {
    return null;
    }
    public function find(int $id): ?StoredKey
    {
    return null;
    }
    public function insert(StoredKey $key): int
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
$kernel = static function (ApiSettings $settings) use ($validator, $core, $store): Kernel {
    return api_test_kernel(new Router($validator, $core), api_test_authenticator(new ApiKeys($store, new Scopes(), new SystemClock())), $settings, validator: $validator);
};
$on   = $kernel(new ApiSettings(true));
$call = static fn (string $method, ?string $path, ?Kernel $k = null): Response => ($k ?? $on)->handle(new Request($method, $path, [], [], '127.0.0.1'));

$r = $call('GET', 'v1/nothing');
pin('an unknown endpoint is 404 problem+json', [404, 'not_found', Response::PROBLEM_TYPE], [$r->status(), $r->body()['code'], $r->prepare('GET')['headers']['Content-Type']]);
pin('the problem names the request as instance', 'urn:request:' . $r->header('Request-Id'), $r->body()['instance']);
pin('an unknown version is 404', [404, 'urn:request:'], [$call('GET', 'v9/listings')->status(), substr($call('GET', 'v9/listings')->body()['instance'], 0, 12)]);
pin('no version at all is 404', 404, $call('GET', '')->status());
pin('a refused path is 404', [404, 'urn:request:'], [$call('GET', null)->status(), substr($call('GET', null)->body()['instance'], 0, 12)]);
$r = $call('POST', 'v1/listings/4');
pin('a known path with another method is 405 with Allow', [405, 'GET, DELETE, HEAD'], [$r->status(), $r->header('Allow')]);
$r = $call('GET', 'v1/listings/4');
pin('a match runs the handler with its args', [200, ['args' => ['id' => '4']]], [$r->status(), $r->body()['data']]);
pin('the version root runs', 200, $call('GET', 'v1')->status());
$off = $kernel(new ApiSettings(false));
pin('a switched-off API answers 403 api_disabled', [403, 'api_disabled'], [$call('GET', 'v1/listings', $off)->status(), $call('GET', 'v1/listings', $off)->body()['code']]);

harness_section('core refusals');
$refusing = static fn (\Throwable $e): array => ['handler' => static function () use ($e): Response {
    throw $e;
}, 'auth' => RouteSpec::AUTH_NONE];
$refusals = api_test_kernel(new Router($validator, [
    'GET r/missing'   => $refusing(new \mindstellar\validation\NotFoundException('No such thing.')),
    'GET r/conflict'  => $refusing(new \mindstellar\validation\ConflictException('Already done.')),
    'GET r/forbidden' => $refusing(new \mindstellar\validation\ForbiddenException('Not on this site.')),
    'GET r/blocked'   => $refusing(new \mindstellar\validation\BlockedException('Wait.', 30)),
    'GET r/invalid'   => $refusing(\mindstellar\validation\InvalidException::all([
        ['pointer' => '/a', 'code' => 'x', 'message' => 'is bad'],
        ['pointer' => '/b', 'code' => 'y', 'message' => 'is worse'],
    ])),
    'GET r/refused'   => $refusing(new \mindstellar\validation\RefusedException('Not now.')),
]), api_test_authenticator(new ApiKeys($store, new Scopes(), new SystemClock())), new ApiSettings(true), validator: $validator);
$refused = static fn (string $path): Response => $refusals->handle(new Request('GET', 'v1/r/' . $path, [], [], '127.0.0.1'));
pin('NotFound is 404, Conflict 409, Forbidden 403', [[404, 'not_found'], [409, 'conflict'], [403, 'forbidden']], [
    [$refused('missing')->status(), $refused('missing')->body()['code']],
    [$refused('conflict')->status(), $refused('conflict')->body()['code']],
    [$refused('forbidden')->status(), $refused('forbidden')->body()['code']],
]);
pin('Blocked is 429 login_blocked with Retry-After', [429, 'login_blocked', '30'], [$refused('blocked')->status(), $refused('blocked')->body()['code'], $refused('blocked')->header('Retry-After')]);
pin('Invalid is 422 with every field', [422, ['/a', '/b']], [$refused('invalid')->status(), array_column($refused('invalid')->body()['errors'], 'pointer')]);
pin('any other refusal is 422 with its message', [422, 'Not now.'], [$refused('refused')->status(), $refused('refused')->body()['detail']]);

harness_section('composition root');
$services = new ApiServices(
    new ApiSettings(true),
    new Scopes(),
    new ApiCredential(),
    api_test_users(),
    new SystemClock(),
    api_test_limiter()
);
pin('each service is built once and shared', [true, true, true], [
    $services->keys() === $services->keys(),
    $services->authenticator() === $services->authenticator(),
    $services->keyService() === $services->keyService(),
]);
pin('one clock for every service', true, $services->clock() === $services->clock());
pin('a class that is not a core controller is refused', 'LogicException', (static function () use ($services): string {
    try {
        ($services->handlers())(InstanceController::class);
    } catch (\LogicException $e) {
        return 'LogicException';
    }

    return 'built';
})());
$table   = RouteTable::core();
$classes = [];
foreach ($table as $spec) {
    if (is_array($spec['handler']) && is_string($spec['handler'][0])) {
        $classes[$spec['handler'][0]] = true;
    }
}
$unbuilt = [];
foreach (array_keys($classes) as $class) {
    try {
        ($services->handlers())($class);
    } catch (\LogicException $e) {
        $unbuilt[] = $class;
    } catch (\Throwable $e) {
        // Built far enough to need the database: it is a core controller.
    }
}
pin('every core route\'s controller is built from the services', [], $unbuilt);
pin('a controller is built once per request', true, ($services->handlers())(\mindstellar\api\controller\AuthController::class) === ($services->handlers())(\mindstellar\api\controller\AuthController::class));

exit(harness_result());
