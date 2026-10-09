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

$GLOBALS['sep'] = array(',', '.');
function osc_locale_thousands_sep()
{
    return $GLOBALS['sep'][0];
}
function osc_locale_dec_point()
{
    return $GLOBALS['sep'][1];
}
function osc_current_user_locale()
{
    return 'en_US';
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
foreach (array('apiaccess/AccessEntry.php', 'webhook/Endpoint.php', 'webhook/Dispatcher.php', 'api/serializer/Format.php') as $f) {
    if (strpos((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/' . $f), 'TH:i:s') !== false) {
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
