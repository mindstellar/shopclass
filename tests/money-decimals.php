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
 * Money shows and reads the currency's own decimals (JPY 0, KWD 3), not the locale's.
 * With no database the lookup falls back to the ISO 4217 default, which is what is pinned here.
 *
 * Usage: php tests/money-decimals.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

$GLOBALS['sep'] = array(',', '.');
function _osc_user_locale_row(): array
{
    return array('s_thousands_sep' => $GLOBALS['sep'][0], 's_dec_point' => $GLOBALS['sep'][1], 's_currency_format' => '{CURRENCY}{NUMBER}', 'i_num_dec' => 2, 'pk_c_code' => 'en_US');
}
function osc_locale_thousands_sep()
{
    return _osc_user_locale_row()['s_thousands_sep'];
}
function osc_locale_dec_point()
{
    return _osc_user_locale_row()['s_dec_point'];
}

require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

use mindstellar\currency\Money;

harness_section('decimals per currency');

pin('JPY 0, KWD 3, USD 2, an unknown code 2', array(0, 3, 2, 2), array(Money::decimals('JPY'), Money::decimals('kwd'), Money::decimals('USD'), Money::decimals('ZZZ')));
pin('no code is the site currency, USD when none is set', 2, Money::decimals());

harness_section('display');

pin('JPY 1234.5 rounds to a whole yen', '¥1,235', Money::display(1234500000, '¥', Money::decimals('JPY')));
pin('KWD shows three decimals', 'KD1,234.567', Money::display(1234567000, 'KD', Money::decimals('KWD')));
pin('KWD whole amount drops its decimals (O2)', 'KD2', Money::display(2000000, 'KD', 3));
pin('USD is as before', array('$12', '$12.50'), array(Money::display(12000000, '$', 2), Money::display(12500000, '$', 2)));
pin('no decimals given means the site currency\'s', '$12.50', Money::display(12500000, '$'));
pin('a negative JPY keeps the minus', "\u{2212}¥150", Money::display(-150000000, '¥', 0));

harness_section('forms and parsing');

pin('fromMicros takes the decimals', array('1235', '1.235', '12.50'), array(Money::fromMicros(1234500000, 0), Money::fromMicros(1234500, 3), Money::fromMicros(12500000)));
pin('parse rounds half-up to the currency\'s decimals', array(101000000, 12350000, 12345000), array(Money::parse('100.5', 0), Money::parse('12.345', 2), Money::parse('12.345', 3)));
pin('parse with no decimals keeps every digit', 12345678, Money::parse('12.345678'));
pin('a JPY form field shows whole yen, a KWD one three decimals', array('1,235', '1.235'), array(osc_prepare_price(1234500000, 'JPY'), osc_prepare_price(1234500, 'KWD')));
pin('osc_prepare_price with no code uses the site currency', '12.50', osc_prepare_price(12500000));

harness_section('osc_format_price');

pin('the currency argument sets the decimals', array('¥1,235', 'KD1.235'), array(osc_format_price(1234500000, '¥', 'JPY'), osc_format_price(1234500, 'KD', 'KWD')));
pin('free and null prices are untouched', array('Free', 'Check with seller'), array(osc_format_price(0, '$', 'JPY'), osc_format_price(null)));

exit(harness_result());
