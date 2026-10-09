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
 * The API's plugin contract: every `@api` method, constant and helper function and each error
 * code's status, byte-compared with a fixture, and the error codes, against the errors page.
 * DB-free. Usage: php tests/api-contract.php [--write]
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\Problem;
use mindstellar\api\ratelimit\RatePolicy;
use mindstellar\api\Request;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\Credential;
use mindstellar\apikey\Scopes;

const API_SURFACE_FIXTURE = __DIR__ . '/fixtures/api-surface.txt';
const API_HELPERS         = 'oc-includes/osclass/helpers/hApi.php';

require_once ABS_PATH . API_HELPERS;

/**
 * `public static name(type $a = default): type` for a method, `function name(...)` for a function.
 */
function api_signature(ReflectionFunctionAbstract $r): string
{
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $s = ($p->hasType() ? (string) $p->getType() . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $d  = $p->getDefaultValue();
            $s .= ' = ' . (is_array($d) ? json_encode($d) : var_export($d, true));
        }
        $parts[] = $s;
    }

    $prefix = $r instanceof ReflectionMethod ? 'public' . ($r->isStatic() ? ' static' : '') : 'function';

    return $prefix . ' ' . $r->getName() . '(' . implode(', ', $parts) . ')' . ($r->hasReturnType() ? ': ' . (string) $r->getReturnType() : '');
}

/**
 * One line per `@api` method or constant of a core class, per `@api` API helper and per error
 * code with its status, sorted. Titles are left out: they may change.
 *
 * @return string[]
 */
function api_surface(): array
{
    $root  = ABS_PATH . 'oc-includes/osclass/classes/';
    $lines = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $source = (string) file_get_contents((string) $file);
        if (!str_contains($source, '@api')) {
            continue;
        }
        if (preg_match('/^namespace ([^;]+);/m', $source, $ns) !== 1
            || preg_match('/^(?:final |abstract )*(?:class|interface|trait|enum) (\w+)/m', $source, $name) !== 1) {
            continue;
        }
        $class = $ns[1] . '\\' . $name[1];
        $r     = new ReflectionClass($class);
        foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === $class && preg_match('/@api\b/', (string) $m->getDocComment()) === 1) {
                $lines[] = $class . '::' . api_signature($m);
            }
        }
        foreach ($r->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $c) {
            if ($c->getDeclaringClass()->getName() === $class && preg_match('/@api\b/', (string) $c->getDocComment()) === 1) {
                $lines[] = $class . '::' . $c->getName() . ' = ' . json_encode($c->getValue(), JSON_UNESCAPED_SLASHES);
            }
        }
    }
    foreach (get_defined_functions()['user'] as $function) {
        $r = new ReflectionFunction($function);
        if ($r->getFileName() === realpath(ABS_PATH . API_HELPERS) && preg_match('/@api\b/', (string) $r->getDocComment()) === 1) {
            $lines[] = api_signature($r);
        }
    }
    foreach (Problem::CATALOGUE as $code => [$status]) {
        $lines[] = Problem::class . ' code ' . $code . ' = ' . $status;
    }
    sort($lines);

    return $lines;
}

