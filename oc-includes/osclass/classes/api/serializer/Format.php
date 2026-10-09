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

namespace mindstellar\api\serializer;

use mindstellar\database\UtcDatetime;

/**
 * The API's value rules: integer ids, booleans, RFC 3339 UTC times, prices as decimal
 * strings, and null instead of an empty string.
 */
final class Format
{
    /** Dates core stores to mean "none". */
    private const NO_DATE = ['', '0000-00-00 00:00:00', '1000-01-01 00:00:00', '9999-12-31 23:59:59'];

    private function __construct()
    {
    }

    public static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function bool(mixed $value): bool
    {
        return (string) $value === '1' || $value === true;
    }

    /**
     * Stored plain text as the text it is: trimmed, null when empty, with the HTML encoding the
     * site stores text in (`&amp;`, `&quot;`) undone.
     */
    public static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = self::plain($value);

        return $value === '' ? null : $value;
    }

    /**
     * text() for a member that is never null: '' when empty.
     */
    public static function plain(mixed $value): string
    {
        return is_scalar($value) ? trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    public static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A stored local datetime as `2026-10-03T12:00:00Z`. Core writes times in the site's
     * timezone, which is PHP's default by the time a request runs.
     */
    public static function time(mixed $value): ?string
    {
        if (!is_string($value) || in_array($value, self::NO_DATE, true)) {
            return null;
        }
        static $zones = [];
        $local = date_default_timezone_get();
        $zones[$local] ??= new \DateTimeZone($local);
        $zones['UTC']  ??= new \DateTimeZone('UTC');
        $date = date_create_immutable($value, $zones[$local]);
        if ($date === false) {
            return null;
        }

        return $date->setTimezone($zones['UTC'])->format(UtcDatetime::RFC3339);
    }

    /**
     * `{code|id, name}` for a country, region or city, or null when neither is known.
     *
     * @param string $keyName 'code' (upper-cased) or 'id'
     *
     * @return array<string,mixed>|null
     */
    public static function place(mixed $key, mixed $name, string $keyName): ?array
    {
        $name = self::text($name);
        $key  = $keyName === 'code' ? self::text(is_string($key) ? strtoupper($key) : null) : self::id($key);
        if ($key === null && $name === null) {
            return null;
        }

        return [$keyName => $key, 'name' => $name];
    }

    /**
     * A unix timestamp as an RFC 3339 UTC time.
     */
    public static function timestamp(mixed $value): ?string
    {
        return is_numeric($value) ? UtcDatetime::rfc3339((int) $value) : null;
    }

    /**
     * A price in millionths (`i_price`) as a decimal string with at least two decimals:
     * 12500000 is "12.50", 12345678 is "12.345678". Integer maths, so nothing is lost.
     */
    public static function amount(mixed $micros): ?string
    {
        if (!is_numeric($micros)) {
            return null;
        }
        $micros   = (int) $micros;
        $sign     = $micros < 0 ? '-' : '';
        $micros   = abs($micros);
        // Not Money::fromMicros(): that rounds to 2 decimals, and the API keeps all 6.
        $fraction = rtrim(str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT), '0');

        return $sign . intdiv($micros, 1000000) . '.' . str_pad($fraction, 2, '0');
    }
}
