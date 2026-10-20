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
 * Pins two sanitize/escape fixes so they don't drift back:
 *
 * - Sanitize::username() keeps dots, unlike osc_sanitize_username(). The
 *   front-end username controllers must call Sanitize::username(), the same
 *   as UserActions, or a dotted username registered on the front end can
 *   never pass its own availability check or username change.
 * - osc_is_username_blacklisted() ignores dots and underscores, and an empty
 *   blacklist entry must not block every name.
 * - Escape::js() must match osc_esc_js() line for line; the two-array
 *   str_replace() it used to call breaks under PHP 8.
 * - Every osc_sanitize_* helper forwards to Sanitize and gives the same output,
 *   including the cases where the two used to differ.
 *
 * Usage: php tests/sanitize-drift.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\Escape;
use mindstellar\utility\Sanitize;

harness_section('Sanitize::username() keeps dots');

pin('a dotted username is kept as-is', 'john.doe', (new Sanitize())->username('john.doe'));
pin('Formatting::username() forwards to it', (new Sanitize())->username(' Jo-hn  doe.x '), (new \mindstellar\utility\Formatting())->username(' Jo-hn  doe.x '));
pin('Formatting::name() forwards to Sanitize::name()', (new Sanitize())->name('jOHN  o\'neil'), (new \mindstellar\utility\Formatting())->name('jOHN  o\'neil'));

harness_section('front-end username controllers call Sanitize::username(), not osc_sanitize_username()');

foreach (array(
    'oc-includes/osclass/classes/controller/CWebUser.php',
    'oc-includes/osclass/classes/controller/CWebAjax.php',
) as $relPath) {
    $src = (string) file_get_contents(ABS_PATH . $relPath);
    check($relPath . ' calls Sanitize username()', (bool) preg_match('/Sanitize\(\)\)->username\(/', $src));
    check($relPath . ' no longer calls osc_sanitize_username(', strpos($src, 'osc_sanitize_username(') === false);
}

harness_section('osc_is_username_blacklisted()');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';

$GLOBALS['blacklist'] = 'admin,user';
function osc_username_blacklist()
{
    return $GLOBALS['blacklist'];
}

check('exact entry is blocked', osc_is_username_blacklisted('admin'));
check('entry inside a name is blocked', osc_is_username_blacklisted('superadmin2'));
check('dotted look-alike is blocked', osc_is_username_blacklisted('ad.min'));
check('underscored look-alike is blocked', osc_is_username_blacklisted('us_er'));
check('digits-only name is blocked', osc_is_username_blacklisted('12345'));
check('an unrelated name passes', !osc_is_username_blacklisted('john.doe'));

$GLOBALS['blacklist'] = 'admin, user,';
check('a trailing comma does not block every name', !osc_is_username_blacklisted('john.doe'));
check('a spaced entry still blocks', osc_is_username_blacklisted('user1'));

$GLOBALS['blacklist'] = '';
check('an empty blacklist blocks nothing', !osc_is_username_blacklisted('john.doe'));

harness_section('osc_sanitize_* helpers give the same output as Sanitize');

if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($hook, $content = '', ...$args)
    {
        return $content;
    }
}
if (!function_exists('osc_current_user_locale')) {
    function osc_current_user_locale()
    {
        return 'en_US';
    }
}

$sanitize = new Sanitize();
$cases    = array(
    // helper => [method, [input => expected]]
    'osc_sanitize_username' => array('username', array(
        'john.doe'      => 'john.doe',      // the helper used to strip the dot
        ' john  doe '   => 'john_doe',      // the class used to drop the space: johndoe
        'a__b'          => 'a_b',
        'jöhn<b>'       => 'jhnb',
        '0'             => '0',             // the class used to return ''
    )),
    'osc_sanitize_phone' => array('phone', array(
        '+44 20 7946 0958'       => '+44 20 7946 0958', // the helper ran it together, the class dropped the spaces
        '(555) 123-4567 ext. 89' => '(555) 123-4567 89',
        '15551234567'            => '15551234567',      // the helper dropped the 1: 555-123-4567
        '089/1234567'            => '089/1234567',
        '  + 91 98765 43210'     => '+91 98765 43210',
        '1-800-FLOWERS'          => '1-800',
        '<script>x</script>'     => '',
        ''                       => '',
    )),
    'osc_sanitize_allcaps' => array('allcaps', array(
        'HELLO WORLD' => 'Hello world',
        'McDonald'    => 'McDonald',    // the class used to lower it: Mcdonald
        'ÉCOLE'       => 'École',       // the helper only looked at A-Z
        '123 MAIN ST' => '123 main st', // the helper needed a capital first
        'a & b'       => 'a & b',       // the class used to escape it: a &amp; b
    )),
    'osc_sanitize_name' => array('name', array(
        '  HELLO WORLD ' => 'Hello World',
        'élodie dupont'  => 'Élodie Dupont',
        '123 MAIN ST'    => '123 Main St',
        'McDonald'       => 'McDonald',
    )),
    'osc_sanitize_int' => array('int', array(
        '12'    => 12,
        '007'   => 7,  // the helper returned '007'
        '12abc' => 12,
        'abc12' => 0,  // the class kept the digits: '12'
        '1.5'   => 1,  // the class joined the digits: '15'
        '-5'    => -5,
        ''      => 0,  // the helper returned ''
    )),
    'osc_sanitize_url' => array('url', array(
        'https://a b.com/x' => 'https://ab.com/x',
        ''                  => '',
    )),
    'osc_sanitize_string' => array('slug', array(
        'Hello World!' => 'hello-world',
    )),
);

foreach ($cases as $helper => $case) {
    list($method, $inputs) = $case;
    foreach ($inputs as $input => $expected) {
        $input = (string) $input;
        pin($helper . '(' . var_export($input, true) . ')', $expected, $helper($input));
        pin('Sanitize::' . $method . '(' . var_export($input, true) . ')', $expected, $sanitize->$method($input));
    }
}

pin('osc_sanitize_int() of an array is 0', 0, osc_sanitize_int(array('1')));
pin('osc_sanitize_url() of an array is \'\'', '', osc_sanitize_url(array('x')));

$dirty = '<p>hi</p><script>alert(1)</script>';
pin('osc_sanitize_text() matches Sanitize::text()', $sanitize->text($dirty), osc_sanitize_text($dirty));
pin('osc_sanitize_text() walks arrays', array('a' => $sanitize->text($dirty)), osc_sanitize_text(array('a' => $dirty)));
pin('osc_sanitize_html() matches Sanitize::richHtml()', '<p>hi</p>', osc_sanitize_html($dirty));
pin('Sanitize::richHtml() keeps markup', '<p>hi</p>', $sanitize->richHtml($dirty));
pin('Sanitize::html() still escapes, unlike richHtml()', '&lt;p&gt;', $sanitize->html('<p>'));

harness_section('Escape::js() matches osc_esc_js()');

$input = "a\nb<br>Array";

pin('Escape::js() converts newlines and <br> without leaking the word Array', 'a\nb\nArray', Escape::js($input));
pin('Escape::js() matches osc_esc_js() for the same input', osc_esc_js($input), Escape::js($input));

exit(harness_result());