harness_section('@api surface');
$surface = implode("\n", api_surface()) . "\n";
if (in_array('--write', $argv, true)) {
    file_put_contents(API_SURFACE_FIXTURE, $surface);
    echo 'Wrote ' . API_SURFACE_FIXTURE . "\n";
}
$pinned = is_file(API_SURFACE_FIXTURE) ? (string) file_get_contents(API_SURFACE_FIXTURE) : '';
check('the @api surface matches tests/fixtures/api-surface.txt; a change is a plugin contract change (php tests/api-contract.php --write)', $pinned === $surface, implode("\n", array_merge(
    array_map(static fn (string $l): string => '+ ' . $l, array_diff(explode("\n", $surface), explode("\n", $pinned))),
    array_map(static fn (string $l): string => '- ' . $l, array_diff(explode("\n", $pinned), explode("\n", $surface)))
)));
check('ViewContext is built by core, not by plugins', !str_contains($surface, 'ViewContext::public __construct'));
foreach (['RouteSpec::public key()', 'RouteSpec::public path()', 'RouteSpec::public method()', 'RouteSpec::public auth()', 'RouteSpec::public scope()', 'RouteSpec::public plugin()', 'Response::public status()', 'Response::public body()', 'Response::public withBodyMember('] as $handed) {
    check('hooks hand plugins ' . $handed . ', so it is @api', str_contains($surface, $handed));
}
check('Credential, which every handler reads, is pinned', str_contains($surface, 'mindstellar\\apikey\\Credential::public has(string $scope): bool'));
foreach (['osc_api_register_route', 'osc_api_register_schema', 'osc_api_register_field', 'osc_api_url', 'osc_webhook_emit'] as $helper) {
    check($helper . '() is pinned with its signature', str_contains($surface, 'function ' . $helper . '('));
}
check('the unchecked ListingReader::one() is not @api; ApiKit::listing() is', !str_contains($surface, 'ListingReader::public one(') && str_contains($surface, 'ApiKit::public listing('));
check('no @api method takes or hands out a raw listing row', !str_contains($surface, 'ApiCall::public canViewListing(') && !str_contains($surface, 'ApiCall::public visibleListing(') && !str_contains($surface, 'ApiKit::public listings('));
check('error titles are not pinned', !str_contains($surface, 'Problem::CATALOGUE'));
check('Links takes no raw row in the contract; plugins call it, never implement it', !str_contains($surface, 'Links::public listing(') && !str_contains($surface, 'Links::public photo('));
check('ApiKit::listingContext() is pinned, so plugins need no ListingSerializer constant', str_contains($surface, 'ApiKit::public listingContext(') && !str_contains($surface, 'ListingSerializer::'));

preg_match_all("/^if \\(!function_exists\\('(\\w+)'\\)\\)/m", (string) file_get_contents(ABS_PATH . API_HELPERS), $guarded);
$elsewhere = array_values(array_filter($guarded[1], static fn (string $f): bool => !function_exists($f) || (new ReflectionFunction($f))->getFileName() !== realpath(ABS_PATH . API_HELPERS)));
pin('every API helper comes from hApi.php, not an earlier definition that would hide it from the surface', [], $elsewhere);

harness_section('hook payload shapes');
$pluginPage = (string) file_get_contents(ABS_PATH . 'docs/site/developers/api/plugin-endpoints.md');
preg_match('/^\\| `api_rate_limit` \\|[^|]*\\| `array\\(([^)]*)\\)/m', $pluginPage, $row);
preg_match_all("/'(\\w+)' =>/", $row[1] ?? '', $documented);
$seen    = null;
$buckets = api_with_filter('api_rate_limit', static function ($limit) use (&$seen) {
    $seen = $limit;

    return ['max' => 7, 'window' => 30];
}, static fn () => (new RatePolicy(new ApiSettings(true)))->bucketsFor(new Request('GET', 'v1/x', [], [], '127.0.0.1'), new RouteSpec('GET', 'x', ['handler' => 'strlen']), Credential::anonymous()));
pin('api_rate_limit hands the keys plugin-endpoints.md documents', $documented[1], array_keys((array) $seen));
pin('and reads max and window back', [7, 30], [$buckets[0]->max(), $buckets[0]->window()]);

check('plugin-endpoints.md documents api_problem_codes entries as [status, title]', str_contains($pluginPage, '`$codes` (`code => array($status, $title)`)'));
$teapot = api_with_filter('api_problem_codes', static fn ($codes) => ['ext_acme_teapot' => [418, 'Short and stout']] + (array) $codes, static fn () => Problem::make('ext_acme_teapot'));
pin('an api_problem_codes entry is read as [status, title]', [418, 'Short and stout'], [$teapot->status(), $teapot->body()['title']]);

