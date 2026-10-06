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

namespace mindstellar\search\query;

use mindstellar\database\Connection;

/**
 * Search values as SQL text (the form toJson() and the plugin filters show) and as
 * bound parameters that compare the same way in MySQL.
 */
final class SqlValue
{
    /**
     * A value as SQL text: a number as it is (quoted when it has a leading zero),
     * a string escaped and quoted, a bool as 1/0, null as NULL.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function literal($value)
    {
        if (is_numeric($value)) {
            if (strlen((string)$value) > 1 && strpos((string)$value, '0') === 0) {
                return "'" . $value . "'";
            }

            return $value;
        }
        if (is_string($value)) {
            return "'" . self::escape($value) . "'";
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (null === $value) {
            return 'NULL';
        }

        return $value;
    }

    /**
     * The parameter that compares as literal() would: a number stays a number, so a
     * string column is still compared numerically.
     *
     * @param mixed $value
     *
     * @return int|float|string|null
     */
    public static function bind($value)
    {
        if (is_numeric($value)) {
            if (strlen((string)$value) > 1 && strpos((string)$value, '0') === 0) {
                return (string)$value;
            }

            return self::number($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (null === $value || is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string)$value : 'Array';
    }

    /**
     * What sprintf('%d') makes of literal(): a quoted leading-zero number is 0.
     *
     * @param mixed $value
     *
     * @return int
     */
    public static function intOf($value): int
    {
        $literal = self::literal($value);

        return is_scalar($literal) ? (int)$literal : 0;
    }

    /**
     * A numeric value as an int or a finite float; anything else as a string.
     *
     * @param mixed $value
     *
     * @return int|float|string
     */
    public static function number($value)
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_numeric($value)) {
            return (string)$value;
        }
        $n = $value + 0;
        if (is_float($n) && !is_finite($n)) {
            return (string)$value;
        }

        return $n;
    }

    /**
     * Driver escaping, without the quotes.
     *
     * @param string $value
     *
     * @return string
     */
    public static function escape(string $value): string
    {
        return Connection::getInstance()->escape($value);
    }

    /**
     * A placeholder list for $count values: "?, ?, ?".
     *
     * @param int $count
     *
     * @return string
     */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
