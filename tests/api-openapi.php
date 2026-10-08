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
 * The OpenAPI 3.1 document: every route documented, structurally valid, plugin routes and Sunset headers.
 * Usage: php tests/api-openapi.php
 */

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

define('OSCLASS_VERSION', '7.0.0-test');
function osc_base_url()
{
    return 'https://shop.test/';
}
function osc_rewrite_enabled()
{
    return true;
}
function osc_active_plugins()
{
    return serialize([]);
}

use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Definitions;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\ExtensionMembers;
use mindstellar\api\Warning;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\CredentialStore;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\StoredKey;
use mindstellar\utility\SystemClock;

/**
 * Structural problems in an OpenAPI 3.1 document, as short sentences. Empty when sound.
 *
 * @param array<string,mixed> $doc
 *
 * @return string[]
 */
function openapi_problems(array $doc): array
{
    $problems = [];
    if (($doc['openapi'] ?? null) !== '3.1.0') {
        $problems[] = 'openapi is not 3.1.0';
    }
    foreach (['title', 'version'] as $member) {
        if (!is_string($doc['info'][$member] ?? null) || $doc['info'][$member] === '') {
            $problems[] = 'info.' . $member . ' is missing';
        }
    }
    if (!is_array($doc['paths'] ?? null) || $doc['paths'] === []) {
        $problems[] = 'no paths';
    }
    $schemas   = (array) ($doc['components']['schemas'] ?? []);
    $schemes   = (array) ($doc['components']['securitySchemes'] ?? []);
    $validator = new Validator($schemas);
    foreach ($schemas as $name => $schema) {
        foreach ($validator->schemaProblems($schema) as $p) {
            $problems[] = 'schema ' . $name . ': ' . $p;
        }
    }

    $ids     = [];
    $methods = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
    foreach ((array) $doc['paths'] as $path => $item) {
        if (!str_starts_with((string) $path, '/')) {
            $problems[] = $path . ': a path must start with /';
        }
        preg_match_all('/\{([^}]+)\}/', (string) $path, $m);
        foreach ($item as $method => $op) {
            $where = strtoupper($method) . ' ' . $path;
            if (!in_array($method, $methods, true)) {
                $problems[] = $where . ': not an HTTP method';
                continue;
            }
            $id = $op['operationId'] ?? '';
            if ($id === '' || isset($ids[$id])) {
                $problems[] = $where . ': operationId missing or repeated';
            }
            $ids[$id] = true;
            $inPath   = [];
            foreach ((array) ($op['parameters'] ?? []) as $p) {
                if (!in_array($p['in'] ?? '', ['path', 'query', 'header', 'cookie'], true) || !isset($p['name'], $p['schema'])) {
                    $problems[] = $where . ': a parameter needs name, in and schema';
                }
                if (($p['in'] ?? '') === 'path') {
                    $inPath[] = $p['name'];
                    if (($p['required'] ?? false) !== true) {
                        $problems[] = $where . ': path parameter ' . $p['name'] . ' must be required';
                    }
                }
                foreach ($validator->schemaProblems((array) ($p['schema'] ?? [])) as $sp) {
                    $problems[] = $where . ' parameter ' . ($p['name'] ?? '?') . ': ' . $sp;
                }
            }
            if ($inPath !== $m[1]) {
                $problems[] = $where . ': path parameters do not match the template';
            }
            if (!is_array($op['responses'] ?? null) || $op['responses'] === []) {
                $problems[] = $where . ': no responses';
            }
            foreach ((array) $op['responses'] as $status => $response) {
                if (preg_match('/^[1-5][0-9]{2}$/D', (string) $status) !== 1 || !is_string($response['description'] ?? null)) {
                    $problems[] = $where . ' ' . $status . ': a response needs a status code and a description';
                }
                foreach ((array) ($response['content'] ?? []) as $type => $media) {
                    if ((int) $status >= 400 && ($type !== 'application/problem+json' || ($media['schema'] ?? null) !== Schema::ref('Problem'))) {
                        $problems[] = $where . ' ' . $status . ': an error answer must be a Problem';
                    }
                    foreach ($validator->schemaProblems((array) ($media['schema'] ?? [])) as $sp) {
                        $problems[] = $where . ' ' . $status . ': ' . $sp;
                    }
                }
            }
            if (isset($op['requestBody'])) {
                foreach ($validator->schemaProblems((array) ($op['requestBody']['content']['application/json']['schema'] ?? [])) as $sp) {
                    $problems[] = $where . ' body: ' . $sp;
                }
            }
            foreach ((array) ($op['security'] ?? []) as $requirement) {
                foreach (array_keys((array) $requirement) as $scheme) {
                    if (!isset($schemes[$scheme])) {
                        $problems[] = $where . ': unknown security scheme ' . $scheme;
                    }
                }
            }
            if (!array_key_exists('x-scope', $op) || !array_key_exists('x-auth', $op)) {
                $problems[] = $where . ': x-scope and x-auth are required';
            }
        }
    }

    // Every $ref anywhere in the document resolves.
    $json = (string) json_encode($doc);
    preg_match_all('~"\$ref":"#/components/schemas/([^"]+)"~', str_replace('\/', '/', $json), $refs);
    foreach (array_unique($refs[1]) as $ref) {
        if (!isset($schemas[$ref])) {
            $problems[] = 'unresolved $ref ' . $ref;
        }
    }

    return $problems;
}

