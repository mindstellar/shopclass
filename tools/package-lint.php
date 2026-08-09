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
 * Standalone validator for a plugin or theme package directory against
 * docs/PACKAGE-SPEC.md. This is the single implementation of the package
 * contract (docs/MARKET.md §6.4): the registry CI downloads this exact file
 * from a core release and runs it, rather than keeping a second copy that
 * could drift and pass packages core would reject.
 *
 * Deliberately dependency-free: no bootstrap, no database, no composer
 * autoloader, no core constants. It runs on a bare `php` binary in a runner
 * that does not have the rest of this repository checked out. The one
 * exception is Compatibility.php (see --compat below), which is itself
 * dependency-free apart from a __() call a passthrough shim satisfies here.
 *
 * Usage:
 *   php tools/package-lint.php --type=plugin --core=6.0.3 [--json] [--compat=PATH]
 *       [--core-versions=PATH] <package-dir>
 *
 * --core-versions points at a JSON file listing every known Shopclass core
 * release (a flat array of version strings, or an object with a "versions"
 * array — prereleases included) and enables the version-existence checks in
 * §4: "Tested up to" naming a version ahead of the newest known release, and
 * "Requires Shopclass" naming a version that never existed. This is
 * deliberately not fetched over the network by this script — callers that
 * have one (registry CI does) pass it; a contributor running this offline
 * from a downloaded release asset gets neither the flag nor the file, and
 * those two checks are silently skipped rather than failing or guessing.
 * The SHOPCLASS_CORE_VERSIONS environment variable is an equivalent way to
 * point at the same file when passing a flag isn't convenient.
 *
 * Exit codes: 0 = no errors (warnings do not fail a build), 1 = errors found,
 * 2 = usage error.
 */

const MAX_UNPACKED_BYTES = 50 * 1024 * 1024;
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const FORBIDDEN_DIR_NAMES = ['.git', 'node_modules'];
const VENDORED_DIR_NAMES = ['vendor', 'third-party', 'thirdparty'];
const WARN_UNPACKED_BYTES = 15 * 1024 * 1024;
const FORBIDDEN_EXTENSIONS = ['exe', 'dll', 'so'];
const SHELL_FUNCTIONS = ['system', 'exec', 'shell_exec', 'passthru', 'proc_open'];

/** Core's own PHP floor (PACKAGE-SPEC §9): "Requires PHP" below this gates nobody. */
const CORE_PHP_FLOOR = '8.0';
/** Newest PHP core's CI itself tests against (PACKAGE-SPEC §8: "php -l across 8.0-8.5"). */
const CORE_PHP_CEILING = '8.5';

/** Header fields recognised in a plugin's index.php, per PACKAGE-SPEC §3.2. */
const PLUGIN_FIELDS = [
    'plugin_name'       => 'Plugin Name',
    'plugin_uri'        => 'Plugin URI',
    'plugin_update_uri' => 'Plugin update URI',
    'support_uri'       => 'Support URI',
    'description'       => 'Description',
    'version'           => 'Version',
    'author'            => 'Author',
    'author_uri'        => 'Author URI',
    'short_name'        => 'Short Name',
    'requires'          => 'Requires Shopclass',
    'tested_up_to'      => 'Tested up to',
    'requires_php'      => 'Requires PHP',
];

/** Header fields recognised in a theme's index.php, per PACKAGE-SPEC §3.3. */
const THEME_FIELDS = [
    'name'             => 'Theme Name',
    'template'         => 'Parent Theme',
    'theme_uri'        => 'Theme URI',
    'theme_update_uri' => 'Theme update URI',
    'description'      => 'Description',
    'version'          => 'Version',
    'author_name'      => 'Author',
    'author_url'       => 'Author URI',
    'locations'        => 'Widgets',
    'requires'         => 'Requires Shopclass',
    'tested_up_to'     => 'Tested up to',
    'requires_php'     => 'Requires PHP',
];

/**
 * @return array{level:string, code:string, file:?string, line:?int, message:string}
 */
function finding(string $level, string $code, ?string $file, ?int $line, string $message): array
{
    return ['level' => $level, 'code' => $code, 'file' => $file, 'line' => $line, 'message' => $message];
}

