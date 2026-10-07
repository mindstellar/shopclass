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
 * Every controller that calls refuseOnDemo() has it. The admin lost-password form called it
 * from a class that lacked it, so setting a new password ended in a fatal error.
 *
 * DB-free.  Usage: php tests/demo-guard-reachable.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$dir   = ABS_PATH . 'oc-includes/osclass/classes/controller';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$calls = array();
foreach ($files as $file) {
    $code = (string) file_get_contents((string) $file);
    if (strpos($code, '$this->refuseOnDemo(') === false || preg_match('/^class\s+(\w+)/m', $code, $m) !== 1) {
        continue;
    }
    $calls[] = $m[1];
}
sort($calls);

check('the lost-password form is among the callers', in_array('CAdminLogin', $calls, true));
$missing = array_values(array_filter($calls, static fn (string $class): bool => !method_exists($class, 'refuseOnDemo')));
pin('every caller has refuseOnDemo()', array(), $missing);

exit(harness_result());
