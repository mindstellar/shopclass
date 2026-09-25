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
 * Pins what the installer writes into config.php: the site address the CLI install
 * was given, values that cannot break out of their PHP string, and the --web-url check.
 * Usage:  php tests/install-config.php
 */

require_once __DIR__ . '/../oc-includes/osclass/install-functions.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('install_config_literal');

foreach (array("plain", "it's", 'back\\slash', "end\\", "\\'", "q\"d\$x", "'.system('id').'") as $raw) {
    try {
        $back = eval("return '" . install_config_literal($raw) . "';");
    } catch (ParseError $e) {
        $back = null;
    }
    check('round-trips ' . json_encode($raw), $back === $raw);
}

harness_section('install_web_url_valid');

foreach (array('http://localhost/', 'https://example.com', 'http://127.0.0.1:8101/', 'https://ex.com/sub/dir/') as $url) {
    check('accepts ' . $url, install_web_url_valid($url));
}
foreach (array('', '127.0.0.1:8101', 'ftp://ex.com/', 'javascript://x', "http://a/'x", 'http://a\\b',
    'https://ex.com/?a=b', 'https://ex.com/#top', "http://a/\n") as $url) {
    check('refuses ' . json_encode($url), !install_web_url_valid($url));
}

harness_section('install_urls under the CLI');

$_SERVER['HTTP_HOST']   = '';
$_SERVER['REQUEST_URI'] = '';
define('WEB_PATH', 'http://127.0.0.1:8101/');
define('REL_WEB_URL', '/');
check('uses the address the CLI defined', install_urls() === array('http://127.0.0.1:8101/', '/'));

harness_section('copy_config_file');

$dir = sys_get_temp_dir() . '/osc-install-config-' . getmypid() . '/';
@mkdir($dir);
copy(__DIR__ . '/../config-sample.php', $dir . 'config-sample.php');
define('ABS_PATH', $dir);
$want = array('my_oc_db', "u'*/x", "*/ echo 'PWNED'; /* oc_ \\", 'db_host', 'xy_');
check('writes config.php', copy_config_file($want[0], $want[1], $want[2], $want[3], $want[4]) === true);
$read = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'foreach (["DB_NAME","DB_USER","DB_PASSWORD","DB_HOST","DB_TABLE_PREFIX"] as $k) putenv($k);'
    . 'require ' . var_export($dir . 'config.php', true) . ';'
    . 'echo json_encode([DB_NAME, DB_USER, DB_PASSWORD, DB_HOST, DB_TABLE_PREFIX, REL_WEB_URL, WEB_PATH]);'
));
check('every value reads back unchanged, and nothing runs', json_decode((string) $read, true)
    === array_merge($want, array('/', 'http://127.0.0.1:8101/')), (string) $read);
array_map('unlink', glob($dir . '*'));
rmdir($dir);

exit(harness_result());
