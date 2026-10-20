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

namespace mindstellar\webhook;

/**
 * When a failed webhook delivery is tried again: 1 and 5 minutes, 30 minutes, 2 and 5 hours,
 * then 10 hours at a time and a last wait of 14, about 72 hours in all, each wait shifted by up to 10% either way.
 * A receiver's Retry-After replaces the wait, up to a day.
 */
final class RetrySchedule
{
    /** Seconds to wait after the 1st, 2nd, ... failed try. */
    public const WAITS = [60, 300, 1800, 7200, 18000, 36000, 36000, 36000, 36000, 36000, 50400];

    /** Tries in all: the first, then one after each wait but the last. */
    public const MAX_ATTEMPTS = 12;

    public const MAX_RETRY_AFTER = 86400;

    private const JITTER_PERCENT = 10;

    /**
     * Seconds to wait after the $attempt-th try failed.
     *
     * @param int $retryAfter seconds the receiver asked for, 0 for none
     */
    public static function delay(int $attempt, int $retryAfter = 0): int
    {
        if ($retryAfter > 0) {
            return min($retryAfter, self::MAX_RETRY_AFTER);
        }
        $base   = self::WAITS[min(max($attempt, 1), count(self::WAITS)) - 1];
        $spread = intdiv($base * self::JITTER_PERCENT, 100);

        return $base + random_int(-$spread, $spread);
    }
}
