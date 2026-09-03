<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * How auto-cron dispatches its work.
 *
 * Three decisions live in one small block of index.php, and each is easy to undo by
 * accident while tidying:
 *
 *  - On FPM the work runs in-process after the response, not over an HTTP request the
 *    site makes to itself. That self request is only a way to detach, and an origin
 *    behind a proxy cannot make it: it resolves its own public host to the edge and
 *    never hairpins back, so no page=cron ever arrives and nothing reports it.
 *  - It is registered as a shutdown function rather than called inline. The CSRF guard
 *    holds the page in an output buffer and injects tokens from its own shutdown
 *    function; finishing the request before that runs sends the page without them.
 *  - The time limit is bounded. This occupies an FPM worker, and a worker that hangs is
 *    one the pool cannot serve from — set_time_limit(0) here would be more permissive
 *    than the self request it replaced, which ran under the site's normal limit.
 *
 * A source scan, so no bootstrap: the behaviour itself is SAPI-dependent and is covered
 * by running the thing.  Usage:  php tests/autocron-dispatch.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$src = (string) file_get_contents(__DIR__ . '/../index.php');

// The auto-cron block: from its guard to the end of the file.
$block = substr($src, (int) strpos($src, "osc_auto_cron()"));

harness_section('auto-cron dispatch');

check('the block was found to scan', strpos($block, 'autocron_fire') !== false);

check(
    'FPM runs the work in-process',
    strpos($block, 'fastcgi_finish_request') !== false
);
check(
    '...guarded by function_exists, so other SAPIs do not fatal',
    preg_match('/function_exists\(\s*[\'"]fastcgi_finish_request[\'"]\s*\)/', $block) === 1
);
// The bare call, not the function_exists() guard that necessarily precedes it.
preg_match('/(?<!function_exists\()\bfastcgi_finish_request\(\s*\)\s*;/', $block, $m, PREG_OFFSET_CAPTURE);
$callPos = $m[0][1] ?? false;
check(
    '...after the response, via a shutdown function, not inline',
    $callPos !== false
    && strpos($block, 'register_shutdown_function') !== false
    && strpos($block, 'register_shutdown_function') < $callPos
);
check(
    '...and it is cron.php that gets run',
    strpos($block, "osclass/cron.php") !== false
);

/* The self request is the fallback only. Both firing would run cron twice. */
$fpmPos      = strpos($block, 'fastcgi_finish_request');
$requestPos  = strpos($block, 'doRequest');
check('the self request is still there for SAPIs that cannot detach', $requestPos !== false);
check(
    '...reached only through the else arm, never alongside the FPM path',
    $fpmPos !== false && $requestPos !== false && $requestPos > $fpmPos
    && preg_match('/\}\s*else\s*\{[^}]*doRequest/s', $block) === 1
);

/* Bounded, never unlimited. */
check(
    'the run is time-limited',
    preg_match('/set_time_limit\(\s*\$?[A-Za-z_][A-Za-z0-9_]*\s*\)|set_time_limit\(\s*[1-9][0-9]*\s*\)/', $block) === 1
);
check(
    '...and never set_time_limit(0)',
    preg_match('/set_time_limit\(\s*0\s*\)/', $block) !== 1
);
check(
    'the client is not waited on once the page has gone',
    strpos($block, 'ignore_user_abort') !== false
);

exit(harness_result());
