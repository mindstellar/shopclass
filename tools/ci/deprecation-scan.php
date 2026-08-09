<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Static half of the deprecated-API check (docs/MARKET.md §6.3): scans a package
 * directory for call sites of core functions/methods that `deprecated-api.json`
 * (scripts/gen-deprecated-api.mjs) says are deprecated, and for deprecated
 * hook/filter names passed to osc_add_hook()/osc_add_filter()/osc_apply_filter()/
 * osc_run_hook(). Sibling of tools/package-lint.php: same dependency-free,
 * bare-`php`, no-bootstrap posture, and the same line-scanning approach the
 * dangerous-construct scan there uses (not a real parser).
 *
 * This never fails a build. Deprecations are a schedule, not a rejection — see
 * PACKAGE-SPEC.md §10 — so this script always exits 0 and every finding is
 * reported as a warning, never an error.
 *
 * Usage:
 *   php deprecation-scan.php --inventory=deprecated-api.json [--json] <package-dir>
 */

const VENDORED_DIR_NAMES = ['vendor', 'third-party', 'thirdparty'];
const FORBIDDEN_DIR_NAMES = ['.git', 'node_modules'];

/** The three helpers a package can pass a hook/filter name to (PACKAGE-SPEC's own hook API). */
const HOOK_CALL_FUNCTIONS = ['osc_add_hook', 'osc_add_filter', 'osc_apply_filter', 'osc_run_hook'];

function printUsage(): void
{
    fwrite(STDERR, "Usage: php deprecation-scan.php --inventory=deprecated-api.json [--json] <package-dir>\n");
}

/**
 * @return array{inventory:?string, json:bool, dir:?string}
 */
function parseArgs(array $argv): array
{
    $options = ['inventory' => null, 'json' => false, 'dir' => null];
    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $options['json'] = true;
        } elseif (str_starts_with($arg, '--inventory=')) {
            $options['inventory'] = substr($arg, 12);
        } elseif ($arg === '-h' || $arg === '--help') {
            printUsage();
            exit(0);
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
        } else {
            $positional[] = $arg;
        }
    }

    if (count($positional) === 1) {
        $options['dir'] = $positional[0];
    }

    return $options;
}

/**
 * @return array{file:string, line:int, symbol:string, kind:string, since:?string, replacement:?string, removal:?string, message:string}
 */
function makeWarning(string $file, int $line, string $symbol, string $kind, array $entry): array
{
    $since = $entry['since'] ?? null;
    $replacement = $entry['replacement'] ?? null;
    $removal = $entry['removal'] ?? null;

    $message = sprintf('`%s` is deprecated', $symbol);
    $message .= $since ? " since {$since}" : ' (deprecation version unknown)';
    $message .= $removal ? ", scheduled for removal in {$removal}." : ', with no scheduled removal version yet.';
    $message .= $replacement ? " Use `{$replacement}` instead." : ' No replacement is available.';

    return [
        'file'        => $file,
        'line'        => $line,
        'symbol'      => $symbol,
        'kind'        => $kind,
        'since'       => $since,
        'replacement' => $replacement,
        'removal'     => $removal,
        'message'     => $message,
    ];
}

/**
 * Indexes a `deprecated-api.json` category array (functions/classes, or the merged
 * hooks+filters set) by name, dropping entries whose name is a runtime-templated
 * placeholder (e.g. "{$prefix}scripts_loaded") — those cannot be matched by a static
 * grep over source text because the string a package would pass never appears
 * literally in either the package or the inventory.
 *
 * @param array<int, array{name:string}> $entries
 * @return array<string, array<string,mixed>>
 */
function indexByName(array $entries): array
{
    $indexed = [];
    foreach ($entries as $entry) {
        $name = $entry['name'] ?? '';
        if ($name === '' || str_contains($name, '{')) {
            continue;
        }
        $indexed[$name] = $entry;
    }

    return $indexed;
}

