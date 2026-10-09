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
 * No controller method grows past 100 lines: a long one is split into a method per action
 * or moved into a service. The ones that were longer when the rule began are listed in
 * tests/fixtures/long-controller-methods.txt; the list may only shrink.
 * DB-free.  Usage: php tests/controller-method-size.php [--write]
 */

require_once __DIR__ . '/lib/harness.php';

const LONG_FIXTURE = __DIR__ . '/fixtures/long-controller-methods.txt';
const LONG_LINES   = 100;

/**
 * Every method in PHP source with its length in lines, from `function` to its closing brace.
 *
 * @return array<string,int>
 */
function method_lengths(string $source): array
{
    $tokens  = token_get_all($source);
    $count   = count($tokens);
    $lengths = array();
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING) {
            continue; // a closure
        }
        $name  = $tokens[$j][1];
        $start = $tokens[$i][2];
        $depth = 0;
        for ($k = $j; $k < $count; $k++) {
            $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($text === ';' && $depth === 0) {
                break; // abstract
            }
            if ($text === '{' || (is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                $line = $start;
                for ($m = $i; $m <= $k; $m++) {
                    $line += is_array($tokens[$m]) ? substr_count($tokens[$m][1], "\n") : 0;
                }
                $lengths[$name] = $line - $start + 1;
                $i              = $k;
                break;
            }
        }
    }

    return $lengths;
}

$long = array();
foreach (harness_class_files('oc-includes/osclass/classes/controller') as $path => $source) {
    foreach (method_lengths($source) as $method => $lines) {
        if ($lines > LONG_LINES) {
            $long[] = $path . '::' . $method;
        }
    }
}

harness_section('controller methods stay short');
harness_shrink_only(LONG_FIXTURE, $long, 'method over ' . LONG_LINES . ' lines');
pin('the length of a known method is counted from function to its brace', 4, method_lengths(harness_class_files('oc-includes/osclass/classes/controller')['oc-includes/osclass/classes/controller/CWebItem.php'])['listingData'] ?? null);

exit(harness_result());
