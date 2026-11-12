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
 * Pins the "not set up yet" page: with no config and no autoloader it renders, instead of a fatal.
 *
 * DB-free.  Usage: php tests/install-pre-boot.php
 */

require_once __DIR__ . '/lib/harness.php';

$script = tempnam(sys_get_temp_dir(), 'preboot');
file_put_contents($script, '<?php
$_SERVER = array("HTTP_HOST" => "localhost", "SERVER_NAME" => "localhost", "REQUEST_URI" => "/", "SCRIPT_NAME" => "/index.php", "REQUEST_METHOD" => "GET");
define("ABS_PATH", "' . dirname(__DIR__) . '/");
define("LIB_PATH", ABS_PATH . "oc-includes/");
require LIB_PATH . "osclass/helpers/hErrors.php";
_osc_die_not_ready("Not ready", "Run the installer.", true);');
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
unlink($script);

check('no fatal error', !str_contains($out, 'Fatal error') && !str_contains($out, 'Stack trace'));
check('the page shows the help link', str_contains($out, 'Need help?') && str_contains($out, 'github.com/mindstellar/shopclass/discussions'));

exit(harness_result());
