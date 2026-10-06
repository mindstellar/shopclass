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
 * An API response ends in exit, so it never reaches index.php's auto-cron. Response::send()
 * hands the shared auto-cron entry a tick once the answer is out, and that entry does
 * nothing when auto-cron is off.  Usage:  php tests/api-auto-cron.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$response = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/api/Response.php');
$jobs     = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/helpers/hJobs.php');
$index    = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/routing/FrontController.php');

harness_section('API path ticks auto-cron after sending');

$send = substr($response, (int) strpos($response, 'public function send('));
$send = substr($send, 0, (int) strpos($send, 'public static function etagMatches'));
$echo = strpos($send, "echo \$out['body']");
$flush = strpos($send, 'flush();');
$tick = strpos($send, 'self::tickAutoCron()');
$exit = strpos($send, 'exit;');
check(
    'send() ticks after the body is written and flushed, before exit',
    $echo !== false && $flush > $echo && $tick > $flush && $exit > $tick
);
check(
    'the tick calls the shared entry with the response marked as sent',
    preg_match('/osc_auto_cron_dispatch\(true\)/', $response) === 1
);
check(
    'a failing tick is caught and cannot change the response',
    preg_match('/try\s*\{[^}]*osc_auto_cron_dispatch\(true\);\s*\}\s*catch\s*\(\\\\Throwable/', $response) === 1
);
check(
    'the tick runs as nobody, not as the caller',
    preg_match('/WebIdentity::forget\(\);\s*osc_auto_cron_dispatch\(true\)/', $response) === 1
);
check('the front controller uses the same entry', strpos($index, 'osc_auto_cron_dispatch();') !== false);
check('the throttle lives only in the shared function', strpos($index, 'autocron_tick') === false);

harness_section('shared entry does nothing when auto-cron is off');

preg_match('/function osc_auto_cron_dispatch\(.*?\n\}\n/s', $jobs, $m);
$fn = $m[0] ?? '';
check('the function was found', $fn !== '');

if (!function_exists('osc_auto_cron')) {
    function osc_auto_cron()
    {
        return false;
    }
}
if (!function_exists('osc_maintenance_is_restoring')) {
    function osc_maintenance_is_restoring($f)
    {
        return false;
    }
}
if (!defined('ABS_PATH')) {
    define('ABS_PATH', __DIR__ . '/');
}
// Reaching the throttle would need the cache factory, which does not exist here.
eval($fn);
$err = null;
try {
    osc_auto_cron_dispatch(true);
} catch (\Throwable $e) {
    $err = $e->getMessage();
}
check('off: returns before the throttle, cache or cron are touched', $err === null);

exit(harness_result());
