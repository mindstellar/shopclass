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
 * Tests for the type-safe Params accessors (getParamInt / getParamString / getParamArray).
 *
 * The request array is attacker-controlled in shape, not just content: `?id[]=1` makes the
 * raw value an array, and the historical `(int) Params::getParam('id')` juggled that array to
 * 1 instead of failing. These pin that the typed accessors never let an array reach a scalar
 * sink (and never let a scalar reach an array sink), while staying behaviour-identical to the
 * old code for ordinary scalar input.
 *
 * DB-free. Usage:  php tests/params-typed-accessors.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$_GET = array(
    'id'      => '42',
    'iPage'   => '3',
    'neg'     => '-7',
    'dirty'   => '5abc',
    'name'    => '<b>hi</b>there',
    'idArr'   => array('1', '2'),          // the array-injection shape
    'nameArr' => array('<b>x</b>', 'y'),
    'meta'    => array('4' => '<i>v</i>', '5' => 'w'),
);
$_POST = array();
Params::init();

harness_section('getParamInt — array-injection safe, scalar-identical');
pin('scalar id -> int',            42, Params::getParamInt('id'));
pin('numeric page -> int',          3, Params::getParamInt('iPage'));
pin('negative -> int',             -7, Params::getParamInt('neg'));
pin('dirty "5abc" -> 5 (as (int))', 5, Params::getParamInt('dirty'));
pin('ARRAY value -> default 0',     0, Params::getParamInt('idArr'));
pin('array -> custom default',     -1, Params::getParamInt('idArr', -1));
pin('missing -> default 0',         0, Params::getParamInt('nope'));
pin('missing -> custom default',    9, Params::getParamInt('nope', 9));

harness_section('getParamString — never returns an array');
pin('scalar purified (tags stripped)', 'hithere', Params::getParamString('name'));
pin('ARRAY value -> empty string',     '',        Params::getParamString('nameArr'));
pin('missing -> empty string',         '',        Params::getParamString('nope'));
check('matches getParam() for a scalar',
    Params::getParamString('name') === Params::getParam('name'));

harness_section('getParamArray — never returns a scalar');
$meta = Params::getParamArray('meta');
check('array value returned as array', is_array($meta) && count($meta) === 2);
check('array values are purified', ($meta['4'] ?? null) === 'v' && ($meta['5'] ?? null) === 'w');
pin('SCALAR value -> empty array',  array(), Params::getParamArray('id'));
pin('missing -> empty array',       array(), Params::getParamArray('nope'));

$fail = $GLOBALS['failCount'];
echo "\n" . ($fail === 0
        ? "ALL PASS ({$GLOBALS['okCount']})\n"
        : "FAILED: $fail (" . implode(', ', $GLOBALS['failLabels']) . ")\n");
exit($fail === 0 ? 0 : 1);