function printUsage(): void
{
    fwrite(
        STDERR,
        "Usage: php tools/package-lint.php --type=plugin|theme --core=X.Y.Z [--json] [--compat=PATH]\n"
        . "           [--core-versions=PATH] <package-dir>\n"
    );
}

/**
 * @return array{type:?string, core:?string, json:bool, compat:?string, coreVersions:?string, dir:?string}
 */
function parseArgs(array $argv): array
{
    $options = ['type' => null, 'core' => null, 'json' => false, 'compat' => null, 'coreVersions' => null, 'dir' => null];
    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $options['json'] = true;
        } elseif (str_starts_with($arg, '--type=')) {
            $options['type'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--core=')) {
            $options['core'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--compat=')) {
            $options['compat'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--core-versions=')) {
            $options['coreVersions'] = substr($arg, 16);
        } elseif ($arg === '-h' || $arg === '--help') {
            printUsage();
            exit(0);
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(2);
        } else {
            $positional[] = $arg;
        }
    }

    if (count($positional) === 1) {
        $options['dir'] = $positional[0];
    }

    return $options;
}

/* ------------------------------------------------------------------ *
 * Header parsing — mirrors Plugins::getInfo() / WebThemes::loadThemeInfo()
 * exactly. The regex shape, the trim(), and the "absent -> empty string"
 * default are reproduced verbatim; this is what core will actually do with
 * the file, not an improved parse of it.
 * ------------------------------------------------------------------ */

/**
 * The first `Field:` match anywhere in $contents, trimmed, or null if the
 * field never occurs at all.
 */
function matchField(string $contents, string $label): ?string
{
    if (preg_match('|' . preg_quote($label, '|') . ':([^\r\t\n]*)|i', $contents, $m)) {
        return trim($m[1]);
    }

    return null;
}

/** @return array<string,string> */
function parseHeader(string $contents, array $fieldMap): array
{
    $info = [];
    foreach ($fieldMap as $key => $label) {
        $info[$key] = matchField($contents, $label) ?? '';
    }

    return $info;
}

/** Byte offset within $contents of every occurrence of `Field:`, in match order. */
function fieldOffsets(string $contents, string $label): array
{
    preg_match_all('|' . preg_quote($label, '|') . ':([^\r\t\n]*)|i', $contents, $m, PREG_OFFSET_CAPTURE);

    return array_map(static fn ($hit) => $hit[1], $m[0]);
}

function lineFromOffset(string $contents, int $offset): int
{
    return substr_count($contents, "\n", 0, $offset) + 1;
}

/** Every `/* ... *‍/` span in the file as [startOffset, endOffsetExclusive]. */
function commentBlocks(string $contents): array
{
    preg_match_all('#/\*.*?\*/#s', $contents, $m, PREG_OFFSET_CAPTURE);
    $spans = [];
    foreach ($m[0] as [$text, $offset]) {
        $spans[] = [$offset, $offset + strlen($text)];
    }

    return $spans;
}

/**
 * The header block is wherever the identity field (Plugin Name / Theme Name)
 * is actually declared — not necessarily the file's first comment. Themes and
 * plugins routinely lead with a license banner comment before the header
 * (see oc-content/themes/bender/index.php), which is harmless as long as the
 * banner does not itself contain a colliding field name; anchoring on the
 * identity field's own comment avoids flagging that ordinary layout as a
 * hijack while still catching a field with no legitimate declaration at all.
 */
function leadingCommentBlock(string $contents, string $identityLabel): ?array
{
    $offsets = fieldOffsets($contents, $identityLabel);
    if ($offsets === []) {
        return null;
    }
    $anchor = $offsets[0];

    foreach (commentBlocks($contents) as $span) {
        if ($anchor >= $span[0] && $anchor < $span[1]) {
            return $span;
        }
    }

    return null;
}

/**
 * PACKAGE-SPEC §3.1: core's header regex is a substring search over the
 * whole file, not a parse of the comment block, so a field name occurring
 * earlier than the real declaration silently wins, and it always uses the
 * first match regardless of how many times the field name occurs. Both
 * failure modes are structural, hence errors rather than warnings.
 *
 * @return array<int, array{level:string, code:string, file:?string, line:?int, message:string}>
 */
function checkHeaderHijack(string $contents, ?array $leadingBlock, array $fieldMap, string $relFile): array
{
    $findings = [];

    foreach ($fieldMap as $label) {
        $offsets = fieldOffsets($contents, $label);
        if ($offsets === []) {
            continue;
        }

        $firstOffset = $offsets[0];
        $firstLine = lineFromOffset($contents, $firstOffset);

        $insideBlock = $leadingBlock !== null && $firstOffset >= $leadingBlock[0] && $firstOffset < $leadingBlock[1];
        if (!$insideBlock) {
            $findings[] = finding(
                'error',
                'HEADER_FIELD_HIJACKED',
                $relFile,
                $firstLine,
                "\"{$label}:\" is only found outside the leading header comment block; " .
                    'core parses the first match anywhere in the file, so this will not read as a declared header field.'
            );
        }

        if (count($offsets) > 1) {
            $findings[] = finding(
                'error',
                'HEADER_FIELD_DUPLICATE',
                $relFile,
                $firstLine,
                "\"{$label}:\" matches " . count($offsets) . ' times in this file. Core always parses the first ' .
                    "match (line {$firstLine}) — check for a longer label such as \"API {$label}:\" appearing " .
                    'earlier, or a genuine duplicate declaration.'
            );
        }
    }

    return $findings;
}

/* ------------------------------------------------------------------ *
 * Versioning — PACKAGE-SPEC §5.
 * ------------------------------------------------------------------ */

function checkVersion(string $version, string $relFile): array
{
    $findings = [];
    if ($version === '') {
        return $findings; // covered by the required-field check
    }

    if (preg_match('/^v/i', $version)) {
        $findings[] = finding(
            'error',
            'VERSION_LEADING_V',
            $relFile,
            null,
            "Version \"{$version}\" is prefixed with \"v\" — the header value must not be (tags may be, the header may not)."
        );

        return $findings;
    }

    if (!preg_match('/^\d+(\.\d+)*(?:[-.](?:dev|alpha|beta|rc)\d*)?$/i', $version)) {
        $findings[] = finding(
            'error',
            'VERSION_INVALID',
            $relFile,
            null,
            "Version \"{$version}\" does not look like a version version_compare() can order " .
                '(expected e.g. "1.2.0" or "1.2.0-beta1").'
        );
    }

    return $findings;
}

/* ------------------------------------------------------------------ *
 * Compatibility range — PACKAGE-SPEC §4. Submission-time strictness: these
 * checks catch a header that declares an impossible or nonexistent range,
 * something a human/CI reviewing the PR can fix on the spot. They never
 * touch how core evaluates compatibility at runtime (Compatibility::evaluate()
 * stays permissive for anything merely untested).
 * ------------------------------------------------------------------ */

/** Leading "v", surrounding whitespace, and any trailing junk stripped; null if nothing numeric remains. */
function bareVersion(string $v): ?string
{
    $v = ltrim(trim($v), 'vV');
    if (!preg_match('/^(\d+(?:\.\d+)*)/', $v, $m)) {
        return null;
    }

    return $m[1];
}

/**
 * First two dot-separated segments, zero-padded — e.g. "6" -> "6.0", "6.1.5" -> "6.1".
 * Mirrors Compatibility::minor()'s precision, since "Tested up to" is declared and
 * evaluated at minor precision everywhere else in this system; comparing at full
 * precision here would flag the ordinary, documented pattern of `Requires Shopclass:
 * 6.1.5` alongside `Tested up to: 6.1` as a contradiction when it is not one.
 */
function minorPrecision(string $v): ?string
{
    $bare = bareVersion($v);
    if ($bare === null) {
        return null;
    }
    $parts = explode('.', $bare);

    return $parts[0] . '.' . ($parts[1] ?? '0');
}

/**
 * Whether a declared version corresponds to a real core release: an exact match, or a
 * prefix of one (so "6.1" is considered to exist once any "6.1.x" release has shipped —
 * authors are not required to name a specific patch).
 *
 * @param string[] $knownVersions
 */
function versionExistsInList(string $bareVersion, array $knownVersions): bool
{
    foreach ($knownVersions as $known) {
        $known = ltrim(trim($known), 'vV');
        if ($known === $bareVersion || str_starts_with($known, $bareVersion . '.') || str_starts_with($known, $bareVersion . '-')) {
            return true;
        }
    }

    return false;
}

/**
 * Loads the optional core-versions list: --core-versions=PATH, else the
 * SHOPCLASS_CORE_VERSIONS environment variable, else null (checks that need it are
 * skipped). Accepts a flat JSON array of version strings, or an object carrying one
 * under a "versions" key. A present-but-unusable file degrades the same way as an
 * absent one — a warning to stderr, never a hard failure of the whole run.
 *
 * @return string[]|null
 */
function loadCoreVersions(?string $flagPath): ?array
{
    $path = $flagPath;
    if ($path === null) {
        $env = getenv('SHOPCLASS_CORE_VERSIONS');
        $path = ($env !== false && $env !== '') ? $env : null;
    }
    if ($path === null) {
        return null;
    }
    if (!is_file($path)) {
        fwrite(STDERR, "Warning: --core-versions file not found: {$path} — skipping version-existence checks.\n");

        return null;
    }

    $raw = @file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['versions']) && is_array($decoded['versions'])) {
        $decoded = $decoded['versions'];
    }
    if (!is_array($decoded)) {
        fwrite(STDERR, "Warning: --core-versions file at {$path} is not a JSON array (or {\"versions\": [...]})" . " — skipping version-existence checks.\n");

        return null;
    }

    $versions = array_values(array_filter(array_map(
        static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null,
        $decoded
    )));

    if ($versions === []) {
        fwrite(STDERR, "Warning: --core-versions file at {$path} names no versions — skipping version-existence checks.\n");

        return null;
    }

    return $versions;
}

