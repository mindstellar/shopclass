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

exit(harness_result());
