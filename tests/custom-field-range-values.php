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
 * Pins the from/to pair a range custom field (a number on the search form, a date interval)
 * shows after a search.
 *
 * The search form blanked the pair and then refilled it for date intervals only, so a number
 * range came back to an empty pair of boxes while every other field kept what was typed. The
 * blank pair also defeated the generic "take it from the request" fallback below it: an array
 * of two empty strings is still a non-empty array.
 *
 * Usage: php tests/custom-field-range-values.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

// FieldForm pulls in the whole form stack; the resolver under test is static and needs none
// of it, so the class is loaded without executing a render.
require_once ABS_PATH . 'oc-includes/osclass/classes/form/FieldForm.php';

$range = static function ($meta, $fieldId = 52): array {
    Params::setParam('meta', $meta);

    return FieldForm::rangeFromRequest($fieldId);
};

harness_section('what was searched for comes back');

pin(
    'both ends',
    array('from' => '2015', 'to' => '2020'),
    $range(array(52 => array('from' => '2015', 'to' => '2020')))
);
pin(
    'one end only',
    array('from' => '3', 'to' => ''),
    $range(array(52 => array('from' => '3')))
);
pin(
    'decimals survive; an int cast would have rounded them',
    array('from' => '2.5', 'to' => '7.25'),
    $range(array(52 => array('from' => '2.5', 'to' => '7.25')))
);
pin(
    'spaces around a number are trimmed',
    array('from' => '10', 'to' => '20'),
    $range(array(52 => array('from' => ' 10 ', 'to' => "20\t")))
);
pin(
    'a negative bound is kept',
    array('from' => '-40', 'to' => '-5'),
    $range(array(52 => array('from' => '-40', 'to' => '-5')))
);

harness_section('nothing searched for leaves the boxes empty');

pin('no meta at all', array('from' => '', 'to' => ''), $range(null));
pin('meta without this field', array('from' => '', 'to' => ''), $range(array(99 => array('from' => '1'))));
pin('empty strings stay empty, never 0', array('from' => '', 'to' => ''), $range(array(52 => array('from' => '', 'to' => ''))));

harness_section('a crafted request cannot reshape the pair');

pin('an array where a bound belongs', array('from' => '', 'to' => '5'), $range(array(52 => array('from' => array('x'), 'to' => '5'))));
pin('a scalar where the pair belongs', array('from' => '', 'to' => ''), $range(array(52 => 'oops')));
pin('meta itself is a scalar', array('from' => '', 'to' => ''), $range('oops'));
pin('extra keys are dropped', array('from' => '1', 'to' => '2'), $range(array(52 => array('from' => '1', 'to' => '2', 'evil' => 'x'))));

harness_section('the render path resolves both range types through it');

$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/form/FieldForm.php');
check(
    'a number on the search form and a date interval share one branch',
    (bool) preg_match('/\$isRange\s*=\s*\$field\[\'e_type\'\]\s*===\s*\'DATEINTERVAL\'/', $src)
);
check('the pair is read back from the request', strpos($src, 'self::rangeFromRequest($field[\'pk_i_id\'])') !== false);
check('no int cast is left on a searched bound', strpos($src, "@(int)\$_meta[\$field['pk_i_id']]['from']") === false);

exit(harness_result());
