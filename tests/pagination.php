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
 * Pins the Pagination page-window math and rendered markup.
 *
 * computePages()/computeRawPages() are pure (no globals, no HTML), so the window
 * around the selected page, the first/prev/next/last boundary markers and the
 * force-limits trimming are asserted directly. The markup assertions cover the
 * regressions fixed alongside them: `list-last` must reach the final item, a
 * reused object must render identically, the active page carries aria-current,
 * and an out-of-range page is clamped.  Usage:  php tests/pagination.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Pagination.php';
require_once __DIR__ . '/lib/harness.php';

// Pagination's render methods call these osclass helpers; stub them DB-/bootstrap-free.
if (!function_exists('osc_esc_html')) {
    function osc_esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('__')) {
    function __($s, $d = '') { return $s; }
}

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** Render markup for a pageCount + 0-based selected page (matches the helper convention). */
function paginate(int $pageCount, int $selected0, array $extra = array()): string
{
    return (new Pagination(array_merge(array(
        'total'     => $pageCount,
        'selected'  => $selected0,
        'url'       => '/s?iPage={PAGE}',
        'first_url' => '/s',
        'sides'     => 2,
    ), $extra)))->doPagination();
}

// --------------------------------------------------------------- pure window math
harness_section('computePages — window and boundary markers');

$mid = Pagination::computePages(10, 5, 2);
pin('middle: window is selected ± sides', array(3, 4, 5, 6, 7), $mid['pages']);
pin('middle: prev is selected - 1', 4, $mid['prev'] ?? null);
pin('middle: next is selected + 1', 6, $mid['next'] ?? null);
check('middle: first kept (window past page 1)', isset($mid['first']) && $mid['first'] === 1);
check('middle: last kept (window before end)', isset($mid['last']) && $mid['last'] === 10);

$first = Pagination::computePages(10, 1, 2);
pin('first page: window starts at 1', array(1, 2, 3), $first['pages']);
check('first page: no prev marker', !isset($first['prev']));
check('first page: first trimmed (== window edge)', !isset($first['first']));
check('first page: next + last present', isset($first['next']) && isset($first['last']));

$last = Pagination::computePages(10, 10, 2);
pin('last page: window ends at pageCount', array(8, 9, 10), $last['pages']);
check('last page: no next marker', !isset($last['next']));
check('last page: last trimmed (== window edge)', !isset($last['last']));
check('last page: first + prev present', isset($last['first']) && isset($last['prev']));

harness_section('computePages — edge cases');
$clamped = Pagination::computePages(3, 99, 2);
check('out-of-range selected clamped into range', max($clamped['pages']) === 3 && !in_array(4, $clamped['pages'], true));
$forced = Pagination::computePages(5, 3, 2, true);
check('force_limits keeps first + last at the edges', isset($forced['first']) && isset($forced['last']));
$noSides = Pagination::computePages(10, 5, 0);
pin('sides = 0 yields just the selected page', array(5), $noSides['pages']);
$single = Pagination::computePages(1, 1, 2);
pin('single page: only page 1', array(1), $single['pages']);

// --------------------------------------------------------------- rendered markup
harness_section('doPagination — markup and regressions');

$html = paginate(10, 4); // middle page (0-based 4 => page 5 of 10)
check('exactly one list-first', substr_count($html, 'list-first') === 1);
check('exactly one list-last', substr_count($html, 'list-last') === 1);
check('active page has aria-current', strpos($html, 'aria-current="page"') !== false);
check('list is a navigation landmark', strpos($html, 'role="navigation"') !== false);
check('arrows carry aria-labels', strpos($html, 'aria-label="Next page"') !== false
    && strpos($html, 'aria-label="Previous page"') !== false);

$lastHtml = paginate(10, 9); // final page => no next/last arrows
$lastLi   = substr($lastHtml, strrpos($lastHtml, '<li'));
check('final page: last item carries list-last', strpos($lastLi, 'list-last') !== false);
check('final page: exactly one list-last', substr_count($lastHtml, 'list-last') === 1);

$obj = new Pagination(array('total' => 10, 'selected' => 4, 'url' => '/s?iPage={PAGE}', 'first_url' => '/s'));
check('doPagination() is idempotent (no class doubling)', $obj->doPagination() === $obj->doPagination());

$oob = paginate(3, 99);
check('out-of-range page renders no page number beyond total', strpos($oob, '>4<') === false);

exit(harness_result());
