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
 * Pins Rewrite::resolveRewrite() — the friendly-URL rewrite path extracted from
 * init(). It returns ['uri', 'params', 'not_found'] and never sends headers or
 * exits (init() turns not_found into the 404), so the rule rewrite, the param
 * extraction and the missing-.php flag are all assertable without a request.
 *
 * OC_ADMIN=false is defined so the public-front-controller branch (the one that
 * rewrites) runs; ABS_PATH points at a dir with no matching .php so the not_found
 * probe is deterministic.  Usage:  php tests/rewrite-resolve-rewrite.php
 */

define('OC_ADMIN', false);
define('ABS_PATH', __DIR__ . '/no-such-webroot/');

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Rewrite.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$REF = new ReflectionClass('Rewrite');

/** Unconstructed instance with $rules injected, then resolveRewrite() invoked. */
function resolve_rewrite(array $rules, string $uri): array
{
    global $REF;
    $rw   = $REF->newInstanceWithoutConstructor();
    $prop = $REF->getProperty('rules');
    $prop->setAccessible(true);
    $prop->setValue($rw, $rules);

    $m = $REF->getMethod('resolveRewrite');
    $m->setAccessible(true);

    return $m->invoke($rw, $uri);
}

$rules = array(
    '^listing/([0-9]+)$' => 'index.php?page=item&id=$1',
    '^(.+)$'             => 'index.php?page=search&sCategory=$1',
);

harness_section('resolveRewrite — rule rewrite + param extraction');
$r = resolve_rewrite($rules, 'listing/42');
check('not a 404', $r['not_found'] === false);
pin('uri rewritten to target', 'index.php?page=item&id=42', $r['uri']);
pin('page param extracted', 'item', $r['params']['page'] ?? null);
pin('id param extracted', '42', $r['params']['id'] ?? null);

harness_section('resolveRewrite — catch-all + encoded segment decoded once');
$r = resolve_rewrite($rules, 'a%2Bb');
pin('catch-all -> search', 'search', $r['params']['page'] ?? null);
pin('sCategory decoded once', 'a+b', $r['params']['sCategory'] ?? null);

harness_section('resolveRewrite — missing .php flags not_found (no exit)');
$r = resolve_rewrite($rules, 'missing.php');
check('not_found is true', $r['not_found'] === true);
check('no params on 404', $r['params'] === array());

exit(harness_result());
