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
 * A logged-out visitor's CSRF token is the same for everyone, so another site could fetch one
 * and post the login or register form for a visitor (login CSRF). The token check now also
 * refuses a logged-out request the browser marks as cross-site.
 *
 * DB-free.  Usage: php tests/csrf-cross-site.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

function osc_base_url($withIndex = false)
{
    return 'https://shop.example/';
}

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\Csrf;

// check() ends the request on a refusal, so each case runs in its own process.
if (isset($argv[1])) {
    function _m($text)
    {
        return $text;
    }
    define('OSC_CSRF_SECRET', 'test-secret');
    define('IS_AJAX', true);
    $case = json_decode($argv[1], true);
    if ($case['user'] !== '') {
        Session::getInstance()->_setEphemeral('userId', $case['user']);
    }
    $csrf  = new Csrf();
    $_POST = $_REQUEST = array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue());
    $_SERVER = $case['headers'];
    Params::init();
    ob_start();
    $csrf->check();
    ob_end_clean();
    echo 'PASSED';
    exit(0);
}

$cross = static function (array $headers): bool {
    $_SERVER = $headers;
    Params::init();

    return Csrf::isCrossSite();
};

harness_section('Sec-Fetch-Site decides when present');
check('cross-site is refused', $cross(array('HTTP_SEC_FETCH_SITE' => 'cross-site')));
check('same-origin passes', !$cross(array('HTTP_SEC_FETCH_SITE' => 'same-origin')));
check('same-site (www and bare domain) passes', !$cross(array('HTTP_SEC_FETCH_SITE' => 'same-site')));
check('none (typed or bookmarked) passes', !$cross(array('HTTP_SEC_FETCH_SITE' => 'none')));
check(
    'a matching Origin does not override cross-site',
    $cross(array('HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://shop.example'))
);

harness_section('Origin decides without Sec-Fetch-Site');
check('another site is refused', $cross(array('HTTP_ORIGIN' => 'https://evil.example')));
check('a look-alike host is refused', $cross(array('HTTP_ORIGIN' => 'https://shop.example.evil.example')));
check('the site host passes', !$cross(array('HTTP_ORIGIN' => 'https://shop.example')));
check('the site host with a port passes', !$cross(array('HTTP_ORIGIN' => 'http://shop.example:8080')));
check(
    'the requested host passes',
    !$cross(array('HTTP_ORIGIN' => 'http://127.0.0.1:8000', 'HTTP_HOST' => '127.0.0.1:8000'))
);
check('a garbled Origin is refused', $cross(array('HTTP_ORIGIN' => 'not a url')));
check('Origin: null passes', !$cross(array('HTTP_ORIGIN' => 'null')));
check('no headers at all pass (older browsers)', !$cross(array()));

harness_section('check() applies it to logged-out visitors only');
$checkPasses = static function (array $headers, string $user = ''): bool {
    $arg = json_encode(array('headers' => $headers, 'user' => $user));

    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($arg))) === 'PASSED';
};
check('a valid token from the same site passes', $checkPasses(array('HTTP_SEC_FETCH_SITE' => 'same-origin')));
check('a valid token from another site is refused when logged out', !$checkPasses(array('HTTP_SEC_FETCH_SITE' => 'cross-site')));
check('a signed-in user\'s valid token passes from another site', $checkPasses(array('HTTP_SEC_FETCH_SITE' => 'cross-site'), '5'));

exit(harness_result());
