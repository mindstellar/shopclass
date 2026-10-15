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
 * Pins AjaxResponse's cache headers: JSON is private by default, and shared() lets a browser
 * or shared cache keep it for five minutes. Headers only exist under a web server, so this
 * runs PHP's built-in one.
 *
 * DB-free.  Usage:  php tests/ajax-response-cache.php
 */

require_once __DIR__ . '/lib/harness.php';

if (isset($_GET['mode'])) {
    require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
    if ($_GET['mode'] === 'cookie') {
        setcookie('signed_out', '', 1);
    }
    $_GET['mode'] === 'json'
        ? \mindstellar\utility\AjaxResponse::json(array('ok' => 1))
        : \mindstellar\utility\AjaxResponse::shared(array('ok' => 1));

    return;
}

$port   = 20000 + random_int(0, 9999);
$server = proc_open(
    array(PHP_BINARY, '-S', '127.0.0.1:' . $port, __FILE__),
    array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')),
    $pipes
);
$cacheControl = static function (string $mode) use ($port): string {
    for ($try = 0; $try < 50; $try++) {
        $body = @file_get_contents('http://127.0.0.1:' . $port . '/?mode=' . $mode);
        if ($body !== false) {
            foreach ($http_response_header as $line) {
                if (stripos($line, 'Cache-Control:') === 0) {
                    return trim(substr($line, 14));
                }
            }

            return '';
        }
        usleep(100000);
    }

    return 'server did not start';
};

harness_section('AjaxResponse cache headers');
pin('json() is private', 'private, no-store', $cacheControl('json'));
pin('shared() may be kept five minutes by any cache', 'public, max-age=300, s-maxage=300', $cacheControl('shared'));
pin('...unless the answer also sets a cookie', 'private, no-store', $cacheControl('cookie'));

proc_terminate($server);
proc_close($server);

exit(harness_result());

/* file end: ./tests/ajax-response-cache.php */
