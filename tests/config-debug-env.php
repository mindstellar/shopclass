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
 * OSC_DEBUG and OSC_DEBUG_LOG can be set from the environment, as Docker sites have no config.php.
 */

require_once __DIR__ . '/lib/harness.php';

$repo  = dirname(__DIR__);
$probe = static function (array $env) use ($repo): string {
    $code = 'define("ABS_PATH", ' . var_export($repo . '/', true) . '); require ' . var_export($repo . '/oc-includes/osclass/config-loader.php', true) . ';'
        . ' echo (defined("OSC_DEBUG") && OSC_DEBUG ? "1" : "0"), (defined("OSC_DEBUG_LOG") && OSC_DEBUG_LOG ? "1" : "0");';
    $vars = array('OSC_IGNORE_CONFIG_FILE=1');
    foreach ($env as $k => $v) {
        $vars[] = $k . '=' . escapeshellarg($v);
    }

    return (string) shell_exec('env -u OSC_DEBUG -u OSC_DEBUG_LOG ' . implode(' ', $vars) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
};

pin('with nothing set, debug and the debug log are off', '00', $probe(array()));
pin('OSC_DEBUG=1 turns debug on', '10', $probe(array('OSC_DEBUG' => '1')));
pin('OSC_DEBUG=1 and OSC_DEBUG_LOG=1 turn both on', '11', $probe(array('OSC_DEBUG' => '1', 'OSC_DEBUG_LOG' => '1')));
pin('OSC_DEBUG_LOG=1 alone turns debug on too', '11', $probe(array('OSC_DEBUG_LOG' => '1')));
pin('a false value leaves debug off', '00', $probe(array('OSC_DEBUG' => '0')));

exit(harness_result());