/** Highest entry by version_compare(), prereleases included — same "genuinely newest" rule catalog.yml resolves with. */
function newestVersion(array $versions): string
{
    $sorted = $versions;
    usort($sorted, static fn ($a, $b) => version_compare($b, $a));

    return $sorted[0];
}

/* ------------------------------------------------------------------ *
 * Structure — PACKAGE-SPEC §2, MARKET.md §6.2 gate 1.
 * ------------------------------------------------------------------ */

/**
 * @return array{files:array<string,array{abs:string,size:int}>, findings:array}
 */
function walkPackage(string $root): array
{
    $files = [];
    $findings = [];
    $totalSize = 0;

    $visit = static function (string $dir) use (&$visit, &$files, &$findings, &$totalSize, $root): void {
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
                $findings[] = finding('error', 'STRUCTURE_SYMLINK', $rel, null, 'Symlinks are not permitted in a distributed package.');
                continue; // never follow — could cycle back into an ancestor
            }

            if (is_dir($abs)) {
                if (in_array($entry, FORBIDDEN_DIR_NAMES, true)) {
                    $findings[] = finding(
                        'error',
                        'STRUCTURE_FORBIDDEN_PATH',
                        $rel,
                        null,
                        "\"{$entry}/\" must not be included in a distributed package."
                    );
                    continue; // do not descend — especially not into .git
                }
                $visit($abs);
                continue;
            }

            $size = @filesize($abs);
            $size = $size === false ? 0 : $size;
            $totalSize += $size;
            $files[$rel] = ['abs' => $abs, 'size' => $size];

            if ($size > MAX_FILE_BYTES) {
                $findings[] = finding(
                    'error',
                    'STRUCTURE_FILE_TOO_LARGE',
                    $rel,
                    null,
                    sprintf('%.2f MB exceeds the 5 MB per-file limit.', $size / 1024 / 1024)
                );
            }

            $ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, FORBIDDEN_EXTENSIONS, true)) {
                $findings[] = finding(
                    'error',
                    'STRUCTURE_FORBIDDEN_EXTENSION',
                    $rel,
                    null,
                    "\".{$ext}\" binaries are not permitted in a distributed package."
                );
            }
        }
    };

    $visit($root);

    // Graduated: a plugin bundling a real SDK legitimately runs to tens of MB, so the
    // recommended ceiling only warns and the hard cap is what blocks a merge.
    if ($totalSize > MAX_UNPACKED_BYTES) {
        $findings[] = finding(
            'error',
            'STRUCTURE_TOO_LARGE',
            null,
            null,
            sprintf(
                'Unpacked size %.2f MB exceeds the %d MB hard limit.',
                $totalSize / 1024 / 1024,
                (int) (MAX_UNPACKED_BYTES / 1024 / 1024)
            )
        );
    } elseif ($totalSize > WARN_UNPACKED_BYTES) {
        $findings[] = finding(
            'warning',
            'STRUCTURE_LARGE',
            null,
            null,
            sprintf(
                'Unpacked size %.2f MB is over the %d MB recommended ceiling — consider trimming vendored dependencies.',
                $totalSize / 1024 / 1024,
                (int) (WARN_UNPACKED_BYTES / 1024 / 1024)
            )
        );
    }

    return ['files' => $files, 'findings' => $findings];
}

