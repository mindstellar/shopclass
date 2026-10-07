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

namespace mindstellar\utility;

/**
 * A date a client sends: a day, `2027-03-01`, or an RFC 3339 date-time with or without
 * fractions of a second. One reading for every date input, so they cannot drift apart.
 */
final class DateInput
{
    /** The same grammar as a regex, for a JSON Schema `pattern`. */
    public const PATTERN = '[0-9]{4}-[0-9]{2}-[0-9]{2}(T[0-9]{2}:[0-9]{2}(:[0-9]{2}(\\.[0-9]+)?)?(Z|[+-](0[0-9]|1[0-4]):[0-5][0-9]))?';

    private function __construct()
    {
    }

    /**
     * The date in UTC: a day is its first second, a date-time its own moment. Null for
     * anything else, including a day that does not exist such as 2026-02-30.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^' . self::PATTERN . '$/D', $value) !== 1) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        if (!str_contains($value, 'T')) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);

            return $day !== false && $day->format('Y-m-d') === $value && (int) $day->format('Y') >= 1000 ? $day : null;
        }
        // Fractions of a second change nothing a site stores, so they are dropped before reading.
        $whole = (string) preg_replace('/(T\d{2}:\d{2}:\d{2})\.\d+/', '$1', $value);
        $time  = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $whole, $utc)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:iP', $whole, $utc);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $time = $time->setTimezone($utc);

        // A year the database cannot store is no date for it.
        return (int) $time->format('Y') >= 1000 && (int) $time->format('Y') <= 9999 ? $time : null;
    }

    /**
     * Whether $value is a day only, with no time.
     */
    public static function isDay(string $value): bool
    {
        return !str_contains(trim($value), 'T');
    }
}
