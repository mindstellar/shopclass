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
 * or shared cache keep it for five minutes, and localized() is private for a visitor with a chosen
 * language. Headers only exist under a web server, so this
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
    match ($_GET['mode']) {
        'json'      => \mindstellar\utility\AjaxResponse::json(array('ok' => 1)),
        'localized' => \mindstellar\utility\AjaxResponse::localized(array('ok' => 1)),
        default     => \mindstellar\utility\AjaxResponse::shared(array('ok' => 1)),
    };

    return;
}

$port   = 20000 + random_int(0, 9999);
$server = proc_open(
    array(PHP_BINARY, '-S', '127.0.0.1:' . $port, __FILE__),
    array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')),
    $pipes
);
$cacheControl = static function (string $mode, string $cookie = '') use ($port): string {
    $context = stream_context_create(array('http' => array('header' => $cookie === '' ? '' : 'Cookie: ' . $cookie)));
    for ($try = 0; $try < 50; $try++) {
        $stream = @fopen('http://127.0.0.1:' . $port . '/?mode=' . $mode, 'rb', false, $context);
        if ($stream !== false) {
            $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            fclose($stream);
            foreach ($headers as $line) {
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
pin('localized() is shared but revalidated by the browser', 'public, max-age=0, s-maxage=300, must-revalidate', $cacheControl('localized'));
pin('...and private for a visitor with a chosen language', 'private, no-store', $cacheControl('localized', 'oc_userLocale=de_DE'));

proc_terminate($server);
proc_close($server);

exit(harness_result());

/* file end: ./tests/ajax-response-cache.php */