/* ------------------------------------------------------------------ *
 * Security — PACKAGE-SPEC §9.
 * ------------------------------------------------------------------ */

/**
 * Whether a package-relative path sits inside a third-party dependency tree. A construct
 * an author did not write is reported but never blocks: the fix is upstream's, and a
 * plugin bundling an SDK cannot be asked to patch it before merging.
 */
function isVendoredPath(string $rel): bool
{
    foreach (VENDORED_DIR_NAMES as $dir) {
        if (strpos('/' . str_replace('\\', '/', $rel), '/' . $dir . '/') !== false) {
            return true;
        }
    }

    return false;
}

/** @param array<string,array{abs:string,size:int}> $phpFiles keyed by package-relative path */
function scanDangerousConstructs(array $phpFiles): array
{
    $findings = [];

    foreach ($phpFiles as $rel => $file) {
        $lines = @file($file['abs'], FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        $vendored = isVendoredPath($rel);
        $level    = $vendored ? 'warning' : 'error';
        $note     = $vendored ? ' (in vendored third-party code — reported, not blocking)' : '';

        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;

            if (preg_match('/\beval\s*\(/', $line)) {
                $findings[] = finding($level, 'DANGEROUS_EVAL', $rel, $lineNo, 'eval() is not permitted in a distributed package.' . $note);
            }
            if (preg_match('/\bassert\s*\(\s*[\'"]/', $line)) {
                $findings[] = finding(
                    $level,
                    'DANGEROUS_ASSERT_STRING',
                    $rel,
                    $lineNo,
                    'assert() called with a string literal — PHP evaluates a string assertion as code; pass a boolean instead.' . $note
                );
            }
            if (preg_match('/\bcreate_function\s*\(/', $line)) {
                $findings[] = finding(
                    $level,
                    'DANGEROUS_CREATE_FUNCTION',
                    $rel,
                    $lineNo,
                    'create_function() is not permitted (removed in PHP 8; historically an eval() wrapper — use a closure).' . $note
                );
            }
            foreach (SHELL_FUNCTIONS as $fn) {
                if (preg_match('/\b' . $fn . '\s*\(/', $line)) {
                    $findings[] = finding($level, 'DANGEROUS_SHELL_EXEC', $rel, $lineNo, "{$fn}() is not permitted in a distributed package." . $note);
                }
            }
            if (preg_match('/\bbase64_decode\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/', $line, $m) && strlen($m[2]) > 200) {
                $findings[] = finding(
                    $level,
                    'DANGEROUS_BASE64_LITERAL',
                    $rel,
                    $lineNo,
                    'base64_decode() of a literal longer than 200 characters — a common payload-obfuscation pattern.' . $note
                );
            }
        }
    }

    return $findings;
}

