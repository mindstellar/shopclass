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

use mindstellar\utility\Clock;

/**
 * Counts requests in a CounterStore (the object cache) and writes the new ones to
 * the database counter every few seconds per key, so a request costs no query. The limit is judged
 * on the memory count, which starts from the database one so a restart does not reset the window.
 */
final class BufferedCounter
{
    public const FLUSH_EVERY = 5;

    /** @var \Closure(string, string, int, int): ?int */
    private \Closure $flush;

    /** @var \Closure(string, string, int): ?int */
    private \Closure $fallback;

    /** @var (\Closure(string, string, int): ?int)|null */
    private ?\Closure $seed;

    /**
     * @param callable      $flush    (context, key, requests to add, window) => count, writes to the database
     * @param callable      $fallback (context, key, window) => count, used when the store fails
     * @param callable|null $seed     (context, key, window) => the database count, read when a memory count starts
     */
    public function __construct(
        private CounterStore $store,
        private Clock $clock,
        callable $flush,
        callable $fallback,
        ?callable $seed = null
    ) {
        $this->flush    = \Closure::fromCallable($flush);
        $this->fallback = \Closure::fromCallable($fallback);
        $this->seed     = $seed !== null ? \Closure::fromCallable($seed) : null;
    }

    /**
     * Count one request and return the count in the current window.
     */
    public function increment(string $context, string $key, int $window, int $limit = 0): ?int
    {
        $window = max(1, $window);
        $now    = $this->clock->now();
        $name   = 'osc_rl:' . sha1(substr($context, 0, 40) . ':' . $window . ':' . $key) . ':' . ($now - ($now % $window));
        if ($this->store->get($name) === null) {
            $start = $this->seed !== null ? (int) (($this->seed)($context, $key, $window) ?? 0) : 0;
            if ($this->store->add($name, $start, $window + 1)) {
                $this->store->set($name . ':s', $start, $window + 1);
            }
        }
        $count = $this->store->inc($name);
        if ($count === null) {
            return ($this->fallback)($context, $key, $window);
        }
        if ($this->store->add($name . ':f', 1, self::FLUSH_EVERY)) {
            $sent = $this->store->get($name . ':s') ?? 0;
            if ($count > $sent) {
                $this->store->set($name . ':s', $count, $window + 1);
                ($this->flush)($context, $key, $count - $sent, $window);
            }
        }

        return $count;
    }
}
