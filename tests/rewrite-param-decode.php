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
 * Pins Rewrite::parseParams() — it url-decodes a rewritten URL's query exactly
 * once, the same as PHP's native $_GET parsing.
 *
 * The friendly-URL path feeds parse_str() (which already decodes) and once
 * urldecode()'d the result a second time, so a captured segment like "a%2Bb"
 * resolved to "a b" instead of "a+b" and a double-encoded "%2520" collapsed to a
 * space. parseParams() is pure (returns the map, sets nothing), so these assert
 * the returned array directly.  Usage:  php tests/rewrite-param-decode.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Rewrite.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** Invoke the private, pure parseParams() on an unconstructed instance (DB-free). */
function parse_params(string $uri): array
{
    $ref  = new ReflectionClass('Rewrite');
    $rw   = $ref->newInstanceWithoutConstructor();
    $meth = $ref->getMethod('parseParams');
    $meth->setAccessible(true);

    return $meth->invoke($rw, $uri);
}

harness_section('parseParams — decodes exactly once');
$p = parse_params('index.php?page=search&sParams=a%2Bb');
pin('%2B stays a literal plus, not a space', 'a+b', $p['sParams'] ?? null);
pin('page param preserved', 'search', $p['page'] ?? null);
pin('double-encoded space stays %20', '%20', parse_params('index.php?q=%2520')['q'] ?? null);
pin('%20 decodes to one space', 'hello world', parse_params('index.php?q=hello%20world')['q'] ?? null);
pin('numeric id untouched', '42', parse_params('index.php?id=42')['id'] ?? null);
pin('trailing %25 kept as %', '10%', parse_params('index.php?n=10%25')['n'] ?? null);

harness_section('parseParams — edge cases');
pin('no query string -> empty map', array(), parse_params('index.php'));
$multi = parse_params('index.php?a=1&b=2');
check('multiple params parsed', ($multi['a'] ?? null) === '1' && ($multi['b'] ?? null) === '2');

exit(harness_result());
