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
 * Pins CWebSearch::carriesLists(): a search whose params hold a list the friendly URL
 * cannot carry (a theme's opt[Size][]=M) is not redirected, so the choice is not lost.
 *
 * DB-free.  Usage: php tests/search-redirect-keeps-lists.php
 */

require_once __DIR__ . '/lib/harness.php';

$src  = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebSearch.php');
$from = strpos($src, 'public static function carriesLists(array $params)');
$to   = $from === false ? false : strpos($src, '/**', $from);
if ($from === false || $to === false) {
    fwrite(STDERR, "CWebSearch::carriesLists() not found\n");
    exit(1);
}
eval('function carries_lists(array $params) ' . substr($src, strpos($src, '{', $from), $to - strpos($src, '{', $from)));

harness_section('lists the friendly URL drops stop the redirect');

pin('opt[Size][]', true, carries_lists(array('sCategory' => 'fashion', 'sale' => '1', 'opt' => array('Size' => array('M')))));
pin('a plain list', true, carries_lists(array('option' => array('red', 'blue'))));

harness_section('what the friendly URL carries still redirects');

pin('plain values', false, carries_lists(array('sCategory' => 'fashion', 'sale' => '1')));
pin('several categories', false, carries_lists(array('sCategory' => array('3', '4'))));
pin('several sellers', false, carries_lists(array('sUser' => array('1', '2'))));
pin('custom fields', false, carries_lists(array('meta' => array('5' => 'x'))));
pin('an empty list', false, carries_lists(array('opt' => array())));

exit(harness_result());
