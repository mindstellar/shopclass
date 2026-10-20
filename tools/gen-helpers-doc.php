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
 * Regenerates the reference tables in docs/site/developers/helpers.md from the source.
 *
 * Only the region between the begin and end markers is rewritten; the prose above it is
 * hand-written. CI regenerates and fails on a diff, so the list cannot drift from core.
 *
 * Usage: php tools/gen-helpers-doc.php [--check]
 */

const ROOT  = __DIR__ . '/../';
const DOC   = ROOT . 'docs/site/developers/helpers.md';
const BEGIN = '<!-- generated:helpers -->';
const END   = '<!-- /generated:helpers -->';

require __DIR__ . '/lib/docgen.php';

// The installer, config loader and fallback page files are left out: they only load in
// their own context, so a plugin cannot rely on them.
const FILES = array(
    'oc-includes/osclass/alerts.php',
    'oc-includes/osclass/formatting.php',
    'oc-includes/osclass/functions.php',
    'oc-includes/osclass/locales.php',
    'oc-includes/osclass/utils.php',
);

/**
 * The first sentence of a docblock's summary, on one line.
 */
function helpers_summary(string $doc): string
{
    $lines = array();
    foreach (preg_split('/\R/', $doc) as $line) {
        $line = trim(preg_replace(array('#^\s*(/\*\*|\*/|\*)#', '#\*/\s*$#'), '', $line));
        if (strpos($line, '@') === 0) {
            break;
        }
        if ($line === '') {
            if ($lines) {
                break;
            }
            continue;
        }
        $lines[] = $line;
    }

    return preg_replace('/\{@(?:see|link) ([^}]+)\}/', '$1', implode(' ', $lines));
}

/**
 * Text for a table cell: pipes escaped, and angle brackets outside code spans turned into
 * entities so a summary naming a tag does not render as one.
 */
function helpers_cell(string $text): string
{
    $parts = explode('`', str_replace('|', '\\|', $text));
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $parts[$i] = str_replace(array('<', '>'), array('&lt;', '&gt;'), $part);
        }
    }

    return implode('`', $parts);
}

/**
 * Every global osc_* function in one file, as name => args, summary, and the deprecation
 * note (null when the helper is not deprecated).
 *
 * @return array<string,array{args:string,summary:string,deprecated:?string}>
 */
function helpers_scan_file(string $source): array
{
    $tokens = token_get_all($source);
    $count  = count($tokens);
    $found  = array();
    $doc    = '';
    $depth  = 0;
    // Methods are skipped: from `class`, `trait` or `interface` to the brace that closes it.
    $classOpening = false;
    $classDepth   = null;
    $prev         = null;
    for ($i = 0; $i < $count; $i++) {
        $t    = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        $type = is_array($t) ? $t[0] : null;

        if ($text === '{' || in_array($type, array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true)) {
            $depth++;
            if ($classOpening) {
                $classOpening = false;
                $classDepth   = $depth;
            }
        } elseif ($text === '}') {
            if ($depth-- === $classDepth) {
                $classDepth = null;
            }
        } elseif (in_array($type, array(T_CLASS, T_TRAIT, T_INTERFACE), true) && $prev !== T_DOUBLE_COLON && $classDepth === null) {
            $classOpening = true; // `Foo::class` names a class, it does not open one
        }
        if (!in_array($type, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $prev = $type ?? $text;
        }
        if ($classOpening || $classDepth !== null) {
            continue;
        }

        if (is_array($t) && $t[0] === T_DOC_COMMENT) {
            $doc = $t[1];
            continue;
        }
        if (!is_array($t) || $t[0] !== T_FUNCTION) {
            if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT), true)) {
                continue;
            }
            if (!is_array($t) || !in_array($t[0], array(T_STATIC, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_FINAL, T_ABSTRACT), true)) {
                $doc = '';
            }
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j < $count && $tokens[$j] === '&') {
            $j++;
        }
        $name = is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_STRING ? $tokens[$j][1] : '';
        if (strpos($name, 'osc_') !== 0 || isset($found[$name])) {
            $doc = '';
            continue;
        }

        // The parameter list, brackets balanced.
        $args  = '';
        $level = 0;
        for ($k = $j + 1; $k < $count; $k++) {
            $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($text === '(') {
                if ($level++ === 0) {
                    continue;
                }
            } elseif ($text === ')' && --$level === 0) {
                break;
            }
            $args .= $text;
        }

        $found[$name] = array(
            'args'       => trim(preg_replace('/\s+/', ' ', $args)),
            'summary'    => helpers_summary($doc),
            'deprecated' => preg_match('/@deprecated\b[ \t]*(.*)/', $doc, $m) ? trim($m[1]) : null,
        );
        $doc = '';
    }

    return $found;
}

/**
 * Every helper, grouped by the file that defines it.
 *
 * @return array<string,array<string,array{args:string,summary:string,deprecated:?string}>>
 */
function helpers_scan(): array
{
    $files = glob(ROOT . 'oc-includes/osclass/helpers/*.php');
    $files = array_merge(
        array_map(static fn ($f) => 'oc-includes/osclass/helpers/' . basename($f), $files),
        FILES
    );
    sort($files);

    $groups = array();
    foreach ($files as $path) {
        $found = helpers_scan_file(file_get_contents(ROOT . $path));
        if ($found) {
            ksort($found);
            $groups[$path] = $found;
        }
    }

    return $groups;
}

$groups     = helpers_scan();
$total      = 0;
$deprecated = 0;
foreach ($groups as $found) {
    $total += count($found);
    foreach ($found as $info) {
        $deprecated += $info['deprecated'] === null ? 0 : 1;
    }
}

$out   = array(BEGIN, '');
$out[] = 'Core defines ' . $total . ' helpers, ' . $deprecated . ' of them deprecated. '
    . 'Generated from the source; do not edit by hand.';
$out[] = '';

foreach ($groups as $path => $found) {
    $out[] = '### ' . basename($path, '.php') . ' (' . count($found) . ')';
    $out[] = '';
    $out[] = '`' . $path . '`';
    $out[] = '';
    $out[] = '| Helper | What it does |';
    $out[] = '|---|---|';
    foreach ($found as $name => $info) {
        $call    = '`' . str_replace('|', '\\|', $name . '(' . $info['args'] . ')') . '`';
        $summary = helpers_cell($info['summary']);
        if ($info['deprecated'] !== null) {
            $note    = rtrim(helpers_cell($info['deprecated']), '.');
            $summary = '**Deprecated' . ($note === '' ? '' : ' ' . $note) . '.** ' . $summary;
        }
        $out[] = '| ' . $call . ' | ' . trim($summary) . ' |';
    }
    $out[] = '';
}

$out[] = END;
$block = implode("\n", $out);

exit(docgen_splice(DOC, BEGIN, END, $block, 'tools/gen-helpers-doc.php', $argv, 'Wrote ' . $total . ' helpers to docs/site/developers/helpers.md'));