/**
 * A package-relative path sits inside a vendored dependency tree the author did not
 * write. Skipped entirely — not merely downgraded — because a deprecation warning
 * about someone else's SDK is not actionable by the package author (same reasoning
 * PACKAGE-SPEC §9 gives for exempting vendored code from the security scan).
 */
function isVendoredPath(string $rel): bool
{
    $normalised = '/' . str_replace('\\', '/', $rel);
    foreach (VENDORED_DIR_NAMES as $dir) {
        if (str_contains($normalised, '/' . $dir . '/')) {
            return true;
        }
    }

    return false;
}

/** @return array<string,string> package-relative path => absolute path, PHP files only */
function walkPhpFiles(string $root): array
{
    $files = [];

    $visit = static function (string $dir) use (&$visit, &$files, $root): void {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $abs = $dir . '/' . $entry;
            $rel = ltrim(substr($abs, strlen($root)), '/');

            if (is_link($abs)) {
                continue;
            }

            if (is_dir($abs)) {
                if (in_array($entry, FORBIDDEN_DIR_NAMES, true) || in_array($entry, VENDORED_DIR_NAMES, true)) {
                    continue; // never descend into .git, node_modules, or a vendored tree
                }
                $visit($abs);
                continue;
            }

            if (strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) === 'php') {
                $files[$rel] = $abs;
            }
        }
    };

    $visit($root);

    return $files;
}

/**
 * Call-site scan for deprecated functions/methods. Deliberately conservative, in the
 * same spirit as package-lint's dangerous-construct scan: a plain, per-line regex over
 * raw source, not a parser, so it can miss a call split across lines or hidden behind
 * a variable, but it will not block a build either way — every hit here is a warning.
 *
 * A bare function name (no "::") matches any call to that name. A qualified
 * "Class::method" name matches only the literal "Class::method(" call — an instance
 * call through a variable (`$x->method()`) is not resolvable statically and is left
 * alone rather than guessed at, to avoid flooding a PR with false positives on common
 * method names.
 *
 * @param array<string,string>        $phpFiles keyed by package-relative path
 * @param array<string,array<string,mixed>> $functions keyed by symbol name
 * @return array<int, array<string,mixed>>
 */
function scanFunctionCalls(array $phpFiles, array $functions): array
{
    if ($functions === []) {
        return [];
    }

    $warnings = [];

    // Pre-split once: bare name => list of [fullSymbol, entry]; used as a fast
    // substring pre-filter before running the real regex on a matching line.
    $byBareName = [];
    foreach ($functions as $symbol => $entry) {
        $bare = str_contains($symbol, '::') ? substr($symbol, strrpos($symbol, '::') + 2) : $symbol;
        $byBareName[$bare][] = [$symbol, $entry];
    }

    foreach ($phpFiles as $rel => $abs) {
        if (isVendoredPath($rel)) {
            continue;
        }
        $lines = @file($abs, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $i => $line) {
            foreach ($byBareName as $bare => $candidates) {
                if (!str_contains($line, $bare)) {
                    continue;
                }
                foreach ($candidates as [$symbol, $entry]) {
                    $needle = str_contains($symbol, '::') ? $symbol : $bare;
                    $pattern = '/(?<![\w:])' . preg_quote($needle, '/') . '\s*\(/';
                    if (preg_match($pattern, $line)) {
                        $warnings[] = makeWarning($rel, $i + 1, $symbol, 'function', $entry);
                    }
                }
            }
        }
    }

    return $warnings;
}

/**
 * Scans for osc_add_hook()/osc_add_filter()/osc_apply_filter()/osc_run_hook() calls
 * whose first argument is a string literal naming a deprecated hook or filter.
 * A non-literal first argument (built from a variable or concatenation) cannot be
 * resolved statically and is silently skipped — the runtime collector (§6.3's second
 * layer) catches those instead.
 *
 * @param array<string,string> $phpFiles
 * @param array<string,array<string,mixed>> $hooksAndFilters keyed by name, each entry
 *        carrying which category ('hook'|'filter') it came from
 * @return array<int, array<string,mixed>>
 */