/**
 * PACKAGE-SPEC §9 second group. Heuristics, deliberately conservative: each
 * message says so, and every one of these is a warning, never an error.
 *
 * @param array<string,array{abs:string,size:int}> $phpFiles
 */
function scanSecurityHeuristics(array $phpFiles): array
{
    $findings = [];

    foreach ($phpFiles as $rel => $file) {
        $lines = @file($file['abs'], FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        if (isVendoredPath($rel)) {
            continue;
        }

        $hasPost = false;
        $hasCsrfCheck = false;

        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;

            if (preg_match('/\$_(GET|POST|REQUEST)\b/', $line)) {
                $findings[] = finding(
                    'warning',
                    'RAW_SUPERGLOBAL',
                    $rel,
                    $lineNo,
                    'Heuristic: direct superglobal access — prefer Params::getParamInt()/getParamString()/getParamArray().'
                );
                if (preg_match('/\$_POST\b/', $line)) {
                    $hasPost = true;
                }
            }
            if (str_contains($line, 'osc_csrf_check(')) {
                $hasCsrfCheck = true;
            }
            if (
                preg_match('/\becho\b/', $line)
                && preg_match('/\$_(GET|POST|REQUEST)\b/', $line)
                && !preg_match('/osc_esc_(html|js)|htmlspecialchars|htmlentities/', $line)
            ) {
                $findings[] = finding(
                    'warning',
                    'UNESCAPED_ECHO_HEURISTIC',
                    $rel,
                    $lineNo,
                    'Heuristic: echo of apparently-unescaped request data — wrap in osc_esc_html() / osc_esc_js().'
                );
            }
        }

        if ($hasPost && !$hasCsrfCheck) {
            $findings[] = finding(
                'warning',
                'CSRF_MISSING_HEURISTIC',
                $rel,
                null,
                'Heuristic: this file reads $_POST but no osc_csrf_check() call was found in it — verify the ' .
                    'state-changing action is CSRF-protected (it may be checked in an included file).'
            );
        }
    }

    return $findings;
}

