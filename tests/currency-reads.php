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
 * Currency reads on a page: once the currency table is loaded, prices, decimals, symbols and
 * the enabled list cost no further query. The enabled list leaves disabled currencies out, is
 * in code order, and a change made in the same request shows at once.
 * Usage: php tests/currency-reads.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\currency\CurrencyService;
use mindstellar\currency\Money;

$admin = api_admin_boot('osc_currency_reads');
$p     = DB_TABLE_PREFIX;
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

seed_locale($admin, 'en_US', 'English');
seed_currency($admin, 'USD', 'US Dollar');
seed_currency($admin, 'JPY', 'Yen');
seed_currency($admin, 'XAU', 'Gold');
$admin->query("UPDATE {$p}t_currency SET i_decimals = 0, s_description = '&yen;' WHERE pk_c_code = 'JPY'");
$admin->query("UPDATE {$p}t_currency SET b_enabled = 0 WHERE pk_c_code = 'XAU'");
Preference::getInstance()->set('currency', 'USD');
scratchdb_forget_cache();

harness_section('one read per page');
check('the query counter works here', harness_questions() >= 0);
$n = harness_query_count(static fn () => Currency::getInstance()->listAll());
pin('the currency table loads in one query', $n < 0 ? $n : 1, $n);
$n = harness_query_count(static function (): void {
    Money::decimals('USD');
    Money::decimals('jpy');
    Money::decimals();
    CurrencyService::make()->symbol('JPY');
    CurrencyService::make()->enabled();
});
pin('decimals, symbols and the enabled list read no more', $n < 0 ? $n : 0, $n);

harness_section('what they read');
pin('enabled currencies only, in code order', array('JPY', 'USD'), array_column(CurrencyService::make()->enabled(), 'pk_c_code'));
pin('each row as stored, values as strings', array('pk_c_code' => 'JPY', 's_name' => 'Yen', 's_description' => '&yen;', 'b_enabled' => '1', 'i_decimals' => '0'), CurrencyService::make()->enabled()[0]);
pin('decimals and symbol per currency', array(0, 2, '¥'), array(Money::decimals('JPY'), Money::decimals('USD'), CurrencyService::make()->symbol('jpy')));
pin('a disabled currency is still found by code', 'Gold', CurrencyService::make()->find('XAU')['s_name'] ?? null);
View::newInstance()->_exportVariableToView('item', array('fk_c_currency_code' => 'JPY'));
pin('a listing price takes its currency\'s symbol and decimals', '1235 &yen;', osc_format_price(1234500000));

harness_section('a change in the same request');
CurrencyService::make()->update('JPY', null, null, 3);
pin('new decimals show at once', 3, Money::decimals('JPY'));
pin('...and a listing price shows them and the new symbol', '1234.500 ¥', osc_format_price(1234500000));
CurrencyService::make()->create('CHF', 'Swiss Franc', 'Fr');
pin('a new currency joins the enabled list', array('CHF', 'JPY', 'USD'), array_column(CurrencyService::make()->enabled(), 'pk_c_code'));

exit(harness_result());
