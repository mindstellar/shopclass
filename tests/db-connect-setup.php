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
 * Pins what ConnectionManager does after the login: the database is selected, autocommit
 * is on, the relaxed sql_mode drops exactly its five modes, NO_BACKSLASH_ESCAPES is always
 * dropped, and all of it costs two round
 * trips. A missing database or a bad password still reports the same error codes.
 *
 * Runs itself a second time with OSC_DB_STRICT_MODE, the branch a new install takes.
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 * Usage:  php tests/db-connect-setup.php
 */

$strict = in_array('--strict', $argv, true);
define('OSC_INSTALLING', 1);
if ($strict) {
    define('OSC_DB_STRICT_MODE', true);
}
define('ABS_PATH', dirname(__DIR__) . '/');
foreach (array('DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME') as $c) {
    define($c, '');
}
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\ConnectionManager;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$host = getenv('DRIFT_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DRIFT_DB_PORT') ?: 3306);
$user = getenv('DRIFT_DB_USER') ?: 'root';
$pass = getenv('DRIFT_DB_PASS') !== false ? getenv('DRIFT_DB_PASS') : 'root';
$name = 'osc_connect_setup_' . getmypid();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($host, $user, $pass, '', $port);
$admin->query("CREATE DATABASE `$name`");
register_shutdown_function(static function () use ($admin, $name) {
    $admin->query("DROP DATABASE IF EXISTS `$name`");
});

/** A fixed server mode, so the relaxed branch has all five modes to strip; put back after. */
$previousMode = $admin->query('SELECT @@GLOBAL.sql_mode')->fetch_row()[0];
register_shutdown_function(static function () use ($admin, $previousMode) {
    $admin->query("SET GLOBAL sql_mode = '" . $admin->real_escape_string($previousMode) . "'");
});
$admin->query("SET GLOBAL sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
    . "ERROR_FOR_DIVISION_BY_ZERO,NO_BACKSLASH_ESCAPES,NO_ENGINE_SUBSTITUTION'");

harness_section($strict ? 'strict install' : 'relaxed install');

$before = mysqli_get_client_stats();
$cm     = new ConnectionManager($host, $user, $pass, $name, $port);
$after  = mysqli_get_client_stats();
$trips  = 0;
foreach ($after as $k => $v) {
    if (strpos($k, 'com_') === 0 && $k !== 'com_quit') {
        $trips += $v - $before[$k];
    }
}
$row = $cm->getHandle()->query('SELECT DATABASE(), @@SESSION.autocommit, @@SESSION.sql_mode, @@character_set_connection')
    ->fetch_row();
pin('the database is selected', $name, $row[0]);
pin('autocommit is on', '1', (string) $row[1]);
pin('the connection charset is utf8mb4', 'utf8mb4', $row[3]);
pin('sql_mode', $strict
    ? 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
    : 'NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', $row[2]);
check('setup after the login takes at most two round trips', $trips <= 2, "$trips round trips");
pin('a backslash escape reads the same on every install', 'a\'b',
    $cm->getHandle()->query("SELECT '" . $cm->getHandle()->real_escape_string("a'b") . "'")->fetch_row()[0]);

$missing = new ConnectionManager($host, $user, $pass, $name . '_missing', $port);
pin('a missing database still reports 1049', 1049, (int) $missing->getErrorLevel());
pin('... as a database error, not a login error', 0, (int) $missing->getErrorConnectionLevel());

$badPass = new ConnectionManager($host, $user, $pass . '-wrong', $name, $port);
pin('a bad password still reports 1045 at login', 1045, (int) $badPass->getErrorConnectionLevel());

if (!$strict) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --strict', $childExit);
    check('the strict run passed', $childExit === 0);
}

exit(harness_result());
