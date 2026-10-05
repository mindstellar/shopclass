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

use mindstellar\api\ProblemException;
use mindstellar\security\RateLimit;
use mindstellar\utility\Clock;

/**
 * Counts requests in their buckets over core's RateLimit (one query per bucket) and builds
 * the rate limit headers. Fails open like RateLimit.
 */
final class RateLimiter
{
    /** @var \Closure(string, string, int): ?int */
    private \Closure $increment;

    /**
     * @param callable $increment (bucket, key, window) => count so far, or null when the counter
     *                            cannot be reached
     */
    public function __construct(callable $increment, private Clock $clock)
    {
        $this->increment = \Closure::fromCallable($increment);
    }

    /**
     * The site's limiter, counting with RateLimit.
     */
    public static function fromSite(Clock $clock): self
    {
        return new self([RateLimit::class, 'increment'], $clock);
    }

    /**
     * Count one request in a bucket.
     *
     * @param bool $failOpen false refuses the request when the counter cannot be reached
     */
    public function hit(RateBucket $bucket, bool $failOpen = true): RateLimitResult
    {
        $window = $bucket->window();
        $reset  = $window - ($this->clock->now() % $window);
        if ($bucket->max() <= 0) {
            return new RateLimitResult($bucket, true, 0, $reset);
        }
        $count = ($this->increment)($bucket->name(), $bucket->key(), $window);
        if ($count === null) {
            return new RateLimitResult($bucket, $failOpen, $failOpen ? $bucket->max() : 0, $reset);
        }

        return new RateLimitResult($bucket, $count <= $bucket->max(), max(0, $bucket->max() - $count), $reset);
    }

    /**
     * Count one request in a bucket and refuse it past the limit.
     *
     * @param bool $failOpen false refuses the request when the counter cannot be reached
     *
     * @throws ProblemException 429 past the limit
     */
    public function enforce(RateBucket $bucket, string $message, bool $failOpen = true): void
    {
        $result = $this->hit($bucket, $failOpen);
        if (!$result->allowed()) {
            throw ProblemException::tooMany($message, $result->reset());
        }
    }

    /**
     * enforce() for each bucket, in order.
     *
     * @param RateBucket[] $buckets
     *
     * @throws ProblemException 429 past a limit
     */
    public function enforceAll(array $buckets, string $message, bool $failOpen = true): void
    {
        foreach ($buckets as $bucket) {
            $this->enforce($bucket, $message, $failOpen);
        }
    }

    /**
     * Both the IETF RateLimit-Policy/RateLimit pair and the older X-RateLimit-* set. The
     * RateLimit and X-RateLimit values describe the bucket closest to its limit.
     *
     * @param RateLimitResult[] $results
     *
     * @return array<string,string>
     */
    public function headers(array $results): array
    {
        $results = array_values(array_filter($results, static fn (RateLimitResult $r): bool => $r->bucket()->max() > 0));
        if ($results === []) {
            return [];
        }
        $policies = [];
        $tightest = $results[0];
        foreach ($results as $result) {
            $policies[] = '"' . $result->bucket()->name() . '";q=' . $result->bucket()->max() . ';w=' . $result->bucket()->window();
            if ($result->remaining() < $tightest->remaining()) {
                $tightest = $result;
            }
        }

        return [
            'RateLimit-Policy'      => implode(', ', $policies),
            'RateLimit'             => '"' . $tightest->bucket()->name() . '";r=' . $tightest->remaining() . ';t=' . $tightest->reset(),
            'X-RateLimit-Limit'     => (string) $tightest->bucket()->max(),
            'X-RateLimit-Remaining' => (string) $tightest->remaining(),
            'X-RateLimit-Reset'     => (string) ($this->clock->now() + $tightest->reset()),
        ];
    }
}
