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
 * Every class in a mindstellar\ namespace declares strict_types=1. The files that did not
 * when the rule began are listed in tests/fixtures/no-strict-types.txt; the list may only
 * shrink. DB-free.  Usage: php tests/strict-types.php [--write]
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

const STRICT_FIXTURE = __DIR__ . '/fixtures/no-strict-types.txt';

$root    = realpath(__DIR__ . '/..') . '/';
$missing = array();
$files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'oc-includes/osclass/classes', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    if (preg_match('/^namespace mindstellar\\\\/m', $src) === 1 && strpos($src, 'declare(strict_types=1);') === false) {
        $missing[] = substr($file->getPathname(), strlen($root));
    }
}
sort($missing);

if (in_array('--write', $argv, true)) {
    file_put_contents(STRICT_FIXTURE, implode("\n", $missing) . "\n");
    echo 'Wrote ' . count($missing) . " files.\n";
    exit(0);
}

$allowed = array_filter(array_map('trim', file(STRICT_FIXTURE)));

harness_section('new classes declare strict types');
$new = array_values(array_diff($missing, $allowed));
pin('no file outside the old list lacks declare(strict_types=1)', array(), $new);

$fixed = array_values(array_diff($allowed, $missing));
pin('the old list names no file that now declares it (run --write)', array(), $fixed);

exit(harness_result());
