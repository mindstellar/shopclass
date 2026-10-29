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
 * Pins that the messages_typing answer is never stored: the action sends Cache-Control: no-store
 * before anything else, and a real response carries it. Headers only exist under a web server, so
 * the runtime half runs PHP's built-in one.
 *
 * DB-free.  Usage:  php tests/messages-typing-header.php
 */

require_once __DIR__ . '/lib/harness.php';

if (isset($_GET['run'])) {
    require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
    function __($text)
    {
        return $text;
    }
    Params::init();
    $ajax = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $run  = new ReflectionMethod('CWebAjax', 'messagesTyping');
    $run->setAccessible(true);
    $run->invoke($ajax);

    return;
}

$source = harness_method_source(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebAjax.php', 'messagesTyping');
check(
    'the action sets no-store before any other statement',
    preg_match('/^\s*\{?\s*header\(\'Cache-Control: no-store\'\);/', (string) preg_replace('/^[^{]*\{/', '{', $source)) === 1
);

$port   = 20000 + random_int(0, 9999);
$server = proc_open(
    array(PHP_BINARY, '-S', '127.0.0.1:' . $port, __FILE__),
    array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')),
    $pipes
);
$cacheControl = 'server did not start';
for ($try = 0; $try < 50; $try++) {
    $stream = @fopen('http://127.0.0.1:' . $port . '/?run=1', 'rb', false, stream_context_create(array('http' => array('ignore_errors' => true))));
    if ($stream !== false) {
        $cacheControl = '';
        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? array() as $line) {
            if (stripos($line, 'Cache-Control:') === 0) {
                $cacheControl = trim(substr($line, 14));
            }
        }
        fclose($stream);
        break;
    }
    usleep(100000);
}
proc_terminate($server);
proc_close($server);

check('a served messages_typing answer is not stored', str_contains($cacheControl, 'no-store'), $cacheControl);

exit(harness_result());
