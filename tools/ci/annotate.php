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
 * Merges the three JSON outputs the package-CI harness produces —
 * tools/package-lint.php, tools/ci/deprecation-scan.php, and
 * tools/ci/smoke-install.sh — into GitHub Actions annotations (printed to
 * stdout) and one sticky-comment markdown body (written to --comment-out).
 * The last job in a registry workflow runs this once per package after the
 * other three have produced their JSON, per docs/MARKET.md §6.2.
 *
 * Dependency-free, like the rest of this family: no bootstrap, no autoloader.
 *
 * Usage:
 *   php annotate.php --slug=<slug> --lint=<file.json> [--deprecations=<file.json>]
 *                     [--smoke=<file.json>] --comment-out=<file.md>
 *                     [--path-prefix=<prefix>]
 *
 * Exit codes: 0 = no error-level finding, 1 = at least one error-level finding.
 * Deprecation warnings never contribute to the exit code — see PACKAGE-SPEC.md §10.
 */

/**
 * @return array<string, ?string>
 */
function parseArgs(array $argv): array
{
    $options = [
        'slug'         => null,
        'lint'         => null,
        'deprecations' => null,
        'smoke'        => null,
        'comment-out'  => null,
        'path-prefix'  => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        foreach (array_keys($options) as $key) {
            $prefix = '--' . $key . '=';
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                continue 2;
            }
        }
        if ($arg === '-h' || $arg === '--help') {
            printUsage();
            exit(0);
        }
        fwrite(STDERR, "Unknown option: {$arg}\n");
    }

    return $options;
}

function printUsage(): void
{
    fwrite(
        STDERR,
        "Usage: php annotate.php --slug=<slug> --lint=<file.json> [--deprecations=<file.json>]\n"
        . "                 [--smoke=<file.json>] --comment-out=<file.md> [--path-prefix=<prefix>]\n"
    );
}

/**
 * @return array<string, mixed>|null
 */
function loadJson(?string $path): ?array
{
    if ($path === null || $path === '') {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Warning: could not read {$path}\n");

        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Warning: {$path} is not valid JSON\n");

        return null;
    }

    return $decoded;
}

/**
 * A finding normalised to one shape across all three input sources:
 * level ('error'|'warning'), gate, file, line, message.
 *
 * @return array{level:string, gate:string, file:?string, line:?int, message:string}
 */
function makeFinding(string $level, string $gate, ?string $file, ?int $line, string $message): array
{
    return ['level' => $level, 'gate' => $gate, 'file' => $file, 'line' => $line, 'message' => $message];
}

/**
 * @param array<string, mixed>|null $lint package-lint.php --json output
 * @return array<int, array<string,mixed>>
 */
function findingsFromLint(?array $lint): array
{
    if ($lint === null) {
        return [];
    }
    $out = [];
    foreach (($lint['errors'] ?? []) as $f) {
        $out[] = makeFinding('error', 'lint', $f['file'] ?? null, $f['line'] ?? null, (string) ($f['message'] ?? ''));
    }
    foreach (($lint['warnings'] ?? []) as $f) {
        $out[] = makeFinding('warning', 'lint', $f['file'] ?? null, $f['line'] ?? null, (string) ($f['message'] ?? ''));
    }

    return $out;
}

/**
 * @param array<string, mixed>|null $deprecations deprecation-scan.php --json output
 * @return array<int, array<string,mixed>>
 */
function findingsFromDeprecations(?array $deprecations): array
{
    if ($deprecations === null) {
        return [];
    }
    $out = [];
    foreach (($deprecations['warnings'] ?? []) as $f) {
        $out[] = makeFinding('warning', 'deprecations', $f['file'] ?? null, $f['line'] ?? null, (string) ($f['message'] ?? ''));
    }

    return $out;
}

/**
 * @param array<string, mixed>|null $smoke smoke-install.sh --out output
 * @return array<int, array<string,mixed>>
 */
