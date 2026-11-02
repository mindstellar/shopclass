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
 * The one rule for an ISO 4217 currency code, such as USD.
 */
final class CurrencyCode
{
    /** For schemas; `valid()` adds the end anchor a trailing newline cannot slip past. */
    public const PATTERN = '^[A-Z]{3}$';

    /** Currencies with no minor unit (ISO 4217). */
    private const ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Currencies with three minor digits (ISO 4217). */
    private const THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private function __construct()
    {
    }

    /**
     * Whether $code is already a well-formed currency code: upper case, no spaces.
     */
    public static function valid(string $code): bool
    {
        return preg_match('/' . self::PATTERN . '/D', $code) === 1;
    }

    /**
     * $code trimmed and upper-cased, or null when it is not a currency code.
     */
    public static function normalize(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return self::valid($code) ? $code : null;
    }

    /**
     * The ISO 4217 minor-unit digits of $code: 0, 3, or 2 for every other code.
     */
    public static function decimals(string $code): int
    {
        $code = strtoupper(trim($code));

        return match (true) {
            in_array($code, self::ZERO, true)  => 0,
            in_array($code, self::THREE, true) => 3,
            default                            => 2,
        };
    }

    /**
     * Every code whose decimals are not 2, as code => digits; the add screen hands it to its script.
     *
     * @return array<string,int>
     */
    public static function exceptions(): array
    {
        return array_fill_keys(self::ZERO, 0) + array_fill_keys(self::THREE, 3);
    }
}