/* ------------------------------------------------------------------ *
 * Main
 * ------------------------------------------------------------------ */

function main(array $argv): int
{
    $options = parseArgs($argv);

    if ($options['dir'] === null || !in_array($options['type'], ['plugin', 'theme'], true) || $options['core'] === null) {
        printUsage();

        return 2;
    }

    $root = realpath($options['dir']);
    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, "Not a directory: {$options['dir']}\n");

        return 2;
    }

    // Beside-itself first: both files are published as release assets, so a contributor
    // who downloaded the pair into one directory needs no --compat at all.
    $compatPath = $options['compat'];
    if ($compatPath === null) {
        foreach ([__DIR__ . '/Compatibility.php',
                  __DIR__ . '/../oc-includes/osclass/classes/market/Compatibility.php'] as $candidate) {
            if (is_file($candidate)) {
                $compatPath = $candidate;
                break;
            }
        }
    }
    if ($compatPath === null || !is_file($compatPath)) {
        fwrite(
            STDERR,
            "Cannot find Compatibility.php. Put it beside this script, or pass --compat=PATH.\n"
            . "Both files are attached to every Shopclass release.\n"
        );

        return 2;
    }
    // Compatibility.php's only external call is __(); this is a bare `php`
    // process with no bootstrap, so it does not exist yet.
    if (!function_exists('__')) {
        function __($text, $domain = 'default')
        {
            return $text;
        }
    }
    require_once $compatPath;

    $type = $options['type'];
    $coreVersion = $options['core'];
    $fieldMap = $type === 'plugin' ? PLUGIN_FIELDS : THEME_FIELDS;
    $findings = [];
    $knownCoreVersions = loadCoreVersions($options['coreVersions']);

    // --- Structure ----------------------------------------------------
    $slug = basename($root);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
        $findings[] = finding(
            'error',
            'SLUG_INVALID',
            null,
            null,
            "Directory name \"{$slug}\" does not match ^[a-z0-9][a-z0-9-]{1,40}$."
        );
    }

    $walked = walkPackage($root);
    $findings = array_merge($findings, $walked['findings']);
    $files = $walked['files'];

    $requiredFiles = $type === 'plugin' ? ['index.php', 'LICENSE'] : ['index.php', 'LICENSE', 'screenshot.png'];
    foreach ($requiredFiles as $required) {
        if (!isset($files[$required])) {
            $findings[] = finding('error', 'MISSING_REQUIRED_FILE', $required, null, "Required file \"{$required}\" is missing.");
        }
    }

    foreach (['README.md', 'CHANGELOG.md'] as $recommended) {
        if (!isset($files[$recommended])) {
            $findings[] = finding(
                'warning',
                'MISSING_RECOMMENDED_FILE',
                $recommended,
                null,
                "\"{$recommended}\" is missing. Recommended, not required."
            );
        }
    }

    if ($type === 'plugin') {
        $hasIcon = isset($files['assets/icon.svg']) || isset($files['assets/icon.png']);
        $hasScreenshot = false;
        foreach (array_keys($files) as $relPath) {
            if (preg_match('#^assets/screenshot-#', $relPath)) {
                $hasScreenshot = true;
                break;
            }
        }
        if (!$hasIcon && !$hasScreenshot) {
            $findings[] = finding(
                'warning',
                'MISSING_ARTWORK',
                null,
                null,
                'No icon or screenshot found under assets/. Optional — core renders a placeholder — but recommended.'
            );
        }
    }

    // --- Header (needs index.php to exist and be readable) ------------
    $indexRel = 'index.php';
    $contents = isset($files[$indexRel]) ? @file_get_contents($files[$indexRel]['abs']) : false;

    if ($contents !== false) {
        $identityLabel = $type === 'plugin' ? 'Plugin Name' : 'Theme Name';
        $leadingBlock = leadingCommentBlock($contents, $identityLabel);
        $identityOffsets = fieldOffsets($contents, $identityLabel);
        if ($identityOffsets !== [] && $leadingBlock === null) {
            $findings[] = finding(
                'error',
                'HEADER_BLOCK_MISSING',
                $indexRel,
                lineFromOffset($contents, $identityOffsets[0]),
                "\"{$identityLabel}:\" is not inside any comment block."
            );
        }

        $info = parseHeader($contents, $fieldMap);

        $requiredHeaderKeys = $type === 'plugin'
            ? ['plugin_name' => 'Plugin Name', 'description' => 'Description', 'version' => 'Version', 'author' => 'Author']
            : ['name' => 'Theme Name', 'description' => 'Description', 'version' => 'Version', 'author_name' => 'Author'];

        foreach ($requiredHeaderKeys as $key => $label) {
            if ($info[$key] === '') {
                $findings[] = finding('error', 'HEADER_FIELD_EMPTY', $indexRel, null, "\"{$label}\" is required and is empty or missing.");
            }
        }

        $findings = array_merge($findings, checkHeaderHijack($contents, $leadingBlock, $fieldMap, $indexRel));
        $findings = array_merge($findings, checkVersion($info['version'], $indexRel));

        foreach (['requires' => 'Requires Shopclass', 'tested_up_to' => 'Tested up to', 'requires_php' => 'Requires PHP'] as $key => $label) {
            if ($info[$key] === '') {
                $findings[] = finding(
                    'warning',
                    'MISSING_COMPAT_FIELD',
                    $indexRel,
                    null,
                    "\"{$label}\" is not declared. Undeclared compatibility installs with a muted note, but is worth declaring."
                );
                continue;
            }

            $isolated = [$key => $info[$key]];
            $verdict = \mindstellar\market\Compatibility::evaluate($isolated, $coreVersion, PHP_VERSION);
            if ($verdict['status'] === \mindstellar\market\Compatibility::UNDECLARED) {
                $findings[] = finding(
                    'error',
                    'COMPAT_FIELD_INVALID',
                    $indexRel,
                    null,
                    "\"{$label}: {$info[$key]}\" does not normalise to a version Compatibility recognises."
                );
            }
        }

        if ($info['tested_up_to'] !== '') {
            $verdict = \mindstellar\market\Compatibility::evaluate($info, $coreVersion, PHP_VERSION);
            if ($verdict['status'] === \mindstellar\market\Compatibility::UNTESTED) {
                $findings[] = finding(
                    'warning',
                    'COMPAT_UNTESTED',
                    $indexRel,
                    null,
                    \mindstellar\market\Compatibility::badgeLabel($info, $coreVersion) . ' — ' . $verdict['reason']
                );
            }
        }

        // A declared range that is empty on its face — no external data needed, the
        // header alone already contradicts itself. Compared at minor precision (see
        // minorPrecision()) so the documented `Requires Shopclass: 6.1.5` / `Tested up
        // to: 6.1` pairing is not flagged as a false positive.
        if ($info['requires'] !== '' && $info['tested_up_to'] !== '') {
            $requiresMinor = minorPrecision($info['requires']);
            $testedMinor = minorPrecision($info['tested_up_to']);
            if ($requiresMinor !== null && $testedMinor !== null && version_compare($requiresMinor, $testedMinor, '>')) {
                $findings[] = finding(
                    'error',
                    'COMPAT_RANGE_EMPTY',
                    $indexRel,
                    null,
                    "\"Requires Shopclass: {$info['requires']}\" is higher than \"Tested up to: {$info['tested_up_to']}\" " .
                        '— the declared range is empty. Raise "Tested up to", or lower "Requires Shopclass".'
                );
            }
        }

        // Version-existence checks — need to know which core versions actually shipped,
        // so they run only when --core-versions (or SHOPCLASS_CORE_VERSIONS) supplied one.
        if ($knownCoreVersions !== null) {
            if ($info['tested_up_to'] !== '') {
                $testedMinor = minorPrecision($info['tested_up_to']);
                $newestMinor = minorPrecision(newestVersion($knownCoreVersions));
                if ($testedMinor !== null && $newestMinor !== null && version_compare($testedMinor, $newestMinor, '>')) {
                    $findings[] = finding(
                        'error',
                        'COMPAT_TESTED_AHEAD',
                        $indexRel,
                        null,
                        "\"Tested up to: {$info['tested_up_to']}\" names a Shopclass version newer than the newest known " .
                            'release (' . newestVersion($knownCoreVersions) . ') — you cannot have tested against a ' .
                            'release that does not exist yet. Likely a typo.'
                    );
                }
            }

            if ($info['requires'] !== '') {
                $requiresBare = bareVersion($info['requires']);
                if ($requiresBare !== null && !versionExistsInList($requiresBare, $knownCoreVersions)) {
                    $findings[] = finding(
                        'error',
                        'COMPAT_REQUIRES_UNKNOWN_VERSION',
                        $indexRel,
                        null,
                        "\"Requires Shopclass: {$info['requires']}\" does not match any known Shopclass release — likely a typo."
                    );
                }
            }
        }

        // Requires PHP against core's own supported range. Neither end can produce a
        // wrong install/update decision at runtime (the field only ever gates upward,
        // and core's own 8.0 floor already applies to every install regardless of what
        // a package declares), so both are warnings rather than errors — signal that the
        // declaration is probably a mistake, not proof that it is one.
        if ($info['requires_php'] !== '') {
            $requiresPhpBare = bareVersion($info['requires_php']);
            if ($requiresPhpBare !== null) {
                if (version_compare($requiresPhpBare, CORE_PHP_FLOOR, '<')) {
                    $findings[] = finding(
                        'warning',
                        'REQUIRES_PHP_BELOW_FLOOR',
                        $indexRel,
                        null,
                        "\"Requires PHP: {$info['requires_php']}\" is below Shopclass's own PHP floor (" . CORE_PHP_FLOOR . ') ' .
                            '— every supported install already meets it, so this declares nothing meaningful.'
                    );
                } elseif (version_compare($requiresPhpBare, CORE_PHP_CEILING, '>')) {
                    $findings[] = finding(
                        'warning',
                        'REQUIRES_PHP_ABOVE_CEILING',
                        $indexRel,
                        null,
                        "\"Requires PHP: {$info['requires_php']}\" is above the newest PHP version this project tests " .
                            'against (' . CORE_PHP_CEILING . ') — double check this is not a typo.'
                    );
                }
            }
        }
    }

    // --- Security -------------------------------------------------------
    $phpFiles = array_filter($files, static fn ($rel) => strtolower((string) pathinfo($rel, PATHINFO_EXTENSION)) === 'php', ARRAY_FILTER_USE_KEY);
    $findings = array_merge($findings, scanDangerousConstructs($phpFiles));
    $findings = array_merge($findings, scanSecurityHeuristics($phpFiles));

    // --- Report -----------------------------------------------------------
    $errors = array_values(array_filter($findings, static fn ($f) => $f['level'] === 'error'));
    $warnings = array_values(array_filter($findings, static fn ($f) => $f['level'] === 'warning'));

    if ($options['json']) {
        echo json_encode(
            ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";
    } else {
        printHuman($slug, $type, $errors, $warnings);
    }

    return $errors === [] ? 0 : 1;
}

function printHuman(string $slug, string $type, array $errors, array $warnings): void
{
    printf("package-lint: %s (%s) — %d error(s), %d warning(s)\n\n", $slug, $type, count($errors), count($warnings));

    foreach (['Errors' => $errors, 'Warnings' => $warnings] as $heading => $group) {
        if ($group === []) {
            continue;
        }
        echo "{$heading}:\n";
        foreach ($group as $f) {
            $where = $f['file'] ?? '';
            if ($f['line'] !== null) {
                $where .= ":{$f['line']}";
            }
            $where = $where === '' ? '' : "{$where} — ";
            printf("  [%s] %s%s\n", $f['code'], $where, $f['message']);
        }
        echo "\n";
    }
}

exit(main($argv));
