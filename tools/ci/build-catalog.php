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
 * Builds the static catalog (docs/MARKET.md §5, §7) from a registry checkout —
 * either shopclass-plugins or shopclass-themes. Resolves both source models
 * (in-repo packages under plugins/|themes/, and external/<slug>.json
 * registrations pointing at another repository) to one identical entry shape,
 * writes the v1/ JSON tree, and exits non-zero if any package could not be
 * resolved safely.
 *
 * Dependency-free, like the rest of this family: no bootstrap, no autoloader,
 * no database. The one exception is Compatibility.php (see below), which is
 * itself dependency-free apart from a __() call a passthrough shim satisfies
 * here. Runs from an extracted release bundle with no core checkout present.
 *
 * Usage:
 *   php build-catalog.php --type=plugin|theme --root=<registry-repo-root> \
 *       --out=<dir> --core-version=<v> [--max-versions=N] [--offline]
 *
 * Writes <out>/v1/index.json, updates.json, categories.json, manifest.json,
 * and <out>/v1/packages/<slug>.json. Exit codes: 0 = success, 1 = at least one
 * package failed to resolve, 2 = usage error. Prints one summary line per
 * package to stderr.
 *
 * Auth is via the GITHUB_TOKEN environment variable when set (raises the
 * unauthenticated 60 req/h GitHub API budget), but every code path here must
 * also work with no token at all — this is what a first-time contributor
 * running the workflow locally, or a fork's PR run, gets.
 */

const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,40}$/';
const DEFAULT_MAX_VERSIONS = 20;

/** Hosts a download URL is allowed to resolve to (docs/MARKET.md §9). HTTPS only. */
const HOST_ALLOWLIST_EXACT = ['github.com', 'objects.githubusercontent.com', 'raw.githubusercontent.com'];

/** Header fields read out of a plugin's index.php, per PACKAGE-SPEC §3.2. Mirrors tools/package-lint.php. */
const PLUGIN_FIELDS = [
    'plugin_name'       => 'Plugin Name',
    'plugin_uri'        => 'Plugin URI',
    'support_uri'       => 'Support URI',
    'description'       => 'Description',
    'version'           => 'Version',
    'author'            => 'Author',
    'author_uri'        => 'Author URI',
    'requires'          => 'Requires Shopclass',
    'tested_up_to'      => 'Tested up to',
    'requires_php'      => 'Requires PHP',
];

/** Header fields read out of a theme's index.php, per PACKAGE-SPEC §3.3. Mirrors tools/package-lint.php. */
const THEME_FIELDS = [
    'name'             => 'Theme Name',
    'theme_uri'        => 'Theme URI',
    'description'      => 'Description',
    'version'          => 'Version',
    'author_name'      => 'Author',
    'author_url'       => 'Author URI',
    'requires'         => 'Requires Shopclass',
    'tested_up_to'     => 'Tested up to',
    'requires_php'     => 'Requires PHP',
];

function printUsage(): void
{
    fwrite(
        STDERR,
        "Usage: php build-catalog.php --type=plugin|theme --root=<registry-repo-root>\n"
        . "           --out=<dir> --core-version=<v> [--max-versions=N] [--offline]\n"
    );
}

/**
 * @return array<string, mixed>
 */
function parseArgs(array $argv): array
{
    $options = [
        'type' => null, 'root' => null, 'out' => null, 'core-version' => null,
        'max-versions' => (string) DEFAULT_MAX_VERSIONS, 'offline' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--offline') {
            $options['offline'] = true;
            continue;
        }
        if ($arg === '-h' || $arg === '--help') {
            printUsage();
            exit(0);
        }
        $matched = false;
        foreach (['type', 'root', 'out', 'core-version', 'max-versions'] as $key) {
            $prefix = '--' . $key . '=';
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }

    return $options;
}

/* ------------------------------------------------------------------ *
 * Deterministic JSON — every rebuild with unchanged inputs must produce a
 * byte-identical tree (docs/MARKET.md §5), so every object's keys are sorted
 * and every "set-like" list (categories, tags, file lists) is sorted at the
 * point it is built. Lists whose order is meaningful (versions newest-first)
 * are left alone here — canonicalize() only ever reorders string-keyed
 * (object) arrays, never numerically-indexed (list) ones.
 * ------------------------------------------------------------------ */

function isList(array $arr): bool
{
    $i = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $i++) {
            return false;
        }
    }

    return true;
}

/**
 * @param mixed $value
 * @return mixed
 */
function canonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (isList($value)) {
        return array_map('canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    $out = [];
    foreach ($value as $k => $v) {
        $out[$k] = canonicalize($v);
    }

    return $out;
}

function encodeJson($data): string
{
    return json_encode(canonicalize($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function writeJsonFile(string $path, $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create directory: {$dir}\n");
        exit(2);
    }
    if (@file_put_contents($path, encodeJson($data)) === false) {
        fwrite(STDERR, "Could not write: {$path}\n");
        exit(2);
    }
}

function readJsonFile(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/* ------------------------------------------------------------------ *
 * Host allowlist — docs/MARKET.md §9. Applied to every URL an install will
 * ultimately download and extract. A failure here is refused, never silently
 * dropped: the caller reports it and it counts toward the exit code.
 * ------------------------------------------------------------------ */

function isAllowedDownloadUrl(string $url): bool
{
    // Strip ASCII control characters before parsing — a known scheme-check bypass
    // ("java\tscript:") relies on a browser/parser stripping them where the check ran first.
    $clean = preg_replace('/[\x00-\x20]/', '', $url);
    $parts = parse_url($clean);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    if (strtolower($parts['scheme']) !== 'https') {
        return false;
    }
    if (isset($parts['port']) || isset($parts['user'])) {
        return false; // A real release asset URL never carries either.
    }

    $host = strtolower($parts['host']);
    if (in_array($host, HOST_ALLOWLIST_EXACT, true)) {
        return true;
    }

    // *.github.io — a genuine subdomain, not the bare apex.
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?\.github\.io$/', $host);
}

/* ------------------------------------------------------------------ *
 * HTTP + GitHub API, with a filesystem cache so --offline can rebuild from a
 * prior run without touching the network, and so a normal run never
 * re-downloads a release asset it already has the bytes for.
 * ------------------------------------------------------------------ */

/**
 * @return array{status:int, headers:array<string,string>, body:?string, error:?string}
 */
function httpGet(string $url, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 30, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['status' => 0, 'headers' => [], 'body' => null, 'error' => 'request failed'];
        }
        $status = 200;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return ['status' => $status, 'headers' => [], 'body' => $body, 'error' => null];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'shopclass-build-catalog',
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return ['status' => 0, 'headers' => [], 'body' => null, 'error' => $error];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    // CURLOPT_FOLLOWLOCATION concatenates one header block per hop; only the
    // final block (the response actually returned) carries Link/rate-limit headers.
    $blocks = preg_split('/\r\n\r\n(?=HTTP\/)/', trim($rawHeaders)) ?: [$rawHeaders];
    $parsedHeaders = [];
    foreach (explode("\r\n", (string) end($blocks)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $parsedHeaders, 'body' => $body, 'error' => null];
}

/** @return array<int,array{url:string,rel:string}> */
function parseLinkHeader(string $link): array
{
    preg_match_all('/<([^>]+)>\s*;\s*rel="([^"]+)"/', $link, $m, PREG_SET_ORDER);

    return array_map(static fn ($hit) => ['url' => $hit[1], 'rel' => $hit[2]], $m);
}

function githubHeaders(?string $token): array
{
    $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: shopclass-build-catalog'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    return $headers;
}

/**
 * All releases (any count) for owner/repo, newest-first as GitHub returns them.
 * Cached to <cacheDir>/releases/<owner>__<repo>.json; --offline reads the cache
 * only and never touches the network.
 *
 * `fatal` distinguishes "we have nothing usable" (no releases resolved for this
 * repo, so its packages truly cannot be listed — a real build failure) from a
 * merely degraded fetch that still produced data (e.g. a live request failed but
 * a prior cache existed): the latter must never be conflated with the ordinary,
 * non-failing case of a repo that genuinely has zero releases yet.
 *
 * @return array{releases: array<int,array<string,mixed>>, error: ?string, fatal: bool}
 */
function ghGetReleases(string $ownerRepo, bool $offline, string $cacheDir, ?string $token): array
{
    $cacheFile = $cacheDir . '/releases/' . str_replace('/', '__', $ownerRepo) . '.json';

    if ($offline) {
        $cached = readJsonFile($cacheFile);

        return $cached === null
            ? ['releases' => [], 'error' => 'offline and no cached release list', 'fatal' => true]
            : ['releases' => $cached, 'error' => null, 'fatal' => false];
    }

    $all = [];
    $url = "https://api.github.com/repos/{$ownerRepo}/releases?per_page=100";
    $hops = 0;
    while ($url !== null && $hops < 10) {
        $hops++;
        $resp = httpGet($url, githubHeaders($token));
        if ($resp['status'] !== 200) {
            $cached = readJsonFile($cacheFile);
            $detail = $resp['error'] ?? "HTTP {$resp['status']}";
            if ($cached !== null) {
                return ['releases' => $cached, 'error' => "using cached release list — live fetch failed ({$detail})", 'fatal' => false];
            }

            return ['releases' => [], 'error' => "could not list releases for {$ownerRepo}: {$detail}", 'fatal' => true];
        }
        $page = json_decode((string) $resp['body'], true);
        if (!is_array($page)) {
            break;
        }
        array_push($all, ...$page);

        $url = null;
        foreach (parseLinkHeader($resp['headers']['link'] ?? '') as $link) {
            if ($link['rel'] === 'next') {
                $url = $link['url'];
            }
        }
    }

    writeJsonFile($cacheFile, $all);

    return ['releases' => $all, 'error' => null, 'fatal' => false];
}

/**
 * Downloads a release asset's raw bytes, cached by URL. The host allowlist is
 * checked by the caller before this is ever invoked — this function assumes
 * the URL already cleared it.
 */
function ghDownloadAsset(string $url, bool $offline, string $cacheDir): ?string
{
    $cacheFile = $cacheDir . '/assets/' . sha1($url) . '.bin';
    if (is_file($cacheFile)) {
        $bytes = @file_get_contents($cacheFile);

        return $bytes === false ? null : $bytes;
    }
    if ($offline) {
        return null;
    }

    $resp = httpGet($url, ['User-Agent: shopclass-build-catalog']);
    if ($resp['status'] !== 200 || $resp['body'] === null) {
        return null;
    }

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($cacheFile, $resp['body']);

    return $resp['body'];
}

/** GITHUB_REPOSITORY (what Actions sets) first, then the root's own git remote. */
function detectRepoSlug(string $root): ?string
{
    $env = getenv('GITHUB_REPOSITORY');
    if ($env !== false && $env !== '' && str_contains($env, '/')) {
        return $env;
    }

    $out = [];
    @exec('git -C ' . escapeshellarg($root) . ' remote get-url origin 2>/dev/null', $out);
    $remote = trim($out[0] ?? '');
    if ($remote === '') {
        return null;
    }
    if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $m)) {
        return $m[1];
    }

    return null;
}

/* ------------------------------------------------------------------ *
 * Zip + header parsing — the artifact is the source of truth (docs/MARKET.md
 * §3.2): every field below is read out of the downloaded zip, never out of a
 * hand-edited claim in a manifest.
 * ------------------------------------------------------------------ */

/**
 * @return array{zip: ZipArchive, path: string}|null
 */
function openZipBytes(string $bytes): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'shopclass-catalog-');
    if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);

        return null;
    }

    return ['zip' => $zip, 'path' => $tmp];
}

function closeZip(array $handle): void
{
    $handle['zip']->close();
    @unlink($handle['path']);
}

/** The single top-level directory name shared by every entry, or null if there isn't one (PACKAGE-SPEC §2). */
function zipTopLevelDir(ZipArchive $zip): ?string
{
    $top = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $first = explode('/', ltrim($name, '/'))[0];
        if ($first === '') {
            continue;
        }
        if ($top === null) {
            $top = $first;
        } elseif ($top !== $first) {
            return null;
        }
    }

    return $top;
}

