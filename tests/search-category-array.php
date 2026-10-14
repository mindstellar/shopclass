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
 * Pins that an sCategory sent as an array skips the slug lookup on the search page. With friendly
 * URLs on, /search?sCategory[]=3 reached strpos() with an array: a TypeError and an HTTP 500.
 *
 * A source scan, so no bootstrap.  DB-free.  Usage: php tests/search-category-array.php
 */

require_once __DIR__ . '/lib/harness.php';

$src = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebSearch.php');

$array = strpos($src, "is_array(Params::getParam('sCategory'))");
$slug  = strpos($src, "strpos(Params::getParam('sCategory'), '/')");

harness_section('array sCategory on the search page');
check('the slug code was found to scan', $slug !== false);
check('an array sCategory is caught', $array !== false);
check('...before the slug code calls strpos() on it', $array !== false && $slug !== false && $array < $slug);

exit(harness_result());
