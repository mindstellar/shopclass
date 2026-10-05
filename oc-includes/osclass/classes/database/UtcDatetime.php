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

namespace mindstellar\database;

/**
 * A DATETIME column that holds UTC, written and read the same way whatever the site's or
 * PHP's time zone, so a DST change or a changed time zone cannot move a lock or an expiry.
 */
final class UtcDatetime
{
    private function __construct()
    {
    }

    /**
     * A Unix time as the column's `Y-m-d H:i:s` in UTC.
     */
    public static function format(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * The Unix time of a UTC column value, or null for none or one that cannot be read.
     */
    public static function parse(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $time === false ? null : $time->getTimestamp();
    }
}
