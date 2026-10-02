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
 * Pins that Params' XSS check gives the purifier's exact output. Values with no `<`,
 * `>` or `&` skip the purifier, so every such value must come back byte-identical to
 * what HTMLPurifier (no tags allowed) returns.  Usage:  php tests/params-purify.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$config = HTMLPurifier_Config::createDefault();
$config->set('HTML.Allowed', '');
$config->set('Output.Newline', "\n");
$config->set('Cache.DefinitionImpl', null);
$purifier = new HTMLPurifier($config);

/** Params' result for one value, through the same path a request takes. */
function via_params($value)
{
    Params::setParam('v', $value);

    return Params::getParam('v');
}

harness_section('named values');

$named = array('', ' ', '2', 'Used bike, good condition', '  lead  ', "a\tb", "a\r\nb", "a\rb", "a\r\x01\nb",
    "é ü 中文 😀", "quote \" and '", "x\x00y", "x\x07y", "x\x7Fy", "\xC3\x28", "\xE2\x82", "\xF0\x9F\x98",
    "x\xEF\xBF\xBEy", "\u{FEFF}bom", "a\u{2028}b", "%3Cscript%3E", "javascript:alert(1)", "\xED\xA0\x80",
    "\u{00A0}nbsp", "\u{202E}rtl", '<b>bold</b>', '<script>alert(1)</script>x', 'a & b', 'a &amp; b',
    '&lt;x&gt;', '1 < 2', 'x > y', null, 123, 1.5, true, false);
foreach ($named as $v) {
    $want = $purifier->purify($v);
    check('matches the purifier: ' . json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE), via_params($v) === $want);
}
check('arrays are purified per value', via_params(array('a' => "x\r\ny", 'b' => '<i>z</i>'))
    === array('a' => "x\ny", 'b' => 'z'));

harness_section('random values');

$bad = 0;
$tested = 0;
foreach (array(1, 2, 3) as $seed) {
    mt_srand($seed);
    for ($i = 0; $i < 8000; $i++) {
        $s = '';
        for ($j = mt_rand(0, 24); $j > 0; $j--) {
            $s .= chr(mt_rand(0, 255));
        }
        $u = '';
        for ($j = mt_rand(1, 12); $j > 0; $j--) {
            $u .= mb_chr(mt_rand(1, 0x10FFFF)) ?: 'x';
        }
        foreach (array($s, $u) as $v) {
            $tested++;
            if (via_params($v) !== $purifier->purify($v)) {
                $bad++;
                if ($bad <= 3) {
                    echo '  differs for ', bin2hex($v), "\n";
                }
            }
        }
    }
}
check("{$tested} random byte and Unicode strings match the purifier", $bad === 0, "{$bad} differ");

exit(harness_result());
