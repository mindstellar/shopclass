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
 * Pins the boot memory limit: php.ini sizes are read with their unit, and OSC_MEMORY_LIMIT
 * only ever raises the host limit, never cuts a bigger or unlimited one.
 * Usage:  php tests/memory-limit.php
 */

require_once __DIR__ . '/lib/harness.php';

define('ABS_PATH', dirname(__DIR__) . '/');
require ABS_PATH . 'oc-includes/osclass/default-constants.php';

harness_section('sizes in bytes');

foreach (array(
    '1G'   => 1073741824,
    '-1'   => -1,
    '512M' => 536870912,
    '16M'  => 16777216,
    '128K' => 131072,
    '2048' => 2048,
) as $size => $bytes) {
    pin($size, $bytes, osc_ini_bytes((string) $size));
}

harness_section('limit after boot');

/** The memory_limit a fresh PHP has after loading the constants file. */
function limit_after_boot(string $host, ?string $wanted = null): string
{
    $code = ($wanted !== null ? "define('OSC_MEMORY_LIMIT', '$wanted');" : '')
        . "define('ABS_PATH', " . var_export(ABS_PATH, true) . ');'
        . "require ABS_PATH . 'oc-includes/osclass/default-constants.php';"
        . "echo ini_get('memory_limit');";

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=' . escapeshellarg($host)
        . ' -r ' . escapeshellarg($code));
}

pin('host 1G stays', '1G', limit_after_boot('1G'));
pin('host unlimited stays', '-1', limit_after_boot('-1'));
pin('host 512M stays', '512M', limit_after_boot('512M'));
pin('host 16M is raised to 32M', '32M', limit_after_boot('16M'));
pin('128K wanted never lowers 16M', '16M', limit_after_boot('16M', '128K'));
pin('unlimited wanted lifts 512M', '-1', limit_after_boot('512M', '-1'));

exit(harness_result());
