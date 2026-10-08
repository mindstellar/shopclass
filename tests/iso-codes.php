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
 * CurrencyCode and CountryCode: the one check every screen, store and API route uses.
 *
 * DB-free.  Usage: php tests/iso-codes.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\currency\CurrencyCode;
use mindstellar\location\CountryCode;

harness_section('CurrencyCode');
check('USD is valid', CurrencyCode::valid('USD'));
check('lower case is not valid as stored', !CurrencyCode::valid('usd'));
check('a trailing newline is refused', !CurrencyCode::valid("USD\n"));
check('two letters are refused', !CurrencyCode::valid('US'));
pin('normalize trims and upper-cases', 'EUR', CurrencyCode::normalize(' eur '));
pin('normalize refuses a newline inside', null, CurrencyCode::normalize("U\nSD"));
pin('normalize refuses four letters', null, CurrencyCode::normalize('USDT'));

harness_section('CountryCode');
check('IN is valid', CountryCode::valid('IN'));
check('a trailing newline is refused', !CountryCode::valid("IN\n"));
check('three letters are refused', !CountryCode::valid('IND'));
pin('normalize trims and upper-cases', 'DE', CountryCode::normalize(' de'));
pin('normalize refuses digits', null, CountryCode::normalize('1A'));

exit(harness_result());
