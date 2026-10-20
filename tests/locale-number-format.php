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
 * Pins the osc_locale_* number helpers: they read the visitor's language row, or the first
 * enabled language when the visitor's is not listed.
 *
 * DB-free.  Usage:  php tests/locale-number-format.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';

/** The site default when no visitor choice is found. */
function osc_language()
{
    return 'fr_FR';
}

$row = static fn (string $code, string $dec, string $sep, int $num, string $format): array => array(
    'pk_c_code'         => $code,
    's_dec_point'       => $dec,
    's_thousands_sep'   => $sep,
    'i_num_dec'         => $num,
    's_currency_format' => $format,
    's_direction'       => $code === 'de_DE' ? 'rtl-test' : 'ltr',
);
View::getInstance()->_exportVariableToView('locales', array(
    $row('en_US', '.', ',', 2, '{CURRENCY} {NUMBER}'),
    $row('de_DE', ',', '.', 0, '{NUMBER} {CURRENCY}'),
));

harness_section('the visitor\'s language');
$_COOKIE['oc_userLocale'] = 'de_DE';
pin('every helper reads the visitor\'s row', array(',', '.', 0, '{NUMBER} {CURRENCY}', 'rtl-test'), array(
    osc_locale_dec_point(),
    osc_locale_thousands_sep(),
    osc_locale_num_dec(),
    osc_locale_currency_format(),
    osc_locale_text_direction(),
));

harness_section('a language that is not enabled');
unset($_COOKIE['oc_userLocale']);
pin('falls back to the first enabled language', array('.', ',', 2), array(
    osc_locale_dec_point(),
    osc_locale_thousands_sep(),
    osc_locale_num_dec(),
));

exit(harness_result());

/* file end: ./tests/locale-number-format.php */
