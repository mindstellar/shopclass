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
 * Pins that Rewrite::extractParams() url-decodes a rewritten URL's query exactly
 * once — the same as PHP's native $_GET parsing.
 *
 * The friendly-URL path feeds parse_str() (which already decodes) and used to
 * urldecode() the result a second time, so a captured segment like "a%2Bb"
 * resolved to "a b" instead of "a+b", and a double-encoded "%2520" collapsed to a
 * space. These pin the single-decode contract so the double-decode cannot return.
 *
 * DB-free (the private method is driven via reflection on an unconstructed
 * instance, so no Preference/DB access is triggered).  Usage:
 *   php tests/rewrite-param-decode.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Rewrite.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/**
 * Run extractParams() against a rewrite target and return one decoded param.
 *
 * @param string $uri   the internal target, e.g. index.php?page=search&sParams=...
 * @param string $param the param name to read back
 *
 * @return mixed
 */
function extract_param(string $uri, string $param)
{
    $_GET  = array();
    $_POST = array();
    Params::init();

    $ref  = new ReflectionClass('Rewrite');
    $rw   = $ref->newInstanceWithoutConstructor();
    $meth = $ref->getMethod('extractParams');
    $meth->setAccessible(true);
    $meth->invoke($rw, $uri);

    return Params::getParam($param);
}

harness_section('extractParams — decodes exactly once');
pin('%2B stays a literal plus, not a space', 'a+b', extract_param('index.php?page=search&sParams=a%2Bb', 'sParams'));
pin('double-encoded space stays %20',        '%20', extract_param('index.php?page=search&q=%2520', 'q'));
pin('%20 decodes to a single space',   'hello world', extract_param('index.php?page=search&q=hello%20world', 'q'));
pin('numeric id is untouched',                 '42', extract_param('index.php?page=item&id=42', 'id'));
pin('the page param itself survives',      'search', extract_param('index.php?page=search&id=42', 'page'));

harness_section('extractParams — edge cases');
pin('no query string sets nothing',              '', extract_param('index.php', 'page'));
pin('trailing %25 (invalid escape) kept as %', '10%', extract_param('index.php?page=search&n=10%25', 'n'));

exit(harness_result());
