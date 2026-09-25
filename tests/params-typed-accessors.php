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
pin('scalar id -> int', 42, Params::getParamInt('id'));
pin('numeric page -> int', 3, Params::getParamInt('iPage'));
pin('negative -> int', -7, Params::getParamInt('neg'));
pin('dirty "5abc" -> 5 (as (int))', 5, Params::getParamInt('dirty'));
pin('ARRAY value -> default 0', 0, Params::getParamInt('idArr'));
pin('array -> custom default', -1, Params::getParamInt('idArr', -1));
pin('missing -> default 0', 0, Params::getParamInt('nope'));
pin('missing -> custom default', 9, Params::getParamInt('nope', 9));

harness_section('getParamString — never returns an array');
pin('scalar purified (tags stripped)', 'hithere', Params::getParamString('name'));
pin('ARRAY value -> empty string', '', Params::getParamString('nameArr'));
pin('missing -> empty string', '', Params::getParamString('nope'));
check(
    'matches getParam() for a scalar',
    Params::getParamString('name') === Params::getParam('name')
);

harness_section('getParamArray — never returns a scalar');
$meta = Params::getParamArray('meta');
check('array value returned as array', is_array($meta) && count($meta) === 2);
check('array values are purified', ($meta['4'] ?? null) === 'v' && ($meta['5'] ?? null) === 'w');
pin('SCALAR value -> empty array', array(), Params::getParamArray('id'));
pin('missing -> empty array', array(), Params::getParamArray('nope'));

harness_section('withRequest — plain data read as the request, then the request is back');
$seen = Params::withRequest(array('title' => '<b>Bike</b>'), static fn () => array(Params::getParam('title'), Params::getParam('name')));
pin('inside, the given values are read and purified the same way', array('Bike', ''), $seen);
pin('after, the real request is back', 'hithere', Params::getParamString('name'));
try {
    Params::withRequest(array(), static function () {
        throw new RuntimeException('stop');
    });
} catch (RuntimeException $e) {
}
pin('and it is back after a throw too', 'hithere', Params::getParamString('name'));

// ---------------------------------------------------------------- bool / email / enum
$warnings = array();
set_error_handler(static function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;

    return true;
});
Params::withRequest(array(
    'on' => 'on', 'yes' => 'YES', 'one' => '1', 'off' => 'off', 'zero' => '0', 'empty' => '',
    'junk' => 'maybe', 'arr' => array('1'),
    'mail' => ' a.b@example.com ', 'badMail' => 'a@b', 'xssMail' => '"<script>alert(1)</script>"@x.com', 'attrMail' => '"a\\"onmouseover=alert(1)"@x.com',
    'ipMail' => 'a@[127.0.0.1]', 'quoteMail' => "o'brien@example.com", 'mailArr' => array('a@example.com'),
    'dir' => 'desc', 'dirCase' => 'DESC', 'dirBad' => 'desc; DROP', 'type' => '2', 'typeArr' => array('asc'),
), static function () {
    pin('bool: on', true, Params::getParamBool('on'));
    pin('bool: YES', true, Params::getParamBool('yes'));
    pin('bool: 1', true, Params::getParamBool('one'));
    pin('bool: off', false, Params::getParamBool('off', true));
    pin('bool: 0', false, Params::getParamBool('zero', true));
    pin('bool: empty is false', false, Params::getParamBool('empty', true));
    pin('bool: junk gives the default', true, Params::getParamBool('junk', true));
    pin('bool: array gives the default', true, Params::getParamBool('arr', true));
    pin('bool: missing gives the default', true, Params::getParamBool('nope', true));

    pin('email: valid, trimmed', 'a.b@example.com', Params::getParamEmail('mail'));
    pin('email: invalid gives the default', 'none', Params::getParamEmail('badMail', 'none'));
    pin('email: a quoted local part with markup is refused', '', Params::getParamEmail('xssMail'));
    pin('email: a quoted local part that breaks an attribute is refused', '', Params::getParamEmail('attrMail'));
    pin('email: an IP-literal domain is refused', '', Params::getParamEmail('ipMail'));
    pin("email: an apostrophe is a real address", "o'brien@example.com", Params::getParamEmail('quoteMail'));
    pin('email: array gives the default', '', Params::getParamEmail('mailArr'));
    pin('email: missing gives the default', '', Params::getParamEmail('nope'));

    pin('enum: exact match', 'desc', Params::getParamEnum('dir', array('asc', 'desc'), 'asc'));
    pin('enum: case matters', 'asc', Params::getParamEnum('dirCase', array('asc', 'desc'), 'asc'));
    pin('enum: anything else gives the default', 'asc', Params::getParamEnum('dirBad', array('asc', 'desc'), 'asc'));
    pin('enum: an int list returns the int', 2, Params::getParamEnum('type', array(0, 1, 2), 0));
    pin('enum: array gives the default', 'asc', Params::getParamEnum('typeArr', array('asc', 'desc'), 'asc'));
    pin('enum: missing gives the default', null, Params::getParamEnum('nope', array('asc')));
});
restore_error_handler();
pin('bool / email / enum: no array ever reaches a string cast', array(), $warnings);

$fail = $GLOBALS['failCount'];
echo "\n" . ($fail === 0
        ? "ALL PASS ({$GLOBALS['okCount']})\n"
        : "FAILED: $fail (" . implode(', ', $GLOBALS['failLabels']) . ")\n");
exit($fail === 0 ? 0 : 1);
