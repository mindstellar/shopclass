<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * ListPaging reads the two values on an admin list screen that come straight from a URL.
 *
 * Before it, every screen parsed them itself and none of them checked the number was usable:
 * `?iDisplayLength=0` divided by zero and `?iDisplayLength=abc` multiplied by a string, so
 * either answered HTTP 500 on 13 of the 14 list screens; `?iPage=-3` sent two of them into a
 * redirect loop. Each case below is one of those URLs.
 *
 * DB-free.  Usage: php tests/admin-list-paging.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

// The real Params, driven through $_REQUEST the way a request drives it.
use mindstellar\admin\ListPaging;

/** Put one request on the wire. */
$request = static function (array $params): void {
    $_REQUEST = $params;
    $_GET     = $params;
    $_POST    = array();
    Params::init();
};

/** Put one request on the wire and read the page back. */
$page = static function ($raw) use ($request): int {
    $request($raw === null ? array() : array('iPage' => $raw));

    return ListPaging::page();
};

/** Same for the page size, with the screen's own default. */
$length = static function ($raw, int $default = 10) use ($request): int {
    $request($raw === null ? array() : array('iDisplayLength' => $raw));

    return ListPaging::length($default);
};

harness_section('Page number');

pin('a normal page is itself', 3, $page('3'));
pin('absent is page 1', 1, $page(null));
pin('empty is page 1', 1, $page(''));
pin('zero is page 1', 1, $page('0'));
pin('negative is page 1', 1, $page('-3'));
pin('a word is page 1', 1, $page('abc'));
pin('a float floors to its page', 2, $page('2.9'));
pin('an array cannot be a page number', 1, $page(array('2')));

// A screen builds its paging links from the parameter after reading it, so a corrected
// page has to be corrected in the request too or the links disagree with the rows.
$request(array('iPage' => '-3'));
ListPaging::page();
pin('the corrected page is written back', 1, (int) Params::getParam('iPage'));

harness_section('Page size');

pin('a normal size is itself', 25, $length('25'));
pin('absent falls to the screen default', 10, $length(null));
pin('empty falls to the screen default', 10, $length(''));
pin('a screen with its own default keeps it', 20, $length(null, 20));

// The two that answered 500.
pin('zero falls to the default rather than dividing by it', 10, $length('0'));
pin('a word falls to the default rather than multiplying by it', 10, $length('abc'));
pin('negative falls to the default', 10, $length('-5'));

pin('the largest size the control offers is allowed', 500, $length('500'));
pin('and so is a larger one asked for by hand', 800, $length('800'));
pin('more than that is capped', ListPaging::MAX_LENGTH, $length('100000'));
pin('a nonsense default is replaced, not trusted', ListPaging::DEFAULT_LENGTH, $length(null, 0));
pin('an over-cap default is capped too', ListPaging::MAX_LENGTH, $length(null, 99999));

harness_section('Row offset');

pin('page 1 starts at the beginning', 0, ListPaging::start(1, 25));
pin('page 2 starts after one page', 25, ListPaging::start(2, 25));
pin('page 4 at 10 a page', 30, ListPaging::start(4, 10));
// start() is handed the output of page(), which cannot be below 1 — but a caller that
// passes its own number must not be able to produce a negative LIMIT offset.
pin('an offset can never go negative', 0, ListPaging::start(0, 25));
pin('...however wrong the page', 0, ListPaging::start(-7, 25));

exit(harness_result());
