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

$missing = array();
foreach (harness_class_files() as $path => $source) {
    if (preg_match('/^namespace mindstellar\\\\/m', $source) === 1 && strpos($source, 'declare(strict_types=1);') === false) {
        $missing[] = $path;
    }
}

harness_section('new classes declare strict types');
harness_shrink_only(STRICT_FIXTURE, $missing, 'file without declare(strict_types=1)');

exit(harness_result());
