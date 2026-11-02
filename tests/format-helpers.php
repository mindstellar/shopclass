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
 * One formatter per job: money (Money), byte sizes (Formatting::bytes), RFC 3339 times
 * (UtcDatetime::rfc3339) and slugs (Formatting::formatSlug). The old helpers forward to them.
 *
 * Usage: php tests/format-helpers.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

$GLOBALS['sep']     = array(',', '.');
$GLOBALS['money']   = array('{CURRENCY}{NUMBER}', 2);
$GLOBALS['lookups'] = 0;
// Like the real helpers, each osc_locale_*() reads the locale row, so lookups can be counted.
function _osc_user_locale_row(): array
{
    $GLOBALS['lookups']++;

    return array('s_thousands_sep' => $GLOBALS['sep'][0], 's_dec_point' => $GLOBALS['sep'][1], 's_currency_format' => $GLOBALS['money'][0], 'i_num_dec' => $GLOBALS['money'][1], 'pk_c_code' => $GLOBALS['code'] ?? 'en_US');
}
function osc_locale_thousands_sep()
{
    return _osc_user_locale_row()['s_thousands_sep'];
}
function osc_locale_dec_point()
{
    return _osc_user_locale_row()['s_dec_point'];
}
function osc_current_user_locale()
{
    return 'en_US';
}
function osc_locale_currency_format()
{
    return _osc_user_locale_row()['s_currency_format'];
}
function osc_locale_num_dec()
{
    return _osc_user_locale_row()['i_num_dec'];
}

require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/market.php';

use mindstellar\api\serializer\Format;
use mindstellar\currency\Money;
use mindstellar\database\UtcDatetime;
use mindstellar\utility\Formatting;

harness_section('money');

pin('format', '1,234.50 EUR', Money::format(1234500000, 'eur'));
pin('osc_admin_money forwards', Money::format(1234500000, 'eur'), osc_admin_money('1234500000', 'eur'));
pin('toMicros rounds to the micro', 12345679, Money::toMicros('12.3456789'));
pin('fromMicros is a plain two-decimal amount for a form', '1234.50', Money::fromMicros(1234500000));
pin('parse reads the locale', 1234500000, Money::parse(' 1,234.5 '));
pin('parse refuses what is not a number', null, Money::parse('12 EUR'));
$GLOBALS['sep'] = array('.', ',');
pin('parse with a comma decimal point', 1234500000, Money::parse('1.234,5'));
pin('format with a comma decimal point', '1.234,50 USD', Money::format(1234500000, 'USD'));
$GLOBALS['sep'] = array(',', '.');
pin('display uses the locale\'s currency format and drops whole decimals', ['₹1,234', '₹1,234.50'], [Money::display(1234000000, '₹'), Money::display(1234500000, '₹')]);
pin('display puts the minus before the whole amount', ["\u{2212}₹150", "\u{2212}₹119.70"], [Money::display(-150000000, '₹'), Money::display(-119700000, '₹')]);
pin('display shows zero as an amount, not "Free"', '₹0', Money::display(0, '₹'));
$GLOBALS['code'] = 'en_IN';
pin('en_IN groups in lakhs', ['₹0', '₹999', '₹1,000', '₹1,00,000', '₹12,34,567.50', "\u{2212}₹1,20,000", '₹12,34,56,789.50'], array_map(fn ($m) => Money::display($m, '₹'), [0, 999000000, 1000000000, 100000000000, 1234567500000, -120000000000, 123456789500000]));
$GLOBALS['code'] = 'hi_IN';
pin('hi_IN groups in lakhs', '₹1,20,000', Money::display(120000000000, '₹'));
$keep = $GLOBALS['sep'];
$GLOBALS['sep'] = array('', '.');
pin('an Indian locale with no separator set still groups with commas', '₹1,20,000', Money::display(120000000000, '₹'));
$GLOBALS['sep'] = $keep;
$GLOBALS['code'] = 'en_US';
pin('en_US keeps groups of three', '₹1,234,567.50', Money::display(1234567500000, '₹'));
unset($GLOBALS['code']);
$GLOBALS['money'] = array('{NUMBER} {CURRENCY}', 2);
pin('display with the symbol after the number', "\u{2212}12.50 €", Money::display(-12500000, '€'));
$GLOBALS['money'] = array('{CURRENCY}{NUMBER}', 2);
$GLOBALS['lookups'] = 0;
Money::display(1234500000, '₹');
pin('display reads the locale row once', 1, $GLOBALS['lookups']);

harness_section('byte sizes');

pin('Formatting::bytes', '63.9 MB', Formatting::bytes(67003596));
pin('bytes at the unit edges, past TB staying in TB', array('0 B', '1023 B', '1 KB', '1 TB', '1024 TB'), array_map(array(Formatting::class, 'bytes'), array(0, 1023, 1024, 1024 ** 4, 1024 ** 5)));
pin('one php.ini size parser: -1, a bare number, lower case, T', array(-1, 900, 512 * 1024, 2 * 1024 ** 4), array_map(array(Formatting::class, 'iniBytes'), array('-1', '900', '512k', '2T')));
pin('SystemChecks and the media screen forward to it', array(Formatting::iniBytes('8M'), intdiv(Formatting::iniBytes('8M'), 1024)), array(\mindstellar\admin\SystemChecks::iniBytes('8M'), \mindstellar\admin\form\MediaSettingsScreen::sizeToKb('8M')));
pin('osc_market_format_size forwards', Formatting::bytes(1300000), osc_market_format_size(1300000));
pin('osc_market_format_size is empty for nothing', '', osc_market_format_size(0));

harness_section('RFC 3339 times');

pin('UtcDatetime::rfc3339', '2026-01-31T12:00:00Z', UtcDatetime::rfc3339(1769860800));
pin('Format::timestamp uses it', UtcDatetime::rfc3339(1769860800), Format::timestamp('1769860800'));
$typed = array();
foreach (array('classes/user/apikey/AccessEntry.php', 'classes/webhook/Endpoint.php', 'classes/webhook/Dispatcher.php', 'classes/api/serializer/Format.php') as $f) {
    $source = @file_get_contents(ABS_PATH . 'oc-includes/' . $f);
    if ($source === false || strpos($source, 'TH:i:s') !== false) {
        $typed[] = $f;
    }
}
pin('no caller types the format again', array(), $typed);

harness_section('slugs');

pin('osc_sanitizeString forwards to formatSlug', (new Formatting())->formatSlug('Ünïcode Café %41 it\'s'), osc_sanitizeString('Ünïcode Café %41 it\'s'));
pin('formatSlug drops a percent escape', 'a41b-c', (new Formatting())->formatSlug('a%41b %c'));
pin('formatSlug folds accents', 'creme-brulee', (new Formatting())->formatSlug('Crème brûlée'));
pin('remove_accents forwards', 'Ecole', remove_accents('École'));

exit(harness_result());
