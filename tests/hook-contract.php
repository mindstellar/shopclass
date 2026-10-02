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

$scan   = hook_scan();
$actual = hook_render($scan);

if (in_array('--write', $argv, true)) {
    file_put_contents(HOOK_FIXTURE, $actual);
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