function findingsFromSmoke(?array $smoke): array
{
    if ($smoke === null) {
        return [];
    }
    $out = [];

    foreach (($smoke['steps'] ?? []) as $step) {
        if (($step['ok'] ?? true) === false) {
            $name = (string) ($step['name'] ?? 'step');
            $detail = (string) ($step['detail'] ?? '');
            $out[] = makeFinding('error', 'smoke', null, null, "smoke install step \"{$name}\" failed" . ($detail !== '' ? ": {$detail}" : ''));
        }
    }

    foreach (($smoke['fatals'] ?? []) as $fatal) {
        $out[] = makeFinding('error', 'smoke', null, null, is_string($fatal) ? $fatal : (string) json_encode($fatal));
    }

    $unexpected = (string) ($smoke['unexpected_output'] ?? '');
    if ($unexpected !== '') {
        $out[] = makeFinding('error', 'smoke', null, null, 'The package generated unexpected output during install: ' . $unexpected);
    }

    foreach (($smoke['deprecations'] ?? []) as $d) {
        $symbol = (string) ($d['symbol'] ?? '');
        $message = (string) ($d['message'] ?? "`{$symbol}` is deprecated (seen at runtime during smoke install)");
        $out[] = makeFinding('warning', 'deprecations', $d['file'] ?? null, isset($d['line']) ? (int) $d['line'] : null, $message);
    }

    foreach (($smoke['tables_left'] ?? []) as $table) {
        $out[] = makeFinding('warning', 'smoke', null, null, "Uninstall left table `{$table}` behind.");
    }

    foreach (($smoke['prefs_left'] ?? []) as $pref) {
        $out[] = makeFinding('warning', 'smoke', null, null, "Uninstall left preference `{$pref}` behind.");
    }

    return $out;
}

function applyPathPrefix(?string $file, string $prefix): ?string
{
    if ($file === null || $file === '' || $prefix === '') {
        return $file;
    }
    // Absolute paths (already repo-relative from the root, or genuinely absolute)
    // are left alone — the prefix is only for a path relative to the package dir
    // the tool was pointed at.
    if ($file[0] === '/') {
        return $file;
    }

    return rtrim($prefix, '/') . '/' . $file;
}

/**
 * GitHub workflow-command escaping for a `::error`/`::warning` message body.
 * See https://docs.github.com/actions/using-workflows/workflow-commands-for-github-actions
 */
function escapeMessage(string $value): string
{
    return str_replace(["%", "\r", "\n"], ["%25", "%0D", "%0A"], $value);
}

/** Escaping for a property value (file=, line=, ...) — additionally escapes `:` and `,`. */
function escapeProperty(string $value): string
{
    return str_replace(["%", "\r", "\n", ":", ","], ["%25", "%0D", "%0A", "%3A", "%2C"], $value);
}

/**
 * @param array<int, array<string,mixed>> $findings
 */
function printAnnotations(array $findings, string $pathPrefix): void
{
    foreach ($findings as $f) {
        $command = $f['level'] === 'error' ? 'error' : 'warning';
        $props = [];
        $file = applyPathPrefix($f['file'], $pathPrefix);
        if ($file !== null && $file !== '') {
            $props[] = 'file=' . escapeProperty($file);
        }
        if ($f['line'] !== null) {
            $props[] = 'line=' . escapeProperty((string) $f['line']);
        }
        $propString = $props === [] ? '' : ' ' . implode(',', $props);
        echo "::{$command}{$propString}::" . escapeMessage($f['message']) . "\n";
    }
}

/**
 * @param array<int, array<string,mixed>> $findings
 * @return array{gate:string, status:string, errors:int, warnings:int}
 */
