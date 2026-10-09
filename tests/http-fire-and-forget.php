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
 * Utils::doRequest() sends a well-formed POST and does not wait for the answer, and
 * Validate::url() with its header check never asks a private address.
 *
 * No database.  Usage:  php tests/http-fire-and-forget.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_base_url()
{
    return 'http://localhost/';
}

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$server = stream_socket_server('tcp://127.0.0.1:0');
$port   = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

harness_section('Utils::doRequest');

$start = microtime(true);
$sent  = \mindstellar\utility\Utils::doRequest('http://127.0.0.1:' . $port . '/cron/run?a=1', array('page' => 'cron'));
check('it returns without waiting for an answer', microtime(true) - $start < 2);
check('it reports the bytes sent', is_int($sent) && $sent > 0);

$conn = stream_socket_accept($server, 2);
$raw  = $conn ? (string) stream_get_contents($conn) : '';
check('the request line keeps the path and query', str_starts_with($raw, "POST /cron/run?a=1 HTTP/1.1\r\n"), $raw);
check('the Host header carries the explicit port', str_contains($raw, "\r\nHost: 127.0.0.1:" . $port . "\r\n"));
check('lines end in CRLF and the body follows the blank line', str_ends_with($raw, "Connection: close\r\n\r\npage=cron"));
pin('a URL with no host is refused', false, \mindstellar\utility\Utils::doRequest('/relative', array()));

harness_section('Validate::url header check');

$validate = new \mindstellar\utility\Validate();
check('without the check a local address is a valid URL', $validate->url('http://127.0.0.1:' . $port . '/'));
$start = microtime(true);
check('with the check a private address fails', !$validate->url('http://127.0.0.1:' . $port . '/', false, true));
check('...without being asked', microtime(true) - $start < 1 && @stream_socket_accept($server, 0.2) === false);

exit(harness_result());