function matchHeaderField(string $contents, string $label): string
{
    if (preg_match('|' . preg_quote($label, '|') . ':([^\r\t\n]*)|i', $contents, $m)) {
        return trim($m[1]);
    }

    return '';
}

/** @return array<string,string> */
function parseHeaderFields(string $contents, array $fieldMap): array
{
    $info = [];
    foreach ($fieldMap as $key => $label) {
        $info[$key] = matchHeaderField($contents, $label);
    }

    return $info;
}

/**
 * Reads index.php out of a downloaded release zip and parses its header block.
 *
 * @return array{ok:bool, error:?string, slug_dir:?string, header:array<string,string>}
 */
function readHeaderFromZip(string $bytes, string $type): array
{
    $handle = openZipBytes($bytes);
    if ($handle === null) {
        return ['ok' => false, 'error' => 'not a valid zip archive', 'slug_dir' => null, 'header' => []];
    }

    $topDir = zipTopLevelDir($handle['zip']);
    $entryName = $topDir !== null ? $topDir . '/index.php' : 'index.php';
    $contents = $handle['zip']->getFromName($entryName);
    closeZip($handle);

    if ($contents === false) {
        return ['ok' => false, 'error' => "no {$entryName} in the zip (PACKAGE-SPEC §2: single top-level directory named for the slug)", 'slug_dir' => $topDir, 'header' => []];
    }

    $fieldMap = $type === 'plugin' ? PLUGIN_FIELDS : THEME_FIELDS;
    $header = parseHeaderFields($contents, $fieldMap);
    if ($header['version'] === '') {
        return ['ok' => false, 'error' => 'index.php in the zip has no Version: header', 'slug_dir' => $topDir, 'header' => $header];
    }

    return ['ok' => true, 'error' => null, 'slug_dir' => $topDir, 'header' => $header];
}

/** Files under assets/ (plugin) or the root screenshot (theme) copied out of a resolved zip for hosting alongside the catalog. */
function extractArtwork(string $bytes, string $slugDir, array $wantRelPaths): array
{
    $handle = openZipBytes($bytes);
    if ($handle === null) {
        return [];
    }
    $out = [];
    foreach ($wantRelPaths as $rel) {
        $data = $handle['zip']->getFromName($slugDir . '/' . $rel);
        if ($data !== false) {
            $out[$rel] = $data;
        }
    }
    closeZip($handle);

    return $out;
}

/** A single text file out of a resolved zip, or null. Used for README.md / CHANGELOG.md on an external package. */
function readZipTextFile(string $bytes, string $slugDir, string $relPath): ?string
{
    $handle = openZipBytes($bytes);
    if ($handle === null) {
        return null;
    }
    $data = $handle['zip']->getFromName($slugDir . '/' . $relPath);
    closeZip($handle);

    return $data === false ? null : $data;
}

/* ------------------------------------------------------------------ *
 * Markdown -> sanitised HTML. No dependency is allowed, so this renders a
 * deliberately small subset of Markdown itself, safely by construction: the
 * ENTIRE input is HTML-escaped first, and every tag in the output is one this
 * renderer builds itself from a recognised construct. There is no raw-HTML
 * passthrough of any kind (docs task §9 "no allowlisted raw HTML" — the
 * allowlist here is empty, satisfied trivially), so a literal `<script>` or
 * an `onclick="..."` typed into a README can only ever end up as escaped,
 * inert text — never as a real tag or attribute.
 * ------------------------------------------------------------------ */

function sanitizeLinkUrl(string $url, bool $isImage): ?string
{
    $clean = trim(preg_replace('/[\x00-\x20]/', '', $url) ?? '');
    if ($clean === '') {
        return null;
    }
    if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $clean)) {
        return $clean; // No scheme -- a relative/anchor link. Not a protocol-injection vector.
    }
    $scheme = strtolower(explode(':', $clean, 2)[0]);
    $allowed = $isImage ? ['http', 'https'] : ['http', 'https', 'mailto'];

    return in_array($scheme, $allowed, true) ? $clean : null;
}

function renderInlineMarkdown(string $escaped): string
{
    // Code spans first, stashed behind placeholders so later patterns cannot reach inside them.
    $codeSpans = [];
    $escaped = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codeSpans) {
        $token = "\x01CODE" . count($codeSpans) . "\x02";
        $codeSpans[$token] = '<code>' . $m[1] . '</code>';

        return $token;
    }, $escaped);

    $escaped = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function ($m) {
        $url = sanitizeLinkUrl($m[2], true);

        return $url === null ? $m[1] : '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $escaped);

    $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
        $url = sanitizeLinkUrl($m[2], false);

        return $url === null ? $m[1] : '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="nofollow noopener noreferrer" target="_blank">' . $m[1] . '</a>';
    }, $escaped);

    $escaped = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $escaped);
    $escaped = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)|(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?!_)/', '<em>$1$2</em>', $escaped);

    return strtr($escaped, $codeSpans);
}

