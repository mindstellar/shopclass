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
 * The API route table and the kernel dispatch: matching, 404 vs 405, plugin routes and replacement rules.
 * Usage: php tests/api-router.php
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

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\routing\RouteTable;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialStore;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\StoredKey;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$validator = new Validator(['Thing' => ['type' => 'object']]);
$logged    = [];
$log       = static function (string $m) use (&$logged): void {
    $logged[] = $m;
};

$handler = static fn (ApiCall $call): Response => Response::ok(['args' => $call->args()]);
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
check('a [class, method] handler is not loaded when the route is built', !class_exists('NoSuch\\Controller', false));
pin('a missing handler class is refused by check()', 'GET x: no handler.', $bad('GET', 'x', ['handler' => ['NoSuch\\Controller', 'show']]));
pin('a missing handler class fails on its first call', 'LogicException', (static function () use ($missing): string {
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

    public function show(): Response
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

    public function show(): Response
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
    'GET account/alerts'                => 'user alerts:read',
    'GET account/alerts/{id}'           => 'user alerts:read',
    'GET account/keys'                  => 'user account:write',
    'GET account/keys/{id}'             => 'user account:write',
    'GET account/listings'              => 'user account:read',
    'GET account/sessions'              => 'user account:read',
    'GET auth/session'                  => 'user account:read',
    'GET categories'                    => 'public listings:read',
    'GET categories/{category}'         => 'public listings:read',
    'GET cities/{id}/areas'             => 'public listings:read',
    'GET comments/{id}'                 => 'public listings:read',
    'GET countries'                     => 'public listings:read',
    'GET countries/{code}/regions'      => 'public listings:read',
    'GET currencies'                    => 'public listings:read',
    'GET custom-fields'                 => 'public listings:read',
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
    'POST auth/sign-out'                => 'user -',
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
    'DELETE admin/custom-fields/{id}'                    => 'admin admin:taxonomy',
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
    'GET admin/custom-fields/{id}'                       => 'admin admin:taxonomy',
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
    'PATCH admin/custom-fields/{id}'                     => 'admin admin:taxonomy',
    'PATCH admin/listings/{id}'                   => 'admin admin:listings',
    'PATCH admin/regions/{id}'                    => 'admin admin:taxonomy',
    'PATCH admin/settings'                        => 'admin admin:settings',
    'PATCH admin/users/{id}'                      => 'admin admin:users',
    'PATCH admin/webhooks/{webhook}'              => 'admin admin:webhooks',
    'POST admin/areas'                            => 'admin admin:taxonomy',
    'POST admin/categories'                       => 'admin admin:taxonomy',
    'POST admin/cities'                           => 'admin admin:taxonomy',
    'POST admin/currencies'                       => 'admin admin:taxonomy',
    'POST admin/custom-fields'                           => 'admin admin:taxonomy',
    'POST admin/keys'                             => 'admin admin:keys',
    'POST admin/keys/{id}/rotate'                 => 'admin admin:keys',
    'POST admin/listings/{id}/bump'               => 'admin admin:listings',
    'POST admin/regions'                          => 'admin admin:taxonomy',
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
check('a deprecated route cannot replace a core route either', (bool) array_filter($logged, static fn (string $m): bool => str_contains($m, 'GET listings/{id} refused: it would replace a core route')));
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
check('a deprecated path is refused from its sunset day', str_contains($refusedFor('GET gone/{id}'), 'sunset date has passed') && $built->match('GET', 'gone/1') === null);
check('an old path cannot add a method on a core resource path', str_contains($refusedFor('PUT listings/{id}'), 'core route GET listings/{id} answers that path')
    && $built->match('PUT', 'listings/7') === null);
check('nor a pattern that takes a core id too', $refusedFor('POST listings/{slug}') !== '' && $refusedFor('PATCH categories/{x}') !== '');
pin('a pattern that refuses digits keeps clear of the core ids', [['external_id' => 'abc'], null, []], [
    $built->match('PUT', 'listings/abc')?->args(), $built->match('PUT', 'listings/7'), $refusedFor('PUT listings/{external_id}') === '' ? [] : [$refusedFor('PUT listings/{external_id}')],
]);
pin('a path no core route has is kept', 'POST listings:batch', $built->match('POST', 'listings:batch')?->route()->key());
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
pin('a later filter can remove a plugin route', null, $built->match('GET', 'ext/acme/hello'));
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
check('each service is built once and shared', $services->keys() === $services->keys()
    && $services->authenticator() === $services->authenticator()
    && $services->keyService() === $services->keyService());
check('one clock for every service', $services->clock() === $services->clock());
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
check('a controller is built once per request', ($services->handlers())(\mindstellar\api\controller\AuthController::class) === ($services->handlers())(\mindstellar\api\controller\AuthController::class));

harness_section('ApiCall');
$apiCall = new ApiCall(new Request('GET', 'v1/x'), Credential::anonymous(), ['id' => '12', 'photo' => '-3', 'slug' => 'cars']);
pin('intArg: digits as an int, anything else 0', [12, 0, 0, 0], [$apiCall->intArg(), $apiCall->intArg('photo'), $apiCall->intArg('slug'), $apiCall->intArg('missing')]);
pin('arg: the value, or null', ['cars', null], [$apiCall->arg('slug'), $apiCall->arg('missing')]);

harness_section('versions');
pin('a core route serves every live version by default', ['v1'], (new RouteSpec('GET', 'x', $none))->versions());
pin('with a v2 live too, it serves both', ['v1', 'v2'], (new RouteSpec('GET', 'x', $none, live: ['v1', 'v2']))->versions());
$v2Handler = static fn (ApiCall $call): Response => Response::ok(['v2' => true]);
$v2        = new Router($validator, $core + ['v2 GET listings' => ['handler' => $v2Handler, 'auth' => RouteSpec::AUTH_NONE]], $log, versions: ['v1', 'v2']);
pin('an unchanged core route answers in v1 and v2', ['GET listings/{id}', 'GET listings/{id}'], [$v2->match('GET', 'listings/3', 'v1')?->route()->key(), $v2->match('GET', 'listings/3', 'v2')?->route()->key()]);
check('a route that names v2 replaces the shared one in v2 only', $v2->match('GET', 'listings', 'v2')?->route()->handler() === $v2Handler
    && $v2->match('GET', 'listings', 'v1')?->route()->handler() === $handler);
check('a plugin route that names no version stays on v1', $v2->addPlugin('GET', 'ext/pinned/x', $none)
    && $v2->match('GET', 'ext/pinned/x', 'v1') !== null && $v2->match('GET', 'ext/pinned/x', 'v2') === null);
check('a plugin route may opt in to v2', $v2->addPlugin('GET', 'ext/pinned/both', $none + ['versions' => ['v1', 'v2']])
    && $v2->match('GET', 'ext/pinned/both', 'v2') !== null);
pin('a context built without a request, as a webhook payload is, keeps the pinned version', 'v1', (new \mindstellar\api\serializer\ViewContext(Credential::anonymous(), 'en_US'))->version());
pin('osc_api_url() points at the pinned version unless told otherwise', ['https://shop.test/api/v1/ext/a', 'https://shop.test/api/v2'], [osc_api_url('ext/a'), osc_api_url('', 'v2')]);
pin('an unknown version is refused', 'GET x: unknown API version v9.', (static function () use ($none): string {
    try {
        new RouteSpec('GET', 'x', $none + ['versions' => ['v9']]);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return 'accepted';
})());
$versioned = new Router($validator, ['v1 GET only-v1' => $none]);
pin('a table key may name its version', [['v1'], null], [$versioned->match('GET', 'only-v1')?->route()->versions(), $versioned->match('GET', 'only-v1', 'v9')]);

harness_section('plugin route rules');
$photo = new RouteSpec('GET', 'x/{photo}', $none);
$named = new RouteSpec('GET', 'x/{name}', $none + ['where' => ['name' => '[a-z]+']]);
check('{photo} is digits when overlaps are checked, as when matching', !$photo->overlaps($named) && !$named->overlaps($photo));
$extValidator = new Validator(['Thing' => ['type' => 'object'], 'Problem' => ['type' => 'object'], 'ExtAcmeThing' => ['type' => 'object']]);
$logged       = [];
$rules        = new Router($extValidator, $core, $log);
check('a core-only key is refused on a plugin route', !$rules->addPlugin('POST', 'ext/acme/up', ['handler' => $handler, 'upload' => true])
    && str_contains($logged[0] ?? '', 'only core routes may set upload'));
$logged = [];
check('a $ref to a core component is refused', !$rules->addPlugin('GET', 'ext/acme/thing', $none + ['responses' => [200 => ['$ref' => '#/components/schemas/Thing']]])
    && str_contains($logged[0] ?? '', 'core component Thing'));
check('its own Ext component and Problem are allowed', $rules->addPlugin('GET', 'ext/acme/own', $none + ['responses' => [
    200 => ['$ref' => '#/components/schemas/ExtAcmeThing'], 404 => ['$ref' => '#/components/schemas/Problem'],
]]));
$logged = [];
check('a slug belongs to the first plugin that uses it', $rules->addPlugin('GET', 'ext/acme/a', $none + ['plugin' => 'acme'])
    && !$rules->addPlugin('GET', 'ext/acme/b', $none + ['plugin' => 'evil'])
    && str_contains($logged[0] ?? '', 'belongs to plugin acme'));
$logged = [];
$rules->addPlugin('GET', 'ext/acme/{any}', $none + ['plugin' => 'acme']);
$rules->addPlugin('GET', 'ext/acme/fixed', $none + ['plugin' => 'acme']);
check('an overlap is logged and the first route answers', str_contains(implode("\n", $logged), 'GET ext/acme/fixed (plugin acme) overlaps GET ext/acme/{any}')
    && $rules->match('GET', 'ext/acme/fixed')?->route()->key() === 'GET ext/acme/{any}');
$logged = [];
check('the same method and path twice is refused', !$rules->addPlugin('GET', 'ext/acme/a', $none + ['plugin' => 'acme'])
    && str_contains($logged[0] ?? '', 'same method and path'));
$logged = [];
check('a plugin write with user or admin auth and no scope is refused', !$rules->addPlugin('POST', 'ext/acme/w1', ['handler' => $handler])
    && !$rules->addPlugin('PATCH', 'ext/acme/w2', ['handler' => $handler, 'auth' => RouteSpec::AUTH_USER])
    && count($logged) === 2 && str_contains($logged[0], 'must name a scope'));
check('with a scope, or with auth none, it is kept', $rules->addPlugin('POST', 'ext/acme/w3', ['handler' => $handler, 'scope' => 'ext:acme:write'])
    && $rules->addPlugin('POST', 'ext/acme/w4', ['handler' => $handler, 'auth' => RouteSpec::AUTH_NONE])
    && $rules->addPlugin('GET', 'ext/acme/r1', ['handler' => $handler, 'auth' => RouteSpec::AUTH_USER]));
osc_api_register_route('GET', 'ext/dup/x', $none + ['summary' => 'first']);
osc_api_register_route('GET', 'ext/dup/x', $none + ['summary' => 'second']);
pin('osc_api_register_route() keeps the first of two registrations', 'first', Router::build($validator, $core, $log)->match('GET', 'ext/dup/x')?->route()->summary());

harness_section('prepare and kit');
$prepCore = ['POST prep' => [
    'handler' => static fn (ApiCall $c): Response => Response::ok(['prepared' => $c->prepared()]),
    'auth'    => RouteSpec::AUTH_NONE,
    'prepare' => static fn (ApiCall $c): string => 'staged for ' . $c->request()->method(),
]];
$prepKernel = api_test_kernel(new Router($validator, $prepCore), api_test_authenticator(new ApiKeys($store, new Scopes(), new SystemClock())), new ApiSettings(true), validator: $validator);
pin('a prepare step runs first and the handler reads its result', 'staged for POST', $prepKernel->handle(new Request('POST', 'v1/prep', [], [], '127.0.0.1'))->body()['data']['prepared'] ?? null);
pin('a call built without services has no kit', 'LogicException', (static function (): string {
    try {
        (new ApiCall(new Request('GET', 'v1/x'), Credential::anonymous()))->kit();
    } catch (\LogicException $e) {
        return 'LogicException';
    }

    return 'kit';
})());
$kitRouter = new Router($validator, ['GET k' => ['handler' => static fn (ApiCall $c): Response => Response::ok(['kit' => get_class($c->kit())]), 'auth' => RouteSpec::AUTH_NONE]], kit: static fn (): \mindstellar\api\ApiKit => new \mindstellar\api\ApiKit($services));
pin('a route built with a kit hands it to the call', \mindstellar\api\ApiKit::class, $kitRouter->match('GET', 'k')->route()->call(new Request('GET', 'v1/k'), Credential::anonymous(), [])->body()['data']['kit']);

harness_section('cache headers');
$policy = new \mindstellar\api\http\CachePolicy(60);
$keyed  = new Credential(\mindstellar\apiaccess\CredentialKind::KEY, ['listings:read'], 4);
$cc     = static fn (string $path): string => $policy->header(new Request('GET', 'v1/' . $path), $keyed);
pin('account, admin and session reads are never stored', ['private, no-store', 'private, no-store', 'private, no-store', 'private, no-store'], [
    $cc('account'), $cc('account/keys/3'), $cc('admin/listings'), $cc('admin/users/4/sessions'),
]);
pin('other keyed reads are revalidated', ['private, no-cache', 'private, no-cache'], [$cc('listings/4'), $cc('accounts-like')]);

harness_section('plugin schemas');
$schemaLog = [];
$checked   = \mindstellar\api\schema\ExtensionSchemas::check([
    'ExtAcmeRating' => ['type' => 'object', 'properties' => ['by' => ['$ref' => '#/components/schemas/ExtAcmeUser'], 'err' => ['$ref' => '#/components/schemas/Problem']]],
    'ExtAcmeUser'   => ['type' => 'object'],
    'ExtAcmeLeak'   => ['$ref' => '#/components/schemas/Listing'],
    'Listing'       => ['type' => 'object'],
], static function (string $m) use (&$schemaLog): void {
    $schemaLog[] = $m;
});
pin('Ext components that refer to each other and to Problem are kept', ['ExtAcmeRating', 'ExtAcmeUser'], array_keys($checked));
check('a core name, or a $ref to a core component, is refused', count($schemaLog) === 2 && str_contains(implode("\n", $schemaLog), 'core component Listing'));
pin('osc_api_register_schema() names the component and returns its $ref', ['$ref' => '#/components/schemas/ExtAcmeRatingsRating'], osc_api_register_schema('acme-ratings', 'Rating', ['type' => 'object']));

harness_section('problems');
pin('a plugin code from api_problem_codes', [409, 'ext_acme_taken', 'Already taken.'], api_with_filter(
    'api_problem_codes',
    static fn (array $codes): array => $codes + ['ext_acme_taken' => [409, 'Already taken.']],
    static fn (): array => [\mindstellar\api\Problem::make('ext_acme_taken')->status(), \mindstellar\api\Problem::make('ext_acme_taken')->body()['code'], \mindstellar\api\Problem::make('ext_acme_taken')->body()['title']]
));
pin('an unknown code is a 500', [500, 'server_error'], [\mindstellar\api\Problem::make('ext_acme_nope')->status(), \mindstellar\api\Problem::make('ext_acme_nope')->body()['code']]);
pin('a field error is about the body unless it says otherwise', ['body', 'query'], [
    \mindstellar\api\ProblemException::field('/a', 'x', 'bad')->response()->body()['errors'][0]['in'],
    \mindstellar\api\ProblemException::field('/a', 'x', 'bad', 'query')->response()->body()['errors'][0]['in'],
]);
pin('refused and rejected errors are about the body', ['body', 'body'], [
    \mindstellar\api\Problem::refused([['pointer' => '/a', 'code' => 'x', 'message' => 'bad']])->body()['errors'][0]['in'],
    \mindstellar\api\Problem::rejected('No.')->body()['errors'][0]['in'],
]);
pin('a core refusal reason maps to its API code', ['feature_disabled', 'wrong_credential', 'forbidden'], [
    \mindstellar\api\Problem::fromRefusal(new \mindstellar\validation\ForbiddenException('Off.', \mindstellar\validation\ForbiddenException::DISABLED))->body()['code'],
    \mindstellar\api\Problem::fromRefusal(new \mindstellar\validation\ForbiddenException('Sign in.', \mindstellar\validation\ForbiddenException::SIGN_IN))->body()['code'],
    \mindstellar\api\Problem::fromRefusal(new \mindstellar\validation\ForbiddenException('No.', 'something_else'))->body()['code'],
]);

exit(harness_result());
