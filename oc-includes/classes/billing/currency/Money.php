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
     * The decimals a currency shows. With no code it is the site currency. With no database set up
     * (tests, the installer) it is the ISO 4217 default; a failed query still throws.
     */
    public static function decimals(?string $currency = null): int
    {
        $code = strtoupper(trim($currency ?? (function_exists('osc_currency') ? (string) osc_currency() : '')));
        $code = $code === '' ? 'USD' : $code;
        if (!defined('DB_TABLE_PREFIX')) {
            return CurrencyCode::decimals($code);
        }

        return CurrencyService::make()->decimals($code);
    }

    /**
     * The currency's decimals in the site's locale, then the currency code: "1,234.50 EUR". Admin
     * screens use it; shop pages use CurrencyService::make()->money() for the symbol format.
     */
    public static function format(int $micros, string $currency, ?int $decimals = null): string
    {
        return number_format($micros / self::MICROS, $decimals ?? self::decimals($currency), osc_locale_dec_point(), osc_locale_thousands_sep())
               . ' ' . strtoupper($currency);
    }

    /**
     * An amount as the shop shows it, in the locale's currency format: "₹1,234", "₹1,234.50",
     * "−₹150", "₹0". The decimals are the currency's (the site currency when $decimals is null);
     * whole amounts drop them unless $fixed, and zero is never "Free". A negative amount takes the minus sign
     * (U+2212), as the cart and checkout show it.
     */
    public static function display(int $micros, string $symbol, ?int $decimals = null, bool $fixed = false): string
    {
        $locale   = _osc_user_locale_row();
        $value    = abs($micros) / self::MICROS;
        $decimals = max(0, $decimals ?? self::decimals());
        $rounded  = round($value, $decimals);
        if (!$fixed && $decimals > 0 && $rounded == floor($rounded)) {
            $decimals = 0;
        }
        $number = number_format($value, $decimals, $locale['s_dec_point'], $locale['s_thousands_sep']);
        if (str_ends_with((string) ($locale['pk_c_code'] ?? ''), '_IN')) {
            // An empty separator is the column default, not a choice, so Indian prices still group.
            $sep    = (string) $locale['s_thousands_sep'];
            $number = self::lakh($value, $decimals, (string) $locale['s_dec_point'], $sep === '' ? ',' : $sep);
        }
        $text = str_replace(
            array('{NUMBER}', '{CURRENCY}'),
            array($number, $symbol),
            (string) $locale['s_currency_format']
        );

        return ($micros < 0 && $rounded > 0 ? "\u{2212}" : '') . $text;
    }

    /**
     * Indian grouping: a group of three, then groups of two ("12,34,56,789.50"). Used for _IN locales.
     */
    private static function lakh(float $value, int $decimals, string $point, string $sep): string
    {
        $parts = explode('.', number_format($value, $decimals, '.', ''));
        $int   = $parts[0];
        if (strlen($int) > 3) {
            $head = substr($int, 0, -3);
            $int  = implode($sep, str_split(str_pad($head, (int) (2 * ceil(strlen($head) / 2)), ' ', STR_PAD_LEFT), 2)) . $sep . substr($int, -3);
            $int  = ltrim($int);
        }

        return isset($parts[1]) ? $int . $point . $parts[1] : $int;
    }

    /**
     * A plain decimal amount ("12.5") as micros. The caller checks it is numeric.
     */
    public static function toMicros(int|float|string $amount): int
    {
        return (int) round((float) $amount * self::MICROS);
    }

    /**
     * Micros as a plain amount ("12.50"), for a form field. Null decimals is the site currency's.
     */
    public static function fromMicros(int $micros, ?int $decimals = null): string
    {
        return number_format($micros / self::MICROS, $decimals ?? self::decimals(), '.', '');
    }

    /**
     * An amount typed in the site's locale ("1.234,50") as micros, or null when it is not a number.
     * With $decimals set it is rounded half-up to that many decimals first.
     */
    public static function parse(string $input, ?int $decimals = null): ?int
    {
        $amount = str_replace(
            array(osc_locale_thousands_sep(), osc_locale_dec_point()),
            array('', '.'),
            trim($input)
        );

        if (!is_numeric($amount)) {
            return null;
        }

        return self::toMicros($decimals === null ? $amount : round((float) $amount, $decimals));
    }
}
