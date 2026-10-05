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
 * Pins the order in which the search page exports view variables and fires hooks.
 *
 * Themes read the exported keys and plugins listen on the hooks, so a refactor of
 * CWebSearch must keep both lists, in the same order. The scan starts at __construct()
 * and doModel() and follows calls into the controller's own methods and into
 * SearchUriResolver / SearchRunner, so moving code into a named step or one of those
 * classes does not change the list.
 *
 * DB-free.  Usage: php tests/search-exports-contract.php [--write]
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

const SEARCH_EXPORTS_FIXTURE = __DIR__ . '/fixtures/search-exports.txt';

/** Classes the scan follows calls into, by short name. */
const SEARCH_EXPORTS_FILES = array(
    'CWebSearch'        => 'oc-includes/osclass/classes/controller/CWebSearch.php',
    'SearchUriResolver' => 'oc-includes/osclass/classes/search/SearchUriResolver.php',
    'SearchRunner'      => 'oc-includes/osclass/classes/search/SearchRunner.php',
);

/**
 * Method bodies of one file, comments removed, keyed by method name.
 *
 * @param string $path
 *
 * @return array<string,string>
 */
function search_exports_methods(string $path): array
{
    if (!is_file($path)) {
        return array();
    }
    $tokens  = token_get_all(file_get_contents($path));
    $methods = array();
    $count   = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            continue; // a closure
        }
        $name = $tokens[$j][1];
        while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
            $j++;
        }
        if ($j >= $count || $tokens[$j] === ';') {
            continue; // abstract
        }
        $depth = 0;
        $body  = '';
        for (; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '{' || (is_array($t) && in_array($t[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
            if (is_array($t) && in_array($t[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $body .= is_array($t) ? $t[1] : $t;
            if ($depth === 0) {
                break;
            }
        }
        $methods[$name] = $body;
        $i              = $j;
    }

    return $methods;
}

/**
 * Exports and hooks reached from $class::$method, in source order, following calls.
 *
 * @param array<string,array<string,string>> $classes
 * @param string                             $class
 * @param string                             $method
 * @param array<string,bool>                 $stack
 *
 * @return string[]
 */
function search_exports_walk(array $classes, string $class, string $method, array $stack = array()): array
{
    $key = $class . '::' . $method;
    if (!isset($classes[$class][$method]) || isset($stack[$key])) {
        return array();
    }
    $stack[$key] = true;
    $body        = $classes[$class][$method];
    $names       = implode('|', array_keys($classes));
    $pattern     = '/_exportVariableToView\s*\(\s*(?<export>[^,]*),'
        . '|(?:osc_(?<osc>run_hook|apply_filter)|Plugins::(?<plugins>runHook|applyFilter))\s*\(\s*(?<hook>[^,)]*)'
        . '|\$this->(?!_exportVariableToView)(?<own>\w+)\s*\('
        . '|\b(?:self|static)::(?<self>\w+)\s*\('
        . '|\b(?<class>' . $names . ')::(?<method>\w+)\s*\(/';
    preg_match_all($pattern, $body, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

    $out = array();
    foreach ($m as $hit) {
        if ($hit['export'] !== null) {
            $out[] = 'export ' . search_exports_name($hit['export']);
        } elseif ($hit['hook'] !== null) {
            $isAction = $hit['osc'] === 'run_hook' || $hit['plugins'] === 'runHook';
            $out[]    = ($isAction ? 'hook ' : 'filter ') . search_exports_name($hit['hook']);
        } elseif ($hit['own'] !== null) {
            $out = array_merge($out, search_exports_walk($classes, $class, $hit['own'], $stack));
        } elseif ($hit['self'] !== null) {
            $out = array_merge($out, search_exports_walk($classes, $class, $hit['self'], $stack));
        } elseif ($hit['class'] !== null) {
            $out = array_merge($out, search_exports_walk($classes, $hit['class'], $hit['method'], $stack));
        }
    }

    return $out;
}

/**
 * A quoted literal name, either quote style; 'name' . $x becomes name<named>, anything else <dynamic>.
 *
 * @param string $arg
 *
 * @return string
 */
function search_exports_name(string $arg): string
{
    if (preg_match('/^([\'"])([A-Za-z0-9_]+)\1\s*(\.)?/', trim($arg), $n)) {
        return $n[2] . (isset($n[3]) ? '<named>' : '');
    }

    return '<dynamic>';
}

$classes = array();
foreach (SEARCH_EXPORTS_FILES as $class => $file) {
    $classes[$class] = search_exports_methods(ABS_PATH . $file);
}

$actual = array_merge(
    search_exports_walk($classes, 'CWebSearch', '__construct'),
    search_exports_walk($classes, 'CWebSearch', 'doModel')
);

if (in_array('--write', $argv, true)) {
    file_put_contents(SEARCH_EXPORTS_FIXTURE, implode("\n", $actual) . "\n");
    echo 'wrote ' . count($actual) . " lines to tests/fixtures/search-exports.txt\n";
    exit(0);
}

$expected = array_values(array_filter(array_map('trim', file(SEARCH_EXPORTS_FIXTURE)), 'strlen'));

harness_section('exports and hooks, in order');

pin('the ordered list matches tests/fixtures/search-exports.txt', $expected, $actual);
if ($expected !== $actual) {
    foreach (array_keys($expected + $actual) as $i) {
        if (($expected[$i] ?? null) !== ($actual[$i] ?? null)) {
            echo '        first difference at line ' . ($i + 1) . ': expected '
                . describe($expected[$i] ?? null) . ', got ' . describe($actual[$i] ?? null) . "\n";
            break;
        }
    }
}

harness_section('the scanned list is the one the theme and plugin contract names, repeats included');

$exports = array_values(array_map(
    static fn ($l) => substr($l, 7),
    array_filter($actual, static fn ($l) => strncmp($l, 'export ', 7) === 0)
));
pin('view exports', array(
    'search_uri', 'canonical', 'search_start', 'search_end', 'search_category', 'search_order_type',
    'search_order', 'search_pattern', 'search_from_user', 'search_total_pages', 'search_page',
    'search_has_pic', 'search_only_premium', 'search_country', 'search_region', 'search_city',
    'search_price_min', 'search_price_max', 'search_total_items', 'items', 'search_show_as', 'search',
    'search_alert', 'search_alert_subscribed', 'meta_noindex', 'canonical',
), $exports);

$hooks = array_values(array_map(
    static fn ($l) => substr($l, strpos($l, ' ') + 1),
    array_filter($actual, static fn ($l) => strncmp($l, 'export ', 7) !== 0)
));
pin('hooks and filters', array(
    'before_search', 'save_latest_searches_pattern', 'search_results', 'pre_show_items', 'search',
    'after_search', 'rss_feed_item', 'feed', 'feed_<named>', 'before_html', 'after_html',
), $hooks);

harness_section('SearchRunner::run() steps, in order');

// Swapping apply() and order() changes nothing a listener can see today, so this pins the
// order in the source: criteria, sort, page, then search_conditions, then the results.
preg_match_all(
    '/SearchBuilder::(apply|fireConditions)\b|\$search->(order|page|doSearch|count)\s*\(|\b(osc_cache_get|osc_cache_set|osc_prime_item_upgrades)\s*\(|osc_apply_filter\(\s*\'(search_results|pre_show_items)\'/',
    $classes['SearchRunner']['run'] ?? '',
    $steps,
    PREG_SET_ORDER
);
pin('the step sequence', array(
    'apply', 'order', 'page', 'page', 'fireConditions', 'search_results', 'osc_cache_get',
    'doSearch', 'count', 'osc_cache_set', 'pre_show_items', 'osc_prime_item_upgrades',
), array_map(static fn ($hit) => end($hit), $steps));

exit(harness_result());

/* file end: ./tests/search-exports-contract.php */
