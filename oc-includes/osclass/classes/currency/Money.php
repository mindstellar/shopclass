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
     * Two decimals in the site's locale and the currency code: "1,234.50 EUR".
     */
    public static function format(int $micros, string $currency): string
    {
        return number_format($micros / self::MICROS, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
               . ' ' . strtoupper($currency);
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