function summariseGate(string $gate, array $findings, bool $alwaysNonBlocking = false): array
{
    $errors = 0;
    $warnings = 0;
    foreach ($findings as $f) {
        if ($f['gate'] !== $gate) {
            continue;
        }
        if ($f['level'] === 'error') {
            $errors++;
        } else {
            $warnings++;
        }
    }

    if ($errors > 0 && !$alwaysNonBlocking) {
        $status = '❌';
    } elseif ($warnings > 0) {
        $status = '⚠️';
    } else {
        $status = '✅';
    }

    return ['gate' => $gate, 'status' => $status, 'errors' => $errors, 'warnings' => $warnings];
}

/**
 * @param array<int, array<string,mixed>> $findings
 */
function whereText(array $f, string $pathPrefix): string
{
    $file = applyPathPrefix($f['file'], $pathPrefix);
    if ($file === null || $file === '') {
        return '';
    }

    return $f['line'] !== null ? "`{$file}:{$f['line']}`" : "`{$file}`";
}

/**
 * @param array<int, array<string,mixed>> $findings
 */
function buildComment(string $slug, array $findings, string $pathPrefix): string
{
    $gates = [
        summariseGate('lint', $findings),
        summariseGate('deprecations', $findings, true),
        summariseGate('smoke', $findings),
    ];
    $gateLabels = ['lint' => 'Structure, manifest & security', 'deprecations' => 'Deprecated API', 'smoke' => 'Smoke install'];

    $errors = array_values(array_filter($findings, static fn ($f) => $f['level'] === 'error'));
    $warnings = array_values(array_filter($findings, static fn ($f) => $f['level'] === 'warning'));

    $lines = [];
    $lines[] = "## Package CI — `{$slug}`";
    $lines[] = '';
    $lines[] = '| Gate | Status | Errors | Warnings |';
    $lines[] = '|---|---|---|---|';
    foreach ($gates as $g) {
        $lines[] = sprintf('| %s | %s | %d | %d |', $gateLabels[$g['gate']], $g['status'], $g['errors'], $g['warnings']);
    }
    $lines[] = '';

    if ($errors === []) {
        $lines[] = '### Errors';
        $lines[] = '';
        $lines[] = 'None.';
    } else {
        $lines[] = '### Errors';
        $lines[] = '';
        foreach ($errors as $f) {
            $where = whereText($f, $pathPrefix);
            $lines[] = '- ' . ($where !== '' ? "{$where} — " : '') . $f['message'];
        }
    }
    $lines[] = '';

    $lines[] = '### Warnings — not blocking';
    $lines[] = '';
    $lines[] = '> These do not gate anything. A pull request may be merged with warnings outstanding — '
        . 'they exist to inform, not to reject.';
    $lines[] = '';
    if ($warnings === []) {
        $lines[] = 'None.';
    } else {
        foreach ($warnings as $f) {
            $where = whereText($f, $pathPrefix);
            $lines[] = '- ' . ($where !== '' ? "{$where} — " : '') . $f['message'];
        }
    }
    $lines[] = '';

    return implode("\n", $lines);
}

function main(array $argv): int
{
    $options = parseArgs($argv);

    if ($options['slug'] === null || $options['lint'] === null || $options['comment-out'] === null) {
        printUsage();

        return 2;
    }

    $lint = loadJson($options['lint']);
    $deprecations = loadJson($options['deprecations']);
    $smoke = loadJson($options['smoke']);

    $findings = array_merge(
        findingsFromLint($lint),
        findingsFromDeprecations($deprecations),
        findingsFromSmoke($smoke)
    );

    printAnnotations($findings, (string) $options['path-prefix']);

    $comment = buildComment((string) $options['slug'], $findings, (string) $options['path-prefix']);
    if (@file_put_contents($options['comment-out'], $comment . "\n") === false) {
        fwrite(STDERR, "Could not write comment markdown to {$options['comment-out']}\n");

        return 2;
    }

    $hasError = false;
    foreach ($findings as $f) {
        if ($f['level'] === 'error') {
            $hasError = true;
            break;
        }
    }

    return $hasError ? 1 : 0;
}

exit(main($argv));
