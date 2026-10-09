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
 * osc_validate_email(), and the rule that every form uses it.
 *
 * The comment form used to carry its own pattern, `^.*?@.{2,}\..{2,3}$`, which was wrong in
 * both directions: it required a two or three character top-level domain, so it turned away
 * every .info, .online, .store and .agency address a visitor might have, while its `.*?`
 * local part accepted "bad name@example.com". Nobody would have noticed from the outside —
 * the form simply answered "Please fill the required field (email)" to a perfectly good
 * address.
 *
 * So there are two things here: the shared helper accepts what it should, and no form
 * validates an address any other way.  Usage:  php tests/validate-email.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hValidate.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('addresses a visitor may actually have');

/* The first four are exactly what the old pattern refused. */
foreach (array(
    'probe@example.info',
    'probe@example.online',
    'someone@shop.technology',
    'a@b.museum',
    'user@example.com',
    'user@example.co.uk',
    'first.last@example.com',
    'user+tag@example.com',
    "o'brien@example.com",
    'user_name@sub.example.com',
) as $email) {
    check('accepted: ' . $email, osc_validate_email($email), 'rejected');
}

harness_section('a top-level domain of any length');

/* The old pattern allowed 2 or 3 characters and nothing else, which is not a rule the
   domain name system has followed for a very long time. */
foreach (array(2, 3, 4, 6, 10, 13) as $len) {
    $tld = str_repeat('x', $len);
    check(
        sprintf('a %d-character TLD is accepted', $len),
        osc_validate_email('user@example.' . $tld),
        'user@example.' . $tld
    );
}

harness_section('addresses that are not addresses');

foreach (array(
    'bad name@example.com'  => 'a space in the local part',
    'no-at-sign.com'        => 'no @ at all',
    '@example.com'          => 'nothing before the @',
    'user@'                 => 'nothing after the @',
    'user@nodot'            => 'a domain with no dot',
    'user@example..com'     => 'a doubled dot',
    'user@.example.com'     => 'a leading dot',
    'user@example.com.'     => 'a trailing dot',
    'user@-example.com'     => 'a leading hyphen',
    'ab'                    => 'not an address at all',
) as $email => $why) {
    check('rejected (' . $why . '): ' . $email, !osc_validate_email($email), 'accepted');
}

harness_section('empty input follows the required flag');

check('empty is rejected when required', !osc_validate_email('', true));
check('empty is allowed when optional', osc_validate_email('', false));
check('...but a malformed optional address is still rejected', !osc_validate_email('nope', false));

/* ----------------------------------------------------------------------------
 * The rule that keeps it one behaviour: the comment form drifted for years
 * because it validated on its own instead of asking.
 * ------------------------------------------------------------------------- */
harness_section('no form rolls its own address pattern');

$offenders = array();
$roots     = array(__DIR__ . '/../oc-includes/osclass', __DIR__ . '/../oc-admin');
foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' || strpos($file->getPathname(), '/vendor/') !== false) {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        // An @ followed by a length-bounded run and a dot: the shape of a hand-rolled
        // address pattern, and of the one this test exists for.
        if (preg_match('/[\'"|][^\'"|]*@\\\\?\.\{\d/', $src)) {
            $offenders[] = str_replace(dirname(__DIR__) . '/', '', (string) realpath($file->getPathname()));
        }
    }
}
pin('every form validates through osc_validate_email()', '', implode(', ', $offenders));

harness_section('osc_validate_* forward to Validate');

$v = new \mindstellar\utility\Validate();
foreach (array('a@b.co', 'bad name@x.com', '', 'u@nodot') as $email) {
    pin('email ' . var_export($email, true), $v->email($email, false), osc_validate_email($email, false));
}
foreach (array('12', '0', '007', '-1', '1.5', ' 5', array('1')) as $n) {
    pin('int ' . json_encode($n), $v->int($n), osc_validate_int($n));
    pin('nozero ' . json_encode($n), $v->nozero($n), osc_validate_nozero($n));
}
check('nozero keeps the digits-only rule', osc_validate_nozero('007') && !osc_validate_nozero(' 5') && !osc_validate_nozero('+5'));
check('nozero refuses an array instead of throwing', !osc_validate_nozero(array('1')));
check('an empty optional number passes', osc_validate_number('') && osc_validate_number(null));
check('an empty required number fails', !osc_validate_number('', true));
check('a numeric string passes', osc_validate_number('12.5', true));
pin('text', $v->text('ab', 2), osc_validate_text('ab', 2));
pin('phone', $v->phone('+1 555 0100', 7), osc_validate_phone('+1 555 0100', 7));
pin('range', $v->range('abc', 1, 2), osc_validate_range('abc', 1, 2));
pin('username', $v->username('john_doe'), osc_validate_username('john_doe'));
foreach (array('https://example.com/a?b=1', 'not a url', '') as $url) {
    pin('url ' . var_export($url, true), $v->url($url), osc_validate_url($url));
}

$hv = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/helpers/hValidate.php');
preg_match_all('/^function (osc_validate_\w+)\(.*?\n\{\n(.*?)^\}/ms', $hv, $m, PREG_SET_ORDER);
$fat = array();
foreach ($m as $fn) {
    if (substr_count(trim($fn[2]), "\n") > 0 || strpos($fn[2], 'Validate())->') === false) {
        $fat[] = $fn[1];
    }
}
pin('every osc_validate_* is a one-line forwarder', '', implode(', ', $fat));

exit(harness_result());
