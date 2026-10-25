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
 * Title and description lengths count the text as typed, not as Params::purifyText()
 * stored it. "&" is stored as "&amp;", so a title the form's maxlength let through was
 * refused as too long. The stored title must still fit its column.
 *
 * Usage: php tests/listing-typed-length.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\listing\ListingValidator;

harness_section('lengths count the text as typed');

pin('an ampersand counts as one', 11, ListingValidator::typedLength('Tom &amp; Jerry'));
pin('angle brackets count as one each', 10, ListingValidator::typedLength('5 &lt; 10 &gt; 3'));
pin('quotes count as one', 5, ListingValidator::typedLength('&quot;a&quot;&#039;b'));
pin('multibyte counts characters', 5, ListingValidator::typedLength('héllo'));
pin('null is empty', 0, ListingValidator::typedLength(null));

harness_section('a title fits the setting as typed and the column as stored');

// 60 typed characters with five "&": 75 stored.
$title = str_repeat('a', 55) . str_repeat('&amp;', 5);
pin('fits a 60 setting as typed', true, ListingValidator::fitsTitle($title, 60));
pin('one over the setting is refused', false, ListingValidator::fitsTitle($title, 59));

// 90 typed characters with five "&": 110 stored, wider than the column.
$wide = str_repeat('a', 85) . str_repeat('&amp;', 5);
pin('the stored title must fit the column', false, ListingValidator::fitsTitle($wide, \Item::TITLE_WIDTH));
pin('a plain title at the column width fits', true, ListingValidator::fitsTitle(str_repeat('a', \Item::TITLE_WIDTH), \Item::TITLE_WIDTH));

exit(harness_result());
