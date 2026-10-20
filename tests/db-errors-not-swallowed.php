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

/** A catch naming DbException, alone or in a list, with or without a variable, and its body. */
const DB_CATCH = '/catch\s*\(([^)]*\bDbException\b[^)]*)\)\s*\{(.*?)\}/s';

/** A body that hides the error: nothing, an empty answer, or a bare return, continue or break. */
function hides_error(string $body): bool
{
    $body = trim($body);

    return $body === '' || preg_match('/^(return\s*(array\(\)|\[\]|false|null|0|\'\'|"")?|continue|break)\s*;$/', $body) === 1;
}

$found = array();
foreach (harness_class_files('oc-includes/osclass/classes', static fn (string $path): bool => !str_contains($path, '/classes/model/') && !str_contains($path, '/classes/database/')) as $path => $source) {
    preg_match_all(DB_CATCH, harness_code_only($source), $m);
    $count = count(array_filter($m[2], 'hides_error'));
    if ($count > 0) {
        $found[$path] = $count;
    }
}

harness_section('The pattern');
check('an empty catch hides the error', hides_error(' '));
check('so does one that only returns an empty array, or returns, continues or breaks', hides_error(' return array(); ') && hides_error('return;') && hides_error('continue;'));
check('one that logs or answers with something does not', !hides_error("error_log(\$e->getMessage());\nreturn array();") && !hides_error('return $fallback;'));
check('a catch with no variable, or with a list of types, is read', preg_match(DB_CATCH, 'catch (DbException) { }') === 1 && preg_match(DB_CATCH, 'catch (DbException|\\RuntimeException $e) { }') === 1);

harness_section('New code reports database errors');
harness_shrink_only(SWALLOW_FIXTURE, $found, 'catch that hides a database error');

exit(harness_result());
