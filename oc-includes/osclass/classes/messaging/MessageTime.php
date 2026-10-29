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

namespace mindstellar\messaging;

/**
 * The short times the messages pages print, in the site time zone: a clock time today, "Yesterday",
 * a weekday this week, else the day and month.
 */
final class MessageTime
{
    /** The time on a bubble, "14:05". */
    public static function clock(string $dateTime): string
    {
        return date('H:i', (int) strtotime($dateTime));
    }

    /** The time on an inbox row. */
    public static function short(string $dateTime, ?int $now = null): string
    {
        $days = self::daysAgo($dateTime, $now);
        if ($days <= 0) {
            return self::clock($dateTime);
        }
        if ($days === 1) {
            return _m('Yesterday');
        }

        return $days < 7 ? osc_format_date($dateTime, 'D') : osc_format_date($dateTime, 'j M');
    }

    /** The label on a day line. */
    public static function day(string $dateTime, ?int $now = null): string
    {
        $days = self::daysAgo($dateTime, $now);
        if ($days <= 0) {
            return _m('Today');
        }

        return $days === 1 ? _m('Yesterday') : osc_format_date($dateTime, 'j F');
    }

    /** The calendar day of a time, to tell whether two messages share a day. */
    public static function dayKey(string $dateTime): string
    {
        return date('Y-m-d', (int) strtotime($dateTime));
    }

    private static function daysAgo(string $dateTime, ?int $now): int
    {
        $then  = new \DateTimeImmutable(self::dayKey($dateTime));
        $today = new \DateTimeImmutable(date('Y-m-d', $now ?? time()));

        return (int) $then->diff($today)->format('%r%a');
    }
}
