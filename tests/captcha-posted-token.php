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
 * Pins osc_posted_captcha_token(): captcha tokens are opaque POST strings and
 * must not go through HTMLPurifier. Params::getParam() with defaults would
 * strip or alter characters that siteverify then rejects.
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

$_GET  = array('cf-turnstile-response' => 'from-query');
$_POST = array(
    'cf-turnstile-response' => $opaque,
    'g-recaptcha-response'  => $opaque,
);
Params::init();

harness_section('POST token is returned unchanged');
pin('turnstile POST', $opaque, osc_posted_captcha_token('cf-turnstile-response'));
pin('recaptcha POST', $opaque, osc_posted_captcha_token('g-recaptcha-response'));

harness_section('GET-only token is not accepted');
$_GET  = array('cf-turnstile-response' => $opaque);
$_POST = array();
Params::init();
pin('query string ignored', '', osc_posted_captcha_token('cf-turnstile-response'));

harness_section('non-string POST is not accepted');
$_GET  = array();
$_POST = array('cf-turnstile-response' => array($opaque));
Params::init();
pin('array POST ignored', '', osc_posted_captcha_token('cf-turnstile-response'));

harness_section('missing field');
$_POST = array();
Params::init();
pin('absent', '', osc_posted_captcha_token('cf-turnstile-response'));

harness_section('HTMLPurifier would alter markup in the same field');
$_GET  = array();
$_POST = array('cf-turnstile-response' => $tagged);
Params::init();
pin('raw keeps tags and ampersand', $tagged, osc_posted_captcha_token('cf-turnstile-response'));
check(
    'default getParam strips or encodes those characters',
    Params::getParam('cf-turnstile-response') !== $tagged
);

$fail = $GLOBALS['failCount'];
echo "\n" . ($fail === 0
        ? "ALL PASS ({$GLOBALS['okCount']})\n"
        : "FAILED: $fail (" . implode(', ', $GLOBALS['failLabels']) . ")\n");
exit($fail === 0 ? 0 : 1);