function scanHookCalls(array $phpFiles, array $hooksAndFilters): array
{
    if ($hooksAndFilters === []) {
        return [];
    }

    $warnings = [];
    $callAlternation = implode('|', HOOK_CALL_FUNCTIONS);
    $pattern = '/\b(?:' . $callAlternation . ')\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/';

    foreach ($phpFiles as $rel => $abs) {
        if (isVendoredPath($rel)) {
            continue;
        }
        $lines = @file($abs, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $i => $line) {
            if (!preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $m) {
                $name = stripcslashes($m[2]);
                if (isset($hooksAndFilters[$name])) {
                    $entry = $hooksAndFilters[$name];
                    $warnings[] = makeWarning($rel, $i + 1, $name, $entry['_kind'], $entry);
                }
            }
        }
    }

    return $warnings;
}

function main(array $argv): int
{
    $options = parseArgs($argv);

    if ($options['dir'] === null || $options['inventory'] === null) {
        printUsage();
        // Never fail the build over a usage mistake either — report an empty
        // result so a misconfigured caller does not take a warn-only gate down
        // with it, and let the human-readable stderr explain why it is empty.
        if ($options['json']) {
            echo json_encode(['warnings' => []]) . "\n";
        }

        return 0;
    }

    $root = realpath($options['dir']);
    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, "Not a directory: {$options['dir']}\n");
        if ($options['json']) {
            echo json_encode(['warnings' => []]) . "\n";
        }

        return 0;
    }

    $inventoryRaw = @file_get_contents($options['inventory']);
    if ($inventoryRaw === false) {
        fwrite(STDERR, "Cannot read inventory: {$options['inventory']}\n");
        if ($options['json']) {
            echo json_encode(['warnings' => []]) . "\n";
        }

        return 0;
    }

    $inventory = json_decode($inventoryRaw, true);
    if (!is_array($inventory)) {
        fwrite(STDERR, "Inventory is not valid JSON: {$options['inventory']}\n");
        if ($options['json']) {
            echo json_encode(['warnings' => []]) . "\n";
        }

        return 0;
    }

    $functions = indexByName(array_merge($inventory['functions'] ?? [], $inventory['classes'] ?? []));

    $hooksAndFilters = [];
    foreach (indexByName($inventory['hooks'] ?? []) as $name => $entry) {
        $hooksAndFilters[$name] = $entry + ['_kind' => 'hook'];
    }
    foreach (indexByName($inventory['filters'] ?? []) as $name => $entry) {
        // A name deprecated as both is vanishingly unlikely; hooks win ties since
        // osc_add_hook()/osc_run_hook() are the more common call shape.
        if (!isset($hooksAndFilters[$name])) {
            $hooksAndFilters[$name] = $entry + ['_kind' => 'filter'];
        }
    }

    $phpFiles = walkPhpFiles($root);

    $warnings = array_merge(
        scanFunctionCalls($phpFiles, $functions),
        scanHookCalls($phpFiles, $hooksAndFilters)
    );

    // Stable order: by file, then line, so re-running on unchanged source
    // produces byte-identical output.
    usort($warnings, static function ($a, $b) {
        return $a['file'] <=> $b['file'] ?: $a['line'] <=> $b['line'] ?: $a['symbol'] <=> $b['symbol'];
    });

    if ($options['json']) {
        echo json_encode(['warnings' => $warnings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        printf("deprecation-scan: %d warning(s)\n\n", count($warnings));
        foreach ($warnings as $w) {
            printf("  %s:%d — %s\n", $w['file'], $w['line'], $w['message']);
        }
    }

    // Deprecations never fail a build.
    return 0;
}

exit(main($argv));
