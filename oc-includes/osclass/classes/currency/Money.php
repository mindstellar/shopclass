<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\currency;

/**
 * Money stored as micros: the value times 1,000,000, as t_item.i_price and the billing tables hold it.
 */
final class Money
{
    public const MICROS = 1000000;

    /**
     * The amount as a shopper reads it: the currency's symbol in the locale's currency format,
     * with decimals only when the amount has them ("$33", "$33.50"). The code stands in for a
     * currency the site does not list.
     */
    public static function format(int $micros, string $currency): string
    {
        $price    = $micros / self::MICROS;
        $decimals = round($price, 2) == floor($price) ? 0 : 2;
        $number   = number_format($price, $decimals, osc_locale_dec_point(), osc_locale_thousands_sep());

        return str_replace(
            array('{NUMBER}', '{CURRENCY}'),
            array($number, self::symbol($currency)),
            osc_locale_currency_format()
        );
    }

    /**
     * What the site prints for a currency: its symbol, or the upper-case code when it has no row.
     */
    public static function symbol(string $currency): string
    {
        $code = strtoupper($currency);
        try {
            $row = \Currency::getInstance()->findByPrimaryKey($code);
        } catch (\Throwable) {
            $row = false;
        }
        $symbol = is_array($row) ? trim((string) ($row['s_description'] ?? '')) : '';

        return $symbol === '' ? $code : $symbol;
    }

    /**
     * A plain decimal amount ("12.5") as micros. The caller checks it is numeric.
     */
    public static function toMicros(int|float|string $amount): int
    {
        return (int) round((float) $amount * self::MICROS);
    }

    /**
     * Micros as a plain two-decimal amount ("12.50"), for a form field.
     */
    public static function fromMicros(int $micros): string
    {
        return number_format($micros / self::MICROS, 2, '.', '');
    }

    /**
     * An amount typed in the site's locale ("1.234,50") as micros, or null when it is not a number.
     */
    public static function parse(string $input): ?int
    {
        $amount = str_replace(
            array(osc_locale_thousands_sep(), osc_locale_dec_point()),
            array('', '.'),
            trim($input)
        );

        return is_numeric($amount) ? self::toMicros($amount) : null;
    }
}
