<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins every hook and filter name core fires. Plugins on sites we cannot see register
 * against these names, so removing or renaming one has to be a deliberate edit of
 * tests/fixtures/hook-names.txt, visible in the diff.
 *
 * The REST API's hooks (`api_*`) also have their argument count pinned, in
 * tests/fixtures/hook-api-args.txt: a plugin callback with the old count would break.
 *
 * Also pins that no name is fired as both an action and a filter: osc_add_hook() and
 * osc_add_filter() share one registry, so a collision calls one set of callbacks with
 * two different argument shapes.
 *
 * DB-free.  Usage: php tests/hook-contract.php [--write]
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

const HOOK_FIXTURE = __DIR__ . '/fixtures/hook-names.txt';
const HOOK_ARGS_FIXTURE = __DIR__ . '/fixtures/hook-api-args.txt';

/** Directories scanned. Themes and plugins live in their own repositories. */
const HOOK_ROOTS = array('oc-includes/osclass', 'oc-admin');

/**
 * Every hook and filter name fired under HOOK_ROOTS.
 *
 * @return array{actions:string[],filters:string[],dynamic:int}
 */
function hook_scan(): array
{
    $actions = array();
    $filters = array();
    $dynamic = 0;

    foreach (HOOK_ROOTS as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . $root));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());

            // Literal names. Both the helper and the class method reach the same registry.
            preg_match_all(
                '/(osc_run_hook|Plugins::runHook)\s*\(\s*([\'"])([a-zA-Z0-9_]+)\2\s*(?![.\s]*\.)/',
                $src,
                $m
            );
            foreach ($m[3] as $name) {
                $actions[$name] = true;
            }

            preg_match_all(
                '/(osc_apply_filter|Plugins::applyFilter)\s*\(\s*([\'"])([a-zA-Z0-9_]+)\2\s*(?![.\s]*\.)/',
                $src,
                $m
            );
            foreach ($m[3] as $name) {
                $filters[$name] = true;
            }

            // Names built at runtime. Not documentable, not greppable, not pinnable.
            $dynamic += preg_match_all(
                '/(osc_run_hook|Plugins::runHook|osc_apply_filter|Plugins::applyFilter)\s*\(\s*\$/',
                $src
            );
        }
    }

    ksort($actions);
    ksort($filters);

    return array(
        'actions' => array_keys($actions),
        'filters' => array_keys($filters),
        'dynamic' => $dynamic,
    );
}

/**
 * How many arguments a hook call passes after its name, read from just after the name: top-level
 * commas up to the closing paren.
 */
function hook_arg_count(string $src, int $offset): int
{
    $depth = 0;
    $count = 0;
    $quote = null;
    for ($i = $offset, $n = strlen($src); $i < $n; $i++) {
        $c = $src[$i];
        if ($quote !== null) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === '\'' || $c === '"') {
            $quote = $c;
        } elseif ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            if ($depth === 0) {
                return $count;
            }
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $count++;
        }
    }

    return $count;
}

/**
 * The top-level arguments of a call, read from just after its opening paren, as source text.
 *
 * @return string[]
 */
function hook_call_args(string $src, int $offset): array
{
    $args  = array();
    $start = $offset;
    $depth = 0;
    $quote = null;
    for ($i = $offset, $n = strlen($src); $i < $n; $i++) {
        $c = $src[$i];
        if ($quote !== null) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === '\'' || $c === '"') {
            $quote = $c;
        } elseif ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif (($c === ')' || $c === ']' || $c === '}') && $depth > 0) {
            $depth--;
        } elseif (($c === ',' || $c === ')') && $depth === 0) {
            $args[] = trim(substr($src, $start, $i - $start));
            if ($c === ')') {
                break;
            }
            $start = $i + 1;
        }
    }

    return $args;
}

/**
 * Filters fired through a wrapper that takes the hook name: Extensions::finish() replays
 * `api_*` serializer filters with the data plus its $args array (6th argument).
 *
 * @return string[] "replay name count"
 */
function hook_replay_args(string $src): array
{
    $lines = array();
    preg_match_all('/->finish\s*\(\s*([\'"])(api_[a-z0-9_]+)\1/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $call) {
        $args   = hook_call_args($src, strpos($src, '(', $call[0][1]) + 1);
        $replay = $args[5] ?? '';
        $count  = preg_match('/^\[(.*)\]$/s', $replay, $inner) === 1
            ? (trim($inner[1]) === '' ? 0 : count(hook_call_args($inner[1] . ')', 0)))
            : -1;
        $lines[] = 'replay ' . $call[2][0] . ' ' . ($count < 0 ? 'unknown' : $count + 1);
    }

    return $lines;
}

