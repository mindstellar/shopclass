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
 * The sign-in `return` path: only a path on this site, under its base URL, is taken; another
 * host, `//host`, a scheme, a backslash or a control character is refused.
 *
 * DB-free.  Usage: php tests/login-return-helpers.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('WEB_PATH', 'https://shop.example/sub/');

function osc_apply_filter($name, $value, ...$args)
{
    return $value;
}

function osc_esc_html($str = '')
{
    return htmlspecialchars((string) $str, ENT_QUOTES);
}

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\auth\SignIn;

harness_section('the path');
foreach (['/sub/checkout', '/sub/checkout?step=2', '/sub/', '/sub/orders/5#top'] as $ok) {
    pin("$ok is kept", 'https://shop.example' . $ok, SignIn::returnUrl($ok));
}
$refused = [
    'https://shop.example/sub/checkout', 'https://evil.test/sub/x', '//evil.test/sub/x', '///evil.test',
    '/\\evil.test', '/sub\\..\\x', 'javascript:alert(1)', 'sub/checkout', "/sub/check\nout", "/sub/\tx",
    '/sub/a b', '/sub/"x', '/sub/<x>', '/other/checkout', '/sub/index.php?page=login', '', str_repeat('/sub/a', 400),
];
foreach ($refused as $bad) {
    pin('refused: ' . json_encode($bad), '', SignIn::returnUrl($bad));
}
pin('not a string is refused', '', SignIn::returnUrl(array('/sub/x')));

harness_section('the link');
$login = 'https://shop.example/sub/index.php?page=login';
pin('a path is added', $login . '&return=' . rawurlencode('/sub/checkout'), SignIn::linkWithReturn($login, '/sub/checkout'));
pin('a full URL on this site is cut to its path and query', $login . '&return=' . rawurlencode('/sub/checkout?a=1'), SignIn::linkWithReturn($login, 'https://shop.example/sub/checkout?a=1#x'));
pin('a friendly URL gets ?', 'https://shop.example/sub/user/login?return=' . rawurlencode('/sub/checkout'), SignIn::linkWithReturn('https://shop.example/sub/user/login', '/sub/checkout'));
foreach (['', 'https://evil.test/sub/checkout', '//evil.test/', '/other/x', 'javascript:alert(1)'] as $bad) {
    pin('dropped: ' . json_encode($bad), $login, SignIn::linkWithReturn($login, $bad));
}

harness_section('the request');
$_GET = array('return' => '/sub/checkout');
Params::init();
pin('a valid return is read', '/sub/checkout', osc_login_return_path());
ob_start();
osc_login_return_field();
pin('and printed as a hidden field', '<input type="hidden" name="return" value="/sub/checkout" />', ob_get_clean());
$_GET = array('return' => '//evil.test/');
Params::init();
ob_start();
osc_login_return_field();
pin('an off-site one is neither read nor printed', array('', ''), array(osc_login_return_path(), ob_get_clean()));

exit(harness_result());
