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
 * Regenerates the reference table in docs/site/developers/hooks.md from the source.
 *
 * Only the region between the begin and end markers is rewritten; the prose above it is
 * hand-written. CI regenerates and fails on a diff, so the table cannot drift from what
 * core actually fires.
 *
 * Usage: php tools/gen-hooks-doc.php [--check]
 */

const ROOT   = __DIR__ . '/../';
const DOC    = ROOT . 'docs/site/developers/hooks.md';
const BEGIN  = '<!-- generated:hooks -->';
const END    = '<!-- /generated:hooks -->';
const ROOTS  = array('oc-includes/osclass', 'oc-admin');

/**
 * Text between the parentheses of a call starting at $open, brackets balanced.
 */
function hooks_call_args(string $src, int $open): string
{
    $depth = 0;
    $len   = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];
        if ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open + 1, $i - $open - 1);
            }
        }
    }

    return '';
}

/**
 * Every fired hook, as name => ['kind' => …, 'args' => […], 'where' => 'path:line'].
 *
 * @return array<string,array{kind:string,args:string,where:string}>
 */
function hooks_scan(): array
{
    $found = array();

    foreach (ROOTS as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . $dir));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace(realpath(ROOT) . '/', '', realpath($file->getPathname()));
            $src  = file_get_contents($file->getPathname());

            // The trailing check rejects a name built by concatenation: only the literal
            // prefix would be captured, and the real name cannot be documented anyway.
            $re = '/(osc_run_hook|Plugins::runHook|osc_apply_filter|Plugins::applyFilter)'
                . '\s*\(\s*([\'"])([a-zA-Z0-9_]+)\2\s*(?![.\s]*\.)/';
            if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[3] as $i => $hit) {
                $name = $hit[0];
                if (isset($found[$name])) {
                    continue;
                }
                $fn    = $m[1][$i][0];
                $start = $m[0][$i][1];
                $open  = strpos($src, '(', $start);
                $args  = hooks_call_args($src, $open);

                // Drop the hook name itself; what is left is what a callback receives.
                $args = preg_replace('/^\s*([\'"])' . preg_quote($name, '/') . '\1\s*,?\s*/', '', $args);
                $args = trim(preg_replace('/\s+/', ' ', $args));

                $found[$name] = array(
                    'kind'  => (stripos($fn, 'filter') !== false) ? 'filter' : 'action',
                    'args'  => $args,
                    'where' => $path . ':' . (substr_count(substr($src, 0, $start), "\n") + 1),
                );
            }
        }
    }

    ksort($found);

    return $found;
}

/**
 * The family a name belongs to, used only to group the table.
 */
function hooks_family(string $name): string
{
    if (strpos($name, 'admin_') === 0 || strpos($name, 'init_admin') === 0) {
        return 'Admin';
    }
    if (strpos($name, 'hook_email') === 0 || strpos($name, 'email_') === 0) {
        return 'Email';
    }
    foreach (array('item', 'user', 'category', 'comment', 'plugin', 'theme', 'search') as $subject) {
        if (strpos($name, $subject) !== false) {
            return ucfirst($subject);
        }
    }

    return 'Other';
}

$hooks    = hooks_scan();
$families = array();
foreach ($hooks as $name => $info) {
    $families[hooks_family($name)][$name] = $info;
}
ksort($families);

$out = array(BEGIN, '');
$out[] = 'Core fires ' . count($hooks) . ' names. Generated from the source; do not edit by hand.';
$out[] = '';

foreach ($families as $family => $names) {
    $out[] = '### ' . $family . ' (' . count($names) . ')';
    $out[] = '';
    $out[] = '| Name | Kind | Arguments | Fired at |';
    $out[] = '|---|---|---|---|';
    foreach ($names as $name => $info) {
        $args = $info['args'] === '' ? '—' : '`' . str_replace('|', '\\|', $info['args']) . '`';
        $out[] = '| `' . $name . '` | ' . $info['kind'] . ' | ' . $args . ' | `' . $info['where'] . '` |';
    }
    $out[] = '';
}

$out[] = END;
$block = implode("\n", $out);

$doc = file_get_contents(DOC);
$a   = strpos($doc, BEGIN);
$b   = strpos($doc, END);
if ($a === false || $b === false) {
    fwrite(STDERR, "Markers missing in " . DOC . "\n");
    exit(1);
}
$updated = substr($doc, 0, $a) . $block . substr($doc, $b + strlen(END));

if (in_array('--check', $argv, true)) {
    if ($updated !== $doc) {
        fwrite(STDERR, "docs/site/developers/hooks.md is stale. Run: php tools/gen-hooks-doc.php\n");
        exit(1);
    }
    echo "hooks.md matches the source.\n";
    exit(0);
}

file_put_contents(DOC, $updated);
echo 'Wrote ' . count($hooks) . " hooks to docs/site/developers/hooks.md\n";