function escapeMd(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderMarkdownSafe(?string $markdown): string
{
    if ($markdown === null || trim($markdown) === '') {
        return '';
    }
    $lines = preg_split('/\r\n|\r|\n/', $markdown);
    $html = [];
    $i = 0;
    $n = count($lines);

    while ($i < $n) {
        $line = $lines[$i];

        if (trim($line) === '') {
            $i++;
            continue;
        }

        // Fenced code block.
        if (preg_match('/^```/', $line)) {
            $buf = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', $lines[$i])) {
                $buf[] = $lines[$i];
                $i++;
            }
            $i++; // closing fence
            $html[] = '<pre><code>' . escapeMd(implode("\n", $buf)) . '</code></pre>';
            continue;
        }

        // ATX heading.
        if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $m)) {
            $level = strlen($m[1]);
            $html[] = "<h{$level}>" . renderInlineMarkdown(escapeMd($m[2])) . "</h{$level}>";
            $i++;
            continue;
        }

        // Horizontal rule.
        if (preg_match('/^\s*([-*_])\s*(?:\1\s*){2,}$/', $line)) {
            $html[] = '<hr>';
            $i++;
            continue;
        }

        // Blockquote.
        if (preg_match('/^>\s?(.*)$/', $line)) {
            $buf = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $m)) {
                $buf[] = $m[1];
                $i++;
            }
            $html[] = '<blockquote><p>' . renderInlineMarkdown(escapeMd(implode(' ', $buf))) . '</p></blockquote>';
            continue;
        }

        // Unordered list.
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line)) {
            $items = [];
            while ($i < $n && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $m)) {
                $items[] = '<li>' . renderInlineMarkdown(escapeMd($m[1])) . '</li>';
                $i++;
            }
            $html[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }

        // Ordered list.
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line)) {
            $items = [];
            while ($i < $n && preg_match('/^\s*\d+\.\s+(.*)$/', $lines[$i], $m)) {
                $items[] = '<li>' . renderInlineMarkdown(escapeMd($m[1])) . '</li>';
                $i++;
            }
            $html[] = '<ol>' . implode('', $items) . '</ol>';
            continue;
        }

        // Paragraph — consecutive non-blank, non-special lines.
        $buf = [];
        while ($i < $n && trim($lines[$i]) !== ''
            && !preg_match('/^(```|#{1,6}\s|>|\s*[-*+]\s|\s*\d+\.\s|\s*([-*_])\s*(?:\2\s*){2,}$)/', $lines[$i])) {
            $buf[] = $lines[$i];
            $i++;
        }
        if ($buf !== []) {
            $html[] = '<p>' . renderInlineMarkdown(escapeMd(implode(' ', $buf))) . '</p>';
        } else {
            $i++; // Guard against an unmatched special line looping forever.
        }
    }

    return implode("\n", $html);
}

/** Splits a CHANGELOG.md by `## <version>` headings, per this project's own load-bearing format. */
function splitChangelogByVersion(?string $changelog): array
{
    if ($changelog === null || trim($changelog) === '') {
        return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $changelog);
    $sections = [];
    $currentVersion = null;
    $buf = [];

    $flush = static function () use (&$sections, &$currentVersion, &$buf) {
        if ($currentVersion !== null) {
            $sections[$currentVersion] = trim(implode("\n", $buf));
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.*)$/', $line, $m) && !preg_match('/^###/', $line)) {
            if (preg_match('/(\d+(?:\.\d+)+(?:[-.](?:dev|alpha|beta|rc)\d*)?)/i', $m[1], $vm)) {
                $flush();
                $currentVersion = $vm[1];
                $buf = [];
                continue;
            }
        }
        $buf[] = $line;
    }
    $flush();

    return $sections;
}

/* ------------------------------------------------------------------ *
 * Package discovery
 * ------------------------------------------------------------------ */

function sortedDirEntries(string $dir): array
{
    $entries = @scandir($dir);
    if ($entries === false) {
        return [];
    }
    $entries = array_values(array_diff($entries, ['.', '..']));
    sort($entries, SORT_STRING);

    return $entries;
}

/** @return array<int,array{slug:string, dir:string, manifest:array}> */
function discoverInRepoPackages(string $root, string $type, array &$errors): array
{
    $subdir = $root . '/' . ($type === 'plugin' ? 'plugins' : 'themes');
    if (!is_dir($subdir)) {
        return [];
    }

    $out = [];
    foreach (sortedDirEntries($subdir) as $entry) {
        $dir = $subdir . '/' . $entry;
        if (!is_dir($dir)) {
            continue;
        }
        if (!preg_match(SLUG_PATTERN, $entry)) {
            $errors[] = "{$entry}: directory name does not match the slug pattern — skipped";
            continue;
        }
        if (!is_file($dir . '/index.php') || !is_file($dir . '/shopclass.json')) {
            $errors[] = "{$entry}: missing index.php or shopclass.json — skipped";
            continue;
        }
        $manifest = readJsonFile($dir . '/shopclass.json');
        if ($manifest === null || ($manifest['slug'] ?? null) !== $entry || ($manifest['type'] ?? null) !== $type
            || !is_array($manifest['categories'] ?? null) || $manifest['categories'] === []
            || !is_string($manifest['short_description'] ?? null) || trim((string) $manifest['short_description']) === ''
        ) {
            $errors[] = "{$entry}: shopclass.json is missing or fails basic validation — skipped";
            continue;
        }
        $out[] = ['slug' => $entry, 'dir' => $dir, 'manifest' => $manifest];
    }

    return $out;
}

/** @return array<int,array{slug:string, manifest:array}> */
function discoverExternalPackages(string $root, string $type, array &$errors): array
{
    $dir = $root . '/external';
    if (!is_dir($dir)) {
        return [];
    }

    $out = [];
    foreach (sortedDirEntries($dir) as $entry) {
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $slug = substr($entry, 0, -5);
        $manifest = readJsonFile($dir . '/' . $entry);
        if ($manifest === null || ($manifest['slug'] ?? null) !== $slug || ($manifest['type'] ?? null) !== $type
            || ($manifest['source']['kind'] ?? null) !== 'github-release'
            || !is_string($manifest['source']['repo'] ?? null) || trim((string) $manifest['source']['repo']) === ''
            || !is_string($manifest['asset_pattern'] ?? null) || $manifest['asset_pattern'] === ''
            || !is_array($manifest['categories'] ?? null) || $manifest['categories'] === []
            || !is_string($manifest['short_description'] ?? null) || trim((string) $manifest['short_description']) === ''
        ) {
            $errors[] = "{$slug}: external/{$entry} is missing or fails basic validation — skipped";
            continue;
        }
        if (!preg_match(SLUG_PATTERN, $slug)) {
            $errors[] = "{$slug}: filename does not match the slug pattern — skipped";
            continue;
        }
        $out[] = ['slug' => $slug, 'manifest' => $manifest];
    }

    return $out;
}

/* ------------------------------------------------------------------ *
 * Version resolution — the heart of the builder. Both source models end up
 * calling resolveOneRelease(), so an in-repo and an external package produce
 * byte-for-byte the same entry shape (docs/MARKET.md §3: "core never learns
 * the difference").
 * ------------------------------------------------------------------ */