/**
 * "kind name count" for every call site of an `api_*` hook, sorted and unique.
 *
 * @return string[]
 */
function hook_api_args(): array
{
    $lines = array();
    foreach (HOOK_ROOTS as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . $root));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            preg_match_all('/(osc_run_hook|Plugins::runHook|osc_apply_filter|Plugins::applyFilter)\s*\(\s*([\'"])(api_[a-z0-9_]+)\2/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $call) {
                $kind    = str_contains($call[1][0], 'ilter') ? 'filter' : 'action';
                $lines[] = $kind . ' ' . $call[3][0] . ' ' . hook_arg_count($src, $call[0][1] + strlen($call[0][0]));
            }
            array_push($lines, ...hook_replay_args($src));
        }
    }
    $lines = array_values(array_unique($lines));
    sort($lines);

    return $lines;
}

/**
 * Render the scan as the fixture's text form: one "kind name" per line.
 *
 * @param array{actions:string[],filters:string[],dynamic:int} $scan
 */
function hook_render(array $scan): string
{
    $lines = array();
    foreach ($scan['actions'] as $n) {
        $lines[] = 'action ' . $n;
    }
    foreach ($scan['filters'] as $n) {
        $lines[] = 'filter ' . $n;
    }
    sort($lines);

    return implode("\n", $lines) . "\n";
}

$scan    = hook_scan();
$actual  = hook_render($scan);
$apiArgs = hook_api_args();

if (in_array('--write', $argv, true)) {
    file_put_contents(HOOK_FIXTURE, $actual);
    file_put_contents(HOOK_ARGS_FIXTURE, implode("\n", $apiArgs) . "\n");
    echo 'Wrote ' . (count($scan['actions']) + count($scan['filters'])) . " names to " . HOOK_FIXTURE . "\n";
    exit(0);
}

harness_section('Fired names');

$expected = is_file(HOOK_FIXTURE) ? file_get_contents(HOOK_FIXTURE) : '';
$want     = array_filter(explode("\n", $expected));
$got      = array_filter(explode("\n", $actual));

$removed = array_diff($want, $got);
$added   = array_diff($got, $want);

report(
    'no hook or filter name was removed or renamed',
    $removed === array(),
    'none missing',
    $removed === array() ? 'none' : implode(', ', $removed)
);

report(
    'new hook names are recorded in the fixture',
    $added === array(),
    'none unrecorded',
    $added === array() ? 'none' : implode(', ', $added) . '  (run: php tests/hook-contract.php --write)'
);

harness_section('API hook arguments');

check('the arg counter reads nesting and strings', hook_arg_count(", \$a, f(\$b, \$c), ['x' => ','], \$d);", 0) === 4);
$pinnedArgs = is_file(HOOK_ARGS_FIXTURE) ? array_values(array_filter(explode("\n", (string) file_get_contents(HOOK_ARGS_FIXTURE)))) : array();
pin('each api_* hook passes the pinned argument count (php tests/hook-contract.php --write)', $pinnedArgs, $apiArgs);
check('the replay reader counts the data and the array', hook_replay_args("\$x->finish('api_user', 'user', M, \$d, \$f, [\$user, f(\$a, \$b)], \$c);") === array('replay api_user 3'));
$byName = array();
foreach ($apiArgs as $line) {
    [, $name, $count] = explode(' ', $line);
    $byName[$name][$count] = true;
}
pin('every call site and replay of an api_* hook passes the same arguments', array(), array_keys(array_filter($byName, static fn (array $l): bool => count($l) > 1)));

harness_section('Action and filter do not collide');

$both = array_intersect($scan['actions'], $scan['filters']);
report(
    'no name is fired as both an action and a filter',
    $both === array(),
    'none',
    $both === array() ? 'none' : implode(', ', $both)
);

harness_section('Runtime-composed names');

// The standard forbids these in new code. The count may fall, never rise.
check(
    'no new runtime-composed hook name (' . $scan['dynamic'] . ' remain)',
    $scan['dynamic'] <= 13,
    'found ' . $scan['dynamic'] . ', ceiling is 13'
);

exit(harness_result());
