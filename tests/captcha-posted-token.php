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
 * Captcha tokens are opaque POST strings and must not go through HTMLPurifier.
 * Params::getParam() with defaults would strip or alter characters that
 * siteverify then rejects. Call sites use getParamString($name, false, false)
 * plus a POST method check — no extra public helper.
 *
 * DB-free. Usage:  php tests/captcha-posted-token.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '0');
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/../oc-includes/osclass/utils.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$opaque = '0.aaaa.bbbb+cccc/dddd=eeee';
$tagged = '0.aa<bb>cc&dd';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET  = array('cf-turnstile-response' => 'from-query');
$_POST = array(
    'cf-turnstile-response' => $opaque,
    'g-recaptcha-response'  => $opaque,
);
Params::init();

harness_section('unpurified Params keeps the posted token');
pin('turnstile POST', $opaque, Params::getParamString('cf-turnstile-response', false, false));
pin('recaptcha POST', $opaque, Params::getParamString('g-recaptcha-response', false, false));

harness_section('GET-only is rejected at the captcha call site');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET  = array('g-recaptcha-response' => $opaque, 'cf-turnstile-response' => $opaque);
$_POST = array();
Params::init();
check('osc_check_recaptcha ignores GET', osc_check_recaptcha() === false);

harness_section('non-string POST is not accepted');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET  = array();
$_POST = array('cf-turnstile-response' => array($opaque));
Params::init();
pin('array POST ignored', '', Params::getParamString('cf-turnstile-response', false, false));

harness_section('missing field');
$_POST = array();
Params::init();
pin('absent', '', Params::getParamString('cf-turnstile-response', false, false));
check('empty POST fails recaptcha', osc_check_recaptcha() === false);

harness_section('HTMLPurifier would alter markup in the same field');
$_GET  = array();
$_POST = array('cf-turnstile-response' => $tagged);
Params::init();
pin('raw keeps tags and ampersand', $tagged, Params::getParamString('cf-turnstile-response', false, false));
check(
    'default getParam strips or encodes those characters',
    Params::getParam('cf-turnstile-response') !== $tagged
);

exit(harness_result());
