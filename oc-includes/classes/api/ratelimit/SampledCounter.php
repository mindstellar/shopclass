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

namespace mindstellar\api\ratelimit;

/**
 * Counts requests in the database without writing on each one, for sites with no object cache. One
 * request in 2 or 4 adds that many to the stored count, so it is right on average but never exact.
 */
final class SampledCounter
{
    public const EVERY_FINE = 2;
    public const EVERY_COARSE = 4;
    public const COARSE_FROM = 300;

    /** @var \Closure(string, string, int, int): ?int */
    private \Closure $add;

    /** @var \Closure(string, string, int): ?int */
    private \Closure $read;

    /** @var \Closure(int): bool */
    private \Closure $draw;

    /**
     * @param callable      $add  (context, key, requests to add, window) => the count after it
     * @param callable      $read (context, key, window) => the stored count
     * @param callable|null $draw (every) => whether this request writes; random when null
     */
    public function __construct(callable $add, callable $read, ?callable $draw = null)
    {
        $this->add  = \Closure::fromCallable($add);
        $this->read = \Closure::fromCallable($read);
        $this->draw = $draw !== null
            ? \Closure::fromCallable($draw)
            : static fn (int $every): bool => random_int(1, $every) === 1;
    }

    /**
     * How many requests share one write for a bucket with this limit.
     */
    public static function everyFor(int $limit): int
    {
        return $limit >= self::COARSE_FROM ? self::EVERY_COARSE : self::EVERY_FINE;
    }

    /**
     * Count one request and return the count in the current window, as stored.
     */
    public function increment(string $context, string $key, int $window, int $limit = 0): ?int
    {
        $every = self::everyFor($limit);
        if (($this->draw)($every)) {
            return ($this->add)($context, $key, $every, $window);
        }

        return ($this->read)($context, $key, $window);
    }
}
