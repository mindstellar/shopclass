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

namespace mindstellar\location;

/**
 * The one rule for an ISO 3166 two-letter country code, such as IN.
 */
final class CountryCode
{
    /** For schemas; `valid()` adds the end anchor a trailing newline cannot slip past. */
    public const PATTERN = '^[A-Z]{2}$';

    private function __construct()
    {
    }

    /**
     * Whether $code is already a well-formed country code: upper case, no spaces.
     */
    public static function valid(string $code): bool
    {
        return preg_match('/' . self::PATTERN . '/D', $code) === 1;
    }

    /**
     * $code trimmed and upper-cased, or null when it is not a country code.
     */
    public static function normalize(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return self::valid($code) ? $code : null;
    }
}