check('plugin-endpoints.md documents api_scopes entries with description and audience', preg_match("/'description' => .*\\n\\s*'audience' +=>/", $pluginPage) === 1);
$scopes = api_with_filter('api_scopes', static fn ($s) => ['ext:acme:x' => ['description' => 'Do x.', 'audience' => Scopes::AUDIENCE_USER]] + (array) $s, static fn () => Scopes::fromHooks());
pin('an api_scopes entry is read as description and audience', ['Do x.', 'user'], [$scopes->all()['ext:acme:x'] ?? null, $scopes->pluginAudience('ext:acme:x')]);

preg_match_all('/^\| `(api_listing|api_user|api_category)` \| (.+) \|$/m', $pluginPage, $rows);
pin('plugin-endpoints.md documents the object filters as data and context, never a database row', array_fill_keys(['api_listing', 'api_user', 'api_category'], '`$data, $context`'), array_combine($rows[1], $rows[2]));

harness_section('error codes');
$errorsPage = (string) file_get_contents(ABS_PATH . 'docs/site/developers/api/errors.md');
preg_match_all('/<a id="([a-z_]+)"><\/a>|^### `([a-z_]+)`$/m', $errorsPage, $anchors);
pin('every error code has its anchor or heading on errors.md, for its type link', [], array_values(array_diff(array_keys(Problem::CATALOGUE), $anchors[1], $anchors[2])));

preg_match('/^### Field codes\n(.*?)(?=^#)/ms', $errorsPage, $section);
preg_match_all('/^\| `([A-Za-z_]+)` \|/m', $section[1] ?? '', $documented);
pin('errors.md lists exactly Problem::FIELD_CODES, in order', Problem::FIELD_CODES, $documented[1]);

harness_section('plugin route keys and shared components');
$pluginPage = (string) file_get_contents(ABS_PATH . 'docs/site/developers/api/plugin-endpoints.md');
preg_match('/^### The spec\n(.*?)(?=^#)/ms', $pluginPage, $section);
preg_match_all('/^\| ([^|]+) \|/m', $section[1] ?? '', $cells);
$keys = [];
foreach (array_slice($cells[1], 1) as $cell) {
    preg_match_all('/`([a-z_]+)`/', $cell, $named);
    $keys = array_merge($keys, $named[1]);
}
$expected = RouteSpec::PLUGIN_KEYS;
sort($keys);
sort($expected);
pin('plugin-endpoints.md lists exactly RouteSpec::PLUGIN_KEYS', $expected, $keys);
preg_match('/^### Your own components\n(.*?)\. Other core component names/ms', $pluginPage, $section);
preg_match_all('/`([A-Za-z]+)`/', $section[1] ?? '', $named);
pin('plugin-endpoints.md lists exactly Router::SHARED_COMPONENTS, in order', Router::SHARED_COMPONENTS, array_values(array_diff($named[1], ['ApiKit'])));

$log    = ini_get('error_log');
$tmp    = tempnam(sys_get_temp_dir(), 'apilog');
ini_set('error_log', (string) $tmp);
$codes = array_column(Problem::validation([
    ['pointer' => '/a', 'code' => 'minLength', 'message' => 'x'],
    ['pointer' => '/b', 'code' => 'too_long', 'message' => 'x'],
    ['pointer' => '/c', 'code' => 'ext_acme_rating_low', 'message' => 'x'],
    ['pointer' => '/d', 'code' => 'no_such_code', 'message' => 'x'],
])->body()['errors'], 'code');
ini_set('error_log', (string) $log);
$written = (string) file_get_contents((string) $tmp);
unlink((string) $tmp);
pin('a known code stays, a core reason maps, a plugin code stays, an unknown one is invalid', ['minLength', 'maxLength', 'ext_acme_rating_low', 'invalid'], $codes);
check('an unknown field code is logged', str_contains($written, 'unknown field error code no_such_code'));
pin('an entry keeps its member order', ['pointer', 'code', 'message', 'in'], array_keys(Problem::validation([['pointer' => '/a', 'code' => 'taken', 'message' => 'x']])->body()['errors'][0]));

exit(harness_result());