harness_section('the core document');
$core = OpenApi::core()->build();
pin('the core document is structurally sound', [], openapi_problems($core));
$op = $core['paths']['/admin/areas']['post'];
pin('a replayable write declares 409, 500 and 503, and Idempotency-Replayed and Request-Id', [true, true, true, true, true], [
    isset($op['responses']['409']), isset($op['responses']['500']), isset($op['responses']['503']),
    isset($op['responses']['201']['headers']['Idempotency-Replayed']), isset($op['responses']['201']['headers']['Request-Id']),
]);
pin('a read declares 500 and 503 but no 409', [true, true, false], [
    isset($core['paths']['/listings']['get']['responses']['500']), isset($core['paths']['/listings']['get']['responses']['503']), isset($core['paths']['/listings']['get']['responses']['409']),
]);
pin('Problem.code is an open string, the known codes only examples', [false, true], [isset($core['components']['schemas']['Problem']['properties']['code']['enum']), count($core['components']['schemas']['Problem']['properties']['code']['examples'] ?? []) > 10]);
pin('info.version is the v1 document revision', '1.0', $core['info']['version']);
pin('a route needing no credential is still documented as rate limited', [true, true], [
    isset($core['paths']['/auth/token']['post']['responses']['429']), isset($core['paths']['/auth/token']['post']['responses']['429']['headers']['RateLimit']),
]);
$documented = [];
foreach ($core['paths'] as $path => $item) {
    foreach (array_keys($item) as $method) {
        $documented[] = strtoupper($method) . ' ' . ltrim($path, '/');
    }
}
$served = array_keys((new Router(new Validator(Schema::components()), Router::core()))->all());
sort($documented);
sort($served);
pin('every core route is documented, and only those', $served, $documented);
check('GET /openapi.json itself is documented', isset($core['paths']['/openapi.json']['get']));
pin('GET /openapi.json takes any credential, or none when public reads are on', ['public', true], [$core['paths']['/openapi.json']['get']['x-auth'], in_array((object) [], $core['paths']['/openapi.json']['get']['security'])]);
$show = $core['paths']['/listings/{id}']['get'];
pin('a public read names its scope, both ways to send a key, a page token, and none at all', [['bearer' => ['listings:read']], ['pageSession' => ['listings:read']], ['publicKey' => ['listings:read']], []], array_map(static fn ($r): array => (array) $r, $show['security']));
pin('a GET takes If-None-Match and can answer 304 with its ETag', [true, true, '#/components/headers/ETag'], [
    in_array('If-None-Match', array_column($show['parameters'], 'name'), true), isset($show['responses']['304']), $show['responses']['200']['headers']['ETag']['$ref'] ?? null,
]);
check('every counted answer documents the rate limit headers', isset($show['responses']['200']['headers']['RateLimit'], $show['responses']['429']['headers']['Retry-After']));
$post = $core['paths']['/listings']['post'];
check('a write takes Idempotency-Key', in_array('Idempotency-Key', array_column($post['parameters'] ?? [], 'name'), true));
check('a write whose answer holds a secret does not', !in_array('Idempotency-Key', array_column($core['paths']['/auth/token']['post']['parameters'] ?? [], 'name'), true));
pin('the token endpoint takes JSON or a form', ['application/json', 'application/x-www-form-urlencoded'], array_keys($core['paths']['/auth/token']['post']['requestBody']['content']));
$deleteCategory = $core['paths']['/admin/categories/{id}']['delete']['responses'];
pin('DELETE admin/categories answers 204, or 202 with no body', [true, false, false], [isset($deleteCategory['202']), isset($deleteCategory['202']['content']), isset($deleteCategory['204']['content'])]);
pin('the webhooks are described', ['listing.created', 'WebhookMessage', '#/components/schemas/Listing'], [
    array_key_first($core['webhooks']), substr((string) ($core['webhooks']['listing.created']['post']['requestBody']['content']['application/json']['schema']['allOf'][0]['$ref'] ?? ''), 21),
    $core['webhooks']['listing.created']['post']['requestBody']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? null,
]);
check('the webhook ping and delete use their own schemas', str_ends_with((string) ($core['webhooks']['ping']['post']['requestBody']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? ''), 'WebhookPing')
    && str_ends_with((string) ($core['webhooks']['user.deleted']['post']['requestBody']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? ''), 'WebhookDeleted'));
