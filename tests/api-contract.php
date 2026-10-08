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
 * The API's plugin contract: every `@api` method and constant under mindstellar\api, byte-compared
 * with a fixture, and the field error codes, against the errors page.
 * DB-free. Usage: php tests/api-contract.php [--write]
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\Problem;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;

const API_SURFACE_FIXTURE = __DIR__ . '/fixtures/api-surface.txt';

/**
 * `public static name(type $a = default): type`, as harness_method_signature() writes it for a class.
 */
function api_signature(ReflectionMethod $r): string
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

    return 'public' . ($r->isStatic() ? ' static' : '') . ' ' . $r->getName() . '(' . implode(', ', $parts) . ')' . ($r->hasReturnType() ? ': ' . (string) $r->getReturnType() : '');
}

/**
 * One line per `@api` method or constant, sorted.
 *
 * @return string[]
 */
function api_surface(): array
{
    $root  = ABS_PATH . 'oc-includes/osclass/classes/api/';
    $lines = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $source = (string) file_get_contents((string) $file);
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
foreach (['RouteSpec::public key()', 'RouteSpec::public path()', 'RouteSpec::public method()', 'Response::public status()', 'Response::public body()', 'Response::public withBodyMember('] as $handed) {
    check('hooks hand plugins ' . $handed . ', so it is @api', str_contains($surface, $handed));
}
check('the unchecked ListingReader::one() is not @api; ApiKit::listing() is', !str_contains($surface, 'ListingReader::public one(') && str_contains($surface, 'ApiKit::public listing('));

harness_section('field error codes');
$errorsPage = (string) file_get_contents(ABS_PATH . 'docs/site/developers/api/errors.md');
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
