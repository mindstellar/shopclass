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
 * osc_esc_html() skips its entity passes for a string with no "&". Pins that the short
 * path gives what the full one gives, over random strings with and without entities.
 *
 * Usage: php tests/esc-html-fast-path.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

/** The function as it was before the short path. */
function esc_html_full($str)
{
    $temp = '__TEMP_AMPERSANDS__';
    $str  = preg_replace("/&#(\d+);/", "$temp\\1;", $str);
    $str  = preg_replace("/&(\w+);/", "$temp\\1;", $str);
    $str  = htmlspecialchars($str);
    $str  = str_replace(array("'", '"'), array('&#39;', '&quot;'), $str);
    $str  = preg_replace("/$temp(\d+);/", "&#\\1;", $str);

    return preg_replace("/$temp(\w+);/", "&\\1;", $str);
}

$alphabet = array('a', 'Z', '0', ' ', '<', '>', '"', "'", '&', '#', ';', 'é', 'ह', '😀', "\n", 'amp', 'quot');
mt_srand(7);
$differ = array();
for ($i = 0; $i < 20000; $i++) {
    $s = '';
    for ($j = mt_rand(1, 12); $j > 0; $j--) {
        $s .= $alphabet[mt_rand(0, count($alphabet) - 1)];
    }
    if (osc_esc_html($s) !== esc_html_full($s)) {
        $differ[] = $s;
    }
}
pin('random strings escape as the full path does', array(), array_slice($differ, 0, 3));
pin('a plain string', 'Tom&#039;s &lt;b&gt; &quot;bike&quot;', osc_esc_html('Tom\'s <b> "bike"'));
pin('an entity is kept', 'R&amp;D &copy; &#169; &lt;', osc_esc_html('R&D &copy; &#169; <'));
pin('a number is escaped as text', '5', osc_esc_html(5));

exit(harness_result());
