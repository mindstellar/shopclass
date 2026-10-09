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
 * New code does not hide a database error: a DbException catch that does nothing, or only
 * returns an empty value, makes "no rows" and "the query failed" look the same. The legacy
 * models keep theirs for plugins. Files that did it when the rule began are listed with their
 * count in tests/fixtures/db-error-swallows.txt; a count may only shrink.
 * DB-free.  Usage: php tests/db-errors-not-swallowed.php [--write]
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

const SWALLOW_FIXTURE = __DIR__ . '/fixtures/db-error-swallows.txt';

/** A DbException catch and its body. */
const DB_CATCH = '/catch\s*\(\s*\\\\?(?:mindstellar\\\\database\\\\)?DbException\s+\$\w+\s*\)\s*\{(.*?)\}/s';

/** A body that hides the error: nothing at all, or only an empty answer. */
function hides_error(string $body): bool
{
    $body = trim($body);

    return $body === '' || preg_match('/^return\s*(array\(\)|\[\]|false|null|0|\'\'|"")\s*;$/', $body) === 1;
}

/**
 * Files outside the legacy models and the database layer that hide a DbException, with how often.
 *
 * @return array<string,int>
 */
function swallowing_files(): array
{
    $found = array();
    $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-includes/osclass/classes', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = substr($file->getPathname(), strlen(ABS_PATH));
        if ($file->getExtension() !== 'php' || str_contains($path, '/classes/model/') || str_contains($path, '/classes/database/')) {
            continue;
        }
        preg_match_all(DB_CATCH, harness_code_only((string) file_get_contents($file->getPathname())), $m);
        $count = count(array_filter($m[1], 'hides_error'));
        if ($count > 0) {
            $found[$path] = $count;
        }
    }
    ksort($found);

    return $found;
}

$found = swallowing_files();
if (in_array('--write', $argv, true)) {
    file_put_contents(SWALLOW_FIXTURE, implode("\n", array_map(static fn (string $p, int $n): string => "$p $n", array_keys($found), $found)) . "\n");
    echo 'Wrote ' . count($found) . " files.\n";
    exit(0);
}

$allowed = array();
foreach (array_filter(array_map('trim', file(SWALLOW_FIXTURE))) as $line) {
    [$path, $count]   = explode(' ', $line);
    $allowed[$path] = (int) $count;
}

harness_section('The pattern');
check('an empty catch hides the error', hides_error(' '));
check('so does one that only returns an empty array', hides_error(' return array(); '));
check('one that logs or answers with something does not', !hides_error("error_log(\$e->getMessage());\nreturn array();") && !hides_error('return $fallback;'));

harness_section('New code reports database errors');
$grown = array();
$fixed = array();
foreach ($found as $path => $count) {
    if ($count > ($allowed[$path] ?? 0)) {
        $grown[] = "$path: $count, was " . ($allowed[$path] ?? 0);
    }
}
foreach ($allowed as $path => $count) {
    if (($found[$path] ?? 0) < $count) {
        $fixed[] = "$path: " . ($found[$path] ?? 0) . ", listed $count";
    }
}
pin('no file hides more database errors than it did', array(), $grown);
pin('the list names no count that has gone down (run --write)', array(), $fixed);

exit(harness_result());