/**
 * Downloads one release's chosen asset, verifies it, and reads its own header.
 * Per-version requires/requires_php/tested come from THIS artifact, never the
 * newest one (docs/MARKET.md §4) — that is simply a consequence of calling
 * this once per resolved release rather than once per package.
 *
 * @return array{ok:bool, entry:?array, artwork_zip_bytes:?string, artwork_slug_dir:?string, error:?string}
 */
function resolveOneRelease(string $slug, string $type, string $assetUrl, string $publishedAt, bool $offline, string $cacheDir, string $coreVersion): array
{
    if (!isAllowedDownloadUrl($assetUrl)) {
        return ['ok' => false, 'entry' => null, 'artwork_zip_bytes' => null, 'artwork_slug_dir' => null, 'error' => "refusing to emit a download URL outside the host allowlist: {$assetUrl}"];
    }

    $bytes = ghDownloadAsset($assetUrl, $offline, $cacheDir);
    if ($bytes === null) {
        return ['ok' => false, 'entry' => null, 'artwork_zip_bytes' => null, 'artwork_slug_dir' => null, 'error' => $offline ? "not cached (offline): {$assetUrl}" : "could not download: {$assetUrl}"];
    }

    $read = readHeaderFromZip($bytes, $type);
    if (!$read['ok']) {
        return ['ok' => false, 'entry' => null, 'artwork_zip_bytes' => null, 'artwork_slug_dir' => null, 'error' => $read['error']];
    }
    if ($read['slug_dir'] !== $slug) {
        return ['ok' => false, 'entry' => null, 'artwork_zip_bytes' => null, 'artwork_slug_dir' => null, 'error' => "zip's top-level directory \"{$read['slug_dir']}\" does not match slug \"{$slug}\""];
    }

    $header = $read['header'];
    $version = $header['version'];
    $requires = $header['requires'] !== '' ? $header['requires'] : null;
    $requiresPhp = $header['requires_php'] !== '' ? $header['requires_php'] : null;
    $testedUpTo = $header['tested_up_to'] !== '' ? $header['tested_up_to'] : null;

    $compat = \mindstellar\market\Compatibility::evaluate($header, $coreVersion, PHP_VERSION);

    $entry = [
        'version'       => $version,
        'requires'      => $requires,
        'requires_php'  => $requiresPhp,
        'tested'        => $testedUpTo,
        'url'           => $assetUrl,
        'sha256'        => hash('sha256', $bytes),
        'size'          => strlen($bytes),
        'published_at'  => $publishedAt,
        'header'        => $header,
        'compat'        => ['status' => $compat['status'], 'blocked' => $compat['blocked'], 'reason' => $compat['reason']],
    ];

    return ['ok' => true, 'entry' => $entry, 'artwork_zip_bytes' => $bytes, 'artwork_slug_dir' => $read['slug_dir'], 'error' => null];
}

/**
 * @return array{versions: array<int,array>, artwork: array{bytes:?string, slug_dir:?string}, notes: array<int,string>, hadError: bool}
 */
function resolveInRepoVersions(string $slug, string $type, array $allReleases, bool $offline, string $cacheDir, string $coreVersion, int $maxVersions): array
{
    $pattern = '/^' . preg_quote($slug, '/') . '-v(.+)$/';
    $matches = [];
    foreach ($allReleases as $release) {
        if (($release['draft'] ?? false) === true) {
            continue;
        }
        if (preg_match($pattern, (string) ($release['tag_name'] ?? ''))) {
            $matches[] = $release;
        }
    }

    if ($matches === []) {
        return ['versions' => [], 'artwork' => ['bytes' => null, 'slug_dir' => null], 'notes' => ['no releases found (nothing tagged ' . $slug . '-v*yet)'], 'hadError' => false];
    }

    // Newest by publish date first, capped — "resolve the newest N releases" (docs/MARKET.md §7).
    usort($matches, static fn ($a, $b) => strtotime((string) ($b['published_at'] ?? '')) <=> strtotime((string) ($a['published_at'] ?? '')));
    $matches = array_slice($matches, 0, $maxVersions);

    $versions = [];
    $notes = [];
    $hadError = false;
    $artwork = ['bytes' => null, 'slug_dir' => null];

    foreach ($matches as $release) {
        $tag = (string) $release['tag_name'];
        $assets = $release['assets'] ?? [];
        $exact = null;
        $loose = null;
        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');
            if (!str_ends_with($name, '.zip')) {
                continue;
            }
            if ($loose === null && preg_match('/^' . preg_quote($slug, '/') . '_.*\.zip$/', $name)) {
                $loose = $asset;
            }
            if (preg_match('/^' . preg_quote($slug, '/') . '_[0-9].*\.zip$/', $name)) {
                $exact = $exact ?? $asset;
            }
        }
        $chosen = $exact ?? $loose;
        if ($chosen === null) {
            $notes[] = "{$tag}: no {$slug}_*.zip asset — skipped";
            continue;
        }

        $resolved = resolveOneRelease($slug, $type, (string) $chosen['browser_download_url'], (string) ($release['published_at'] ?? ''), $offline, $cacheDir, $coreVersion);
        if (!$resolved['ok']) {
            $notes[] = "{$tag}: {$resolved['error']}";
            $hadError = true;
            continue;
        }
        $versions[] = $resolved['entry'];
        if ($artwork['bytes'] === null) {
            $artwork = ['bytes' => $resolved['artwork_zip_bytes'], 'slug_dir' => $resolved['artwork_slug_dir']];
        }
    }

    usort($versions, static fn ($a, $b) => version_compare($b['version'], $a['version']));

    return ['versions' => $versions, 'artwork' => $artwork, 'notes' => $notes, 'hadError' => $hadError];
}

/**
 * @return array{versions: array<int,array>, artwork: array{bytes:?string, slug_dir:?string}, notes: array<int,string>, hadError: bool}
 */