foreach (['ListingList', 'UserList', 'CommentList'] as $unused) {
    check($unused . ' is not listed, since no route answers with it', !isset($core['components']['schemas'][$unused]));
}
pin('its id is an integer path parameter', ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']], $show['parameters'][0]);
check('its 401 and 429 are Problems', isset($show['responses']['401'], $show['responses']['429']));
pin('the API key is a bearer scheme', ['http', 'bearer'], [$core['components']['securitySchemes']['bearer']['type'], $core['components']['securitySchemes']['bearer']['scheme']]);
check('every core scope is listed', array_keys(Scopes::CORE) === array_keys($core['x-scopes']));
pin('the committed copy names no site', OpenApi::relativeServers(), $core['servers']);
check('every component schema is in the document', array_keys(Schema::components()) === array_keys($core['components']['schemas']));

harness_section('plugins');
$validator = new Validator();
$ext       = ExtensionMembers::fromDeclarations([
    ['listing', 'acme', 'offer_count', ['type' => 'integer'], ['public']],
], $validator);
$components = Schema::components($ext);
$router     = new Router(new Validator($components), Router::core());
$router->addPlugin('POST', 'ext/acme/offers', [
    'handler' => static fn (): Response => Response::ok([]),
    'auth'    => RouteSpec::AUTH_USER,
    'scope'   => 'ext:acme:offers:write',
    'tags'    => ['Acme'],
    'summary' => 'Make an offer',
    'body'    => ['type' => 'object', 'required' => ['amount'], 'properties' => ['amount' => ['type' => 'integer']]],
]);
$doc = (new OpenApi($router, Definitions::of($components), new Scopes(['ext:acme:offers:write' => ['description' => 'Make offers.', 'audience' => 'user']]), 'v1', OpenApi::relativeServers()))->build();
pin('with a plugin route it is still sound', [], openapi_problems($doc));
$op = $doc['paths']['/ext/acme/offers']['post'] ?? [];
pin('the plugin route is documented with its body, scope and tag; a user scope also takes a page token', [[['bearer' => ['ext:acme:offers:write']], ['pageSession' => ['ext:acme:offers:write']]], ['Acme'], ['amount']], [
    $op['security'] ?? null, $op['tags'] ?? null, $op['requestBody']['content']['application/json']['schema']['required'] ?? null,
]);
check('a write with a body can answer 400, 413, 415 and 422', isset($op['responses']['400'], $op['responses']['413'], $op['responses']['415'], $op['responses']['422']));
check('the plugin scope is listed', isset($doc['x-scopes']['ext:acme:offers:write']));
pin('a plugin POST that names no response is a 200 with its body undescribed', [true, false, false], [
    isset($op['responses']['200']), isset($op['responses']['200']['content']), isset($op['responses']['201']),
]);
check('a declared ext field is in the Listing schema', str_contains((string) json_encode($doc['components']['schemas']['Listing']), 'offer_count'));
check('the plugin\'s tag is listed', in_array(['name' => 'Acme'], $doc['tags'], true));

harness_section('deprecation');
$old = new Router(new Validator(), ['GET old' => [
    'handler' => static fn (): Response => Response::ok([]), 'auth' => RouteSpec::AUTH_NONE,
    'deprecated' => '2026-10-01', 'sunset' => '2027-06-01',
]]);
$doc = (new OpenApi($old, Definitions::of([]), new Scopes(), 'v1', OpenApi::relativeServers()))->build();
pin('a deprecated route says so, with its sunset date', [true, '2027-06-01'], [$doc['paths']['/old']['get']['deprecated'] ?? null, $doc['paths']['/old']['get']['x-sunset'] ?? null]);
$bad = static function (array $spec): string {
    try {
        new RouteSpec('GET', 'x', $spec + ['handler' => static fn () => Response::ok([])]);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return 'accepted';
};
pin('a deprecation that is not a date is refused', 'GET x: deprecated must be a date, Y-m-d.', $bad(['deprecated' => '7.2.0']));
pin('a sunset before the deprecation is refused', 'GET x: sunset comes before deprecated.', $bad(['deprecated' => '2027-01-01', 'sunset' => '2026-01-01']));

harness_section('served by the kernel');
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
$definitions = Schema::definitions();
$full        = new Validator($definitions);
$settings    = new ApiSettings(true, true);
$router      = null;
$router      = new Router($full, Router::core() + ['GET old' => [
    'handler' => static fn (): Response => Response::ok([]), 'auth' => RouteSpec::AUTH_NONE,
    'deprecated' => '2026-10-01', 'sunset' => '2027-06-01', 'responses' => [200 => ['type' => 'object']],
]], handlers: static function (string $class) use (&$router, $definitions): object {
    return OpenApi::forSite($router, $definitions, new Scopes());
});
$kernel   = api_test_kernel($router, api_test_authenticator(new ApiKeys($store, new Scopes(), new SystemClock())), $settings, validator: $full);
$r = $kernel->handle(new Request('GET', 'v1/openapi.json', [], [], '127.0.0.1'));
pin('GET /api/v1/openapi.json answers with no credential when public reads are on', [200, '3.1.0'], [$r->status(), $r->body()['openapi'] ?? null]);
pin('and asks for one when they are off', 401, api_test_kernel($router, api_test_authenticator(new ApiKeys($store, new Scopes(), new SystemClock())), new ApiSettings(true), validator: $full)
    ->handle(new Request('GET', 'v1/openapi.json', [], [], '127.0.0.1'))->status());
pin('with the document revision and its own URL', ['1.0', 'https://shop.test/api/v1'], [$r->body()['info']['version'], $r->body()['servers'][0]['url']]);
pin('the OpenAPI document stays out of shared caches, as turning public reads off must take effect, and gets an ETag', ['private, no-cache', true], [$r->header('Cache-Control'), isset($r->prepare('GET')['headers']['ETag'])]);
pin('the live document is sound', [], openapi_problems($r->body()));
$r = $kernel->handle(new Request('GET', 'v1/old', [], [], '127.0.0.1'));
pin('a deprecated route answers with Deprecation, Sunset and a changelog link', [
    '@' . strtotime('2026-10-01 00:00:00 UTC'), 'Tue, 01 Jun 2027 00:00:00 GMT', '<' . Kernel::CHANGELOG . '>; rel="deprecation"',
], [$r->header('Deprecation'), $r->header('Sunset'), $r->header('Link')]);
pin('a current route has none of them', [null, null], [
    $kernel->handle(new Request('GET', 'v1/openapi.json', [], [], '127.0.0.1'))->header('Deprecation'),
    $kernel->handle(new Request('GET', 'v1/openapi.json', [], [], '127.0.0.1'))->header('Sunset'),
]);

harness_section('committed files');
exec(PHP_BINARY . ' ' . escapeshellarg(ABS_PATH . 'tools/gen-openapi.php') . ' --check 2>&1', $out1, $code1);
pin('docs/site/developers/api/openapi.json is current', 0, $code1);
exec(PHP_BINARY . ' ' . escapeshellarg(ABS_PATH . 'tools/gen-api-doc.php') . ' --check 2>&1', $out2, $code2);
pin('docs/site/developers/api/reference.md is current', 0, $code2);
$committed = json_decode((string) file_get_contents(ABS_PATH . 'docs/site/developers/api/openapi.json'), true);
pin('the committed file is the core document', json_decode((string) json_encode($core), true), $committed);

harness_section('warnings');
$writesDoc = (string) file_get_contents(ABS_PATH . 'docs/site/developers/api/writes.md');
$table     = (string) substr($writesDoc, (int) strpos($writesDoc, "\n## Warnings\n"));
preg_match_all('/^\| `([a-z_]+)` \|/m', $table, $documented);
pin('every warning code is in the warnings table of writes.md, in its order', Warning::CODES, $documented[1]);
pin('the Warning schema lists the same codes', Warning::CODES, $core['components']['schemas']['Warning']['properties']['code']['enum'] ?? null);

/**
 * Warning codes the core controllers and writers emit: every array key and `$warnings[...]` index
 * in a statement that names `$warnings` or Warning::member(), as written in the source.
 *
 * @return string[] `Warning::NAME` or a quoted literal
 */
function api_emitted_warning_codes(string $code): array
{
    $found = [];
    foreach (preg_split('/;/', $code) ?: [] as $statement) {
        if (!preg_match('/\$warnings\b|Warning::member\(/', $statement)) {
            continue;
        }
        preg_match_all('/(Warning::[A-Z_]+|\'[^\']*\'|"[^"]*")\s*=>|\$warnings\[\s*(Warning::[A-Z_]+|\'[^\']*\'|"[^"]*")\s*\]/', $statement, $m);
        array_push($found, ...array_filter(array_merge($m[1], $m[2])));
    }

    return $found;
}

$emitted = [];
foreach (['controller', 'write'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-includes/osclass/classes/api/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        array_push($emitted, ...api_emitted_warning_codes((string) file_get_contents((string) $file)));
    }
}
$named = static fn (string $code): bool => str_starts_with($code, 'Warning::') && defined(Warning::class . substr($code, 7))
    && in_array(constant(Warning::class . substr($code, 7)), Warning::CODES, true);
pin('the scan finds every warning the core emits', ['Warning::COMMENT_PENDING', 'Warning::EMAIL_CONFIRMATION_SENT', 'Warning::LISTING_PENDING', 'Warning::PHOTO_SKIPPED'], (static function (array $codes): array {
    sort($codes);

    return array_values(array_unique($codes));
})($emitted));
pin('every warning a controller or writer emits is a Warning constant listed in Warning::CODES', [], array_values(array_filter(array_unique($emitted), static fn (string $c): bool => !$named($c))));
pin('a literal code is caught', ["'comment_waiting'"], api_emitted_warning_codes("\$warnings = Warning::member(\$live ? [] : ['comment_waiting' => 'Soon.']);"));

exit(harness_result());