function resolveExternalVersions(string $slug, string $type, array $manifest, bool $offline, string $cacheDir, ?string $token, string $coreVersion, int $maxVersions): array
{
    $repo = (string) $manifest['source']['repo'];
    $assetPattern = (string) $manifest['asset_pattern'];

    $result = ghGetReleases($repo, $offline, $cacheDir, $token);
    $notes = $result['error'] !== null ? [$result['error']] : [];
    $releases = $result['releases'];

    if ($releases === []) {
        if (!$result['fatal']) {
            $notes[] = "no releases found for {$repo}";
        }

        return ['versions' => [], 'artwork' => ['bytes' => null, 'slug_dir' => null], 'notes' => $notes, 'hadError' => $result['fatal']];
    }

    usort($releases, static fn ($a, $b) => strtotime((string) ($b['published_at'] ?? '')) <=> strtotime((string) ($a['published_at'] ?? '')));
    $candidates = array_filter($releases, static fn ($r) => ($r['draft'] ?? false) !== true);
    $candidates = array_slice(array_values($candidates), 0, $maxVersions);

    $versions = [];
    $hadError = $result['fatal'];
    $artwork = ['bytes' => null, 'slug_dir' => null];

    foreach ($candidates as $release) {
        $tag = (string) $release['tag_name'];
        $matchingAssets = array_values(array_filter(
            $release['assets'] ?? [],
            static fn ($a) => @preg_match('#' . $assetPattern . '#', (string) ($a['name'] ?? '')) === 1
        ));
        if ($matchingAssets === []) {
            $notes[] = "{$tag}: no asset matches asset_pattern — skipped";
            continue;
        }
        usort($matchingAssets, static fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        if (count($matchingAssets) > 1) {
            $notes[] = "{$tag}: asset_pattern matched " . count($matchingAssets) . ' assets — using ' . $matchingAssets[0]['name'];
        }

        $resolved = resolveOneRelease($slug, $type, (string) $matchingAssets[0]['browser_download_url'], (string) ($release['published_at'] ?? ''), $offline, $cacheDir, $coreVersion);
        if (!$resolved['ok']) {
            $notes[] = "{$tag}: {$resolved['error']}";
            $hadError = true;
            continue;
        }
        // External packages ship no CHANGELOG.md of their own (§7 note in buildPackageEntry) —
        // the release body is the only per-version changelog source available for them.
        $resolved['entry']['release_body'] = $release['body'] ?? null;
        $versions[] = $resolved['entry'];
        if ($artwork['bytes'] === null) {
            $artwork = ['bytes' => $resolved['artwork_zip_bytes'], 'slug_dir' => $resolved['artwork_slug_dir']];
        }
    }

    usort($versions, static fn ($a, $b) => version_compare($b['version'], $a['version']));

    return ['versions' => $versions, 'artwork' => $artwork, 'notes' => $notes, 'hadError' => $hadError];
}

/* ------------------------------------------------------------------ *
 * Package assembly
 * ------------------------------------------------------------------ */

function localHeaderFallback(string $dir, string $type): array
{
    $contents = @file_get_contents($dir . '/index.php');
    if ($contents === false) {
        return [];
    }

    return parseHeaderFields($contents, $type === 'plugin' ? PLUGIN_FIELDS : THEME_FIELDS);
}

function displayName(array $header, string $type, string $slugFallback): string
{
    $name = $type === 'plugin' ? ($header['plugin_name'] ?? '') : ($header['name'] ?? '');

    return $name !== '' ? $name : ucwords(str_replace('-', ' ', $slugFallback));
}

function displayAuthor(array $header, string $type): string
{
    return $type === 'plugin' ? ($header['author'] ?? '') : ($header['author_name'] ?? '');
}

/**
 * @return array{package: array, summary: string, hadError: bool}
 */
function buildPackageEntry(
    string $slug,
    string $type,
    string $source,
    array $manifest,
    array $resolution,
    ?string $localDir,
    string $coreVersion,
    string $outDir
): array {
    $versions = $resolution['versions'];
    $newest = $versions[0] ?? null;

    // Browsing metadata: in-repo packages have a local index.php that is authoritative
    // even pre-release (PACKAGE-SPEC §1); external packages have none, so metadata can
    // only ever come from a resolved artifact.
    $header = $newest['header'] ?? ($localDir !== null ? localHeaderFallback($localDir, $type) : []);

    $name = displayName($header, $type, $slug);
    $description = $header['description'] ?? '';
    $author = displayAuthor($header, $type);
    $authorUri = $type === 'plugin' ? ($header['author_uri'] ?? '') : ($header['author_url'] ?? '');
    $homepage = $type === 'plugin' ? ($header['plugin_uri'] ?? '') : ($header['theme_uri'] ?? '');

    // Artwork. In-repo: copy the files declared in shopclass.json out of the newest
    // resolved zip into v1/assets/<slug>/ and reference them relative to v1/ — a path,
    // not a URL, so it resolves the same whether the caller is reading via GitHub Pages
    // or the raw.githubusercontent mirror (docs/MARKET.md §5's two-mirror design), with
    // neither hardcoded here. External: the manifest already carries full absolute URLs
    // into the package's own repository; pass those through after the same host check
    // used for downloads (icons/screenshots are lower stakes than an installable zip, so
    // a failure here drops just the image, not the whole package).
    $icon = null;
    $screenshots = [];

    if ($source === 'in-repo') {
        $bytes = $resolution['artwork']['bytes'];
        $slugDir = $resolution['artwork']['slug_dir'];
        if ($bytes !== null && $slugDir !== null) {
            $want = [];
            if (isset($manifest['icon'])) {
                $want[] = $manifest['icon'];
            }
            foreach ($manifest['screenshots'] ?? [] as $s) {
                if (isset($s['src'])) {
                    $want[] = $s['src'];
                }
            }
            if ($type === 'theme') {
                $want[] = 'screenshot.png';
            }
            $files = extractArtwork($bytes, $slugDir, $want);
            foreach ($files as $rel => $data) {
                $dest = $outDir . '/v1/assets/' . $slug . '/' . $rel;
                if (!is_dir(dirname($dest))) {
                    @mkdir(dirname($dest), 0775, true);
                }
                @file_put_contents($dest, $data);
            }
            if (isset($manifest['icon']) && isset($files[$manifest['icon']])) {
                $icon = 'assets/' . $slug . '/' . $manifest['icon'];
            }
            foreach ($manifest['screenshots'] ?? [] as $s) {
                if (isset($files[$s['src']])) {
                    $screenshots[] = ['src' => 'assets/' . $slug . '/' . $s['src'], 'caption' => $s['caption'] ?? ''];
                }
            }
            if ($type === 'theme' && isset($files['screenshot.png'])) {
                $icon = 'assets/' . $slug . '/screenshot.png';
            }
        }
    } else {
        if (isset($manifest['icon']) && isAllowedDownloadUrl((string) $manifest['icon'])) {
            $icon = $manifest['icon'];
        }
        foreach ($manifest['screenshots'] ?? [] as $s) {
            if (isset($s['src']) && isAllowedDownloadUrl((string) $s['src'])) {
                $screenshots[] = ['src' => $s['src'], 'caption' => $s['caption'] ?? ''];
            }
        }
    }

    // README. In-repo reads it straight off disk (already PR-reviewed alongside index.php).
    // External has no local copy at all — the newest resolved zip is where it lives, read
    // the same way the header block was.
    $readmeMd = $localDir !== null ? @file_get_contents($localDir . '/README.md') : false;
    if ($readmeMd === false && $resolution['artwork']['bytes'] !== null && $resolution['artwork']['slug_dir'] !== null) {
        $readmeMd = readZipTextFile($resolution['artwork']['bytes'], $resolution['artwork']['slug_dir'], 'README.md') ?? false;
    }
    $readmeHtml = renderMarkdownSafe($readmeMd === false ? null : $readmeMd);

    // Changelog. In-repo splits the package's own CHANGELOG.md by version heading; an
    // external package's zip may or may not carry one of its own (unlike README.md this
    // is not guaranteed — a theme repo's own release process decides), so each version's
    // GitHub release body is the reliable fallback when it doesn't.
    $changelogBySlugVersion = [];
    $changelogMd = $localDir !== null ? @file_get_contents($localDir . '/CHANGELOG.md') : false;
    if ($changelogMd === false && $resolution['artwork']['bytes'] !== null && $resolution['artwork']['slug_dir'] !== null) {
        $changelogMd = readZipTextFile($resolution['artwork']['bytes'], $resolution['artwork']['slug_dir'], 'CHANGELOG.md') ?? false;
    }
    if ($changelogMd !== false) {
        $changelogBySlugVersion = splitChangelogByVersion($changelogMd);
    }

    $versionsOut = [];
    foreach ($versions as $v) {
        $changelogSrc = $changelogBySlugVersion[$v['version']] ?? ($v['release_body'] ?? null);
        $versionsOut[] = [
            'version'        => $v['version'],
            'requires'       => $v['requires'],
            'requires_php'   => $v['requires_php'],
            'tested'         => $v['tested'],
            'url'            => $v['url'],
            'sha256'         => $v['sha256'],
            'size'           => $v['size'],
            'published_at'   => $v['published_at'],
            'compat'         => $v['compat'],
            'changelog_html' => renderMarkdownSafe($changelogSrc),
        ];
    }

    $categories = array_values(array_unique(array_map('strval', $manifest['categories'] ?? [])));
    sort($categories, SORT_STRING);
    $tags = array_values(array_unique(array_map('strval', $manifest['tags'] ?? [])));
    sort($tags, SORT_STRING);

    $package = [
        'slug'             => $slug,
        'type'             => $type,
        'source'           => $source,
        'name'             => $name,
        'short_description' => (string) $manifest['short_description'],
        'description_html' => $readmeHtml,
        'author'           => $author,
        'author_uri'       => $authorUri !== '' ? $authorUri : null,
        'homepage'         => $homepage !== '' ? $homepage : null,
        'support'          => [
            'issues' => $manifest['support']['issues'] ?? null,
            'docs'   => $manifest['support']['docs'] ?? null,
        ],
        'funding'          => $manifest['funding'] ?? null,
        'license'          => $manifest['license'] ?? null,
        'categories'       => $categories,
        'tags'             => $tags,
        'icon'             => $icon,
        'screenshots'      => $screenshots,
        'latest_version'   => $newest['version'] ?? null,
        'updated_at'       => $newest['published_at'] ?? null,
        'versions'         => $versionsOut,
    ];

    $summary = sprintf(
        '%s (%s, %s): %d version(s) resolved%s',
        $slug,
        $type,
        $source,
        count($versionsOut),
        $resolution['notes'] === [] ? '' : ' — ' . implode('; ', $resolution['notes'])
    );

    return ['package' => $package, 'summary' => $summary, 'hadError' => $resolution['hadError']];
}

/* ------------------------------------------------------------------ *
 * Output files
 * ------------------------------------------------------------------ */

function buildUpdatesJson(array $packages): array
{
    $out = [];
    foreach ($packages as $slug => $pkg) {
        $out[$slug] = array_map(static fn ($v) => [
            'version'      => $v['version'],
            'requires'     => $v['requires'],
            'requires_php' => $v['requires_php'],
            'tested'       => $v['tested'],
            'url'          => $v['url'],
            'sha256'       => $v['sha256'],
            'size'         => $v['size'],
        ], $pkg['versions']);
    }

    return $out;
}

function buildIndexJson(array $packages): array
{
    $out = [];
    foreach ($packages as $slug => $pkg) {
        $out[] = [
            'slug'             => $slug,
            'name'             => $pkg['name'],
            'short_description' => $pkg['short_description'],
            'author'           => $pkg['author'],
            'version'          => $pkg['latest_version'],
            'icon'             => $pkg['icon'],
            'categories'       => $pkg['categories'],
            'tags'             => $pkg['tags'],
            'updated_at'       => $pkg['updated_at'],
        ];
    }
    usort($out, static fn ($a, $b) => strcmp($a['slug'], $b['slug']));

    return $out;
}

function buildCategoriesJson(string $root, array $packages): array
{
    $vocab = readJsonFile($root . '/schema/categories.json');
    $entries = [];
    if ($vocab !== null && isset($vocab['categories']) && is_array($vocab['categories'])) {
        foreach ($vocab['categories'] as $c) {
            if (isset($c['id'])) {
                $entries[$c['id']] = ['id' => $c['id'], 'label' => $c['label'] ?? $c['id'], 'description' => $c['description'] ?? '', 'count' => 0];
            }
        }
    }
    foreach ($packages as $pkg) {
        foreach ($pkg['categories'] as $cat) {
            if (!isset($entries[$cat])) {
                $entries[$cat] = ['id' => $cat, 'label' => $cat, 'description' => '', 'count' => 0];
            }
            $entries[$cat]['count']++;
        }
    }
    $out = array_values($entries);
    usort($out, static fn ($a, $b) => strcmp($a['id'], $b['id']));

    return $out;
}

function buildManifestJson(string $type, string $coreVersion, array $packages): array
{
    $latest = null;
    $resolvedCount = 0;
    foreach ($packages as $pkg) {
        foreach ($pkg['versions'] as $v) {
            $resolvedCount++;
            $ts = strtotime((string) $v['published_at']);
            if ($ts !== false && ($latest === null || $ts > $latest)) {
                $latest = $ts;
            }
        }
    }

    return [
        'schema_version'         => '1',
        'type'                   => $type,
        'core_version'           => $coreVersion,
        // Derived from the newest resolved release's publish date, not wall-clock time:
        // a rebuild with no upstream change must be byte-identical, and time() would
        // defeat that on every single run.
        'generated_at'           => $latest !== null ? gmdate('Y-m-d\TH:i:s\Z', $latest) : '1970-01-01T00:00:00Z',
        'package_count'          => count($packages),
        'resolved_version_count' => $resolvedCount,
    ];
}

/* ------------------------------------------------------------------ *
 * Main
 * ------------------------------------------------------------------ */

function loadCompatibility(): void
{
    $compatPath = null;
    foreach ([__DIR__ . '/Compatibility.php', __DIR__ . '/../../oc-includes/osclass/classes/market/Compatibility.php'] as $candidate) {
        if (is_file($candidate)) {
            $compatPath = $candidate;
            break;
        }
    }
    if ($compatPath === null) {
        fwrite(STDERR, "Cannot find Compatibility.php. Put it beside this script (package-ci bundle layout).\n");
        exit(2);
    }
    if (!function_exists('__')) {
        function __($text, $domain = 'default')
        {
            return $text;
        }
    }
    require_once $compatPath;
}

function main(array $argv): int
{
    $options = parseArgs($argv);

    if (!in_array($options['type'], ['plugin', 'theme'], true) || $options['root'] === null
        || $options['out'] === null || $options['core-version'] === null) {
        printUsage();

        return 2;
    }

    $root = realpath($options['root']);
    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, "Not a directory: {$options['root']}\n");

        return 2;
    }

    $maxVersions = (int) $options['max-versions'];
    if ($maxVersions <= 0) {
        $maxVersions = DEFAULT_MAX_VERSIONS;
    }

    $out = $options['out'];
    if (!is_dir($out) && !@mkdir($out, 0775, true) && !is_dir($out)) {
        fwrite(STDERR, "Could not create output directory: {$out}\n");

        return 2;
    }
    $out = realpath($out);
    $cacheDir = $out . '/.build-cache';

    loadCompatibility();

    $type = $options['type'];
    $offline = $options['offline'];
    $coreVersion = $options['core-version'];
    $token = getenv('GITHUB_TOKEN') ?: null;

    $discoveryErrors = [];
    $inRepo = discoverInRepoPackages($root, $type, $discoveryErrors);
    $external = discoverExternalPackages($root, $type, $discoveryErrors);

    $hadFailure = false;
    foreach ($discoveryErrors as $e) {
        fwrite(STDERR, "build-catalog: {$e}\n");
        $hadFailure = true;
    }

    $allReleases = [];
    if ($inRepo !== []) {
        $registryRepo = detectRepoSlug($root);
        if ($registryRepo === null) {
            fwrite(STDERR, "build-catalog: could not determine the registry's own owner/repo (set GITHUB_REPOSITORY or add a git remote) — in-repo packages will resolve to 0 versions\n");
        } else {
            $result = ghGetReleases($registryRepo, $offline, $cacheDir, $token);
            $allReleases = $result['releases'];
            if ($result['error'] !== null) {
                fwrite(STDERR, "build-catalog: {$result['error']}\n");
            }
            if ($result['fatal']) {
                $hadFailure = true;
            }
        }
    }

    $packages = []; // slug => assembled package array, insertion order == resolution order

    foreach ($inRepo as $p) {
        $resolution = resolveInRepoVersions($p['slug'], $type, $allReleases, $offline, $cacheDir, $coreVersion, $maxVersions);
        $built = buildPackageEntry($p['slug'], $type, 'in-repo', $p['manifest'], $resolution, $p['dir'], $coreVersion, $out);
        $packages[$p['slug']] = $built['package'];
        fwrite(STDERR, "build-catalog: {$built['summary']}\n");
        $hadFailure = $hadFailure || $built['hadError'];
    }

    foreach ($external as $p) {
        $resolution = resolveExternalVersions($p['slug'], $type, $p['manifest'], $offline, $cacheDir, $token, $coreVersion, $maxVersions);
        $built = buildPackageEntry($p['slug'], $type, 'external', $p['manifest'], $resolution, null, $coreVersion, $out);
        $packages[$p['slug']] = $built['package'];
        fwrite(STDERR, "build-catalog: {$built['summary']}\n");
        $hadFailure = $hadFailure || $built['hadError'];
    }

    ksort($packages, SORT_STRING);

    writeJsonFile($out . '/v1/updates.json', buildUpdatesJson($packages));
    writeJsonFile($out . '/v1/index.json', buildIndexJson($packages));
    writeJsonFile($out . '/v1/categories.json', buildCategoriesJson($root, $packages));
    writeJsonFile($out . '/v1/manifest.json', buildManifestJson($type, $coreVersion, $packages));
    foreach ($packages as $slug => $pkg) {
        writeJsonFile($out . '/v1/packages/' . $slug . '.json', $pkg);
    }

    return $hadFailure ? 1 : 0;
}

exit(main($argv));
