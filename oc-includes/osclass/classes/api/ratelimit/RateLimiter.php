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
 * Counts requests in their buckets over core's RateLimit, in the object cache when the site has
 * one and in database samples when it does not, and builds the rate limit headers. An exact bucket is counted
 * in the database on every request, and it fails open like RateLimit.
 */
final class RateLimiter
{
    /** @var \Closure(string, string, int, int): ?int */
    private \Closure $increment;

    /** @var \Closure(string, string, int, int): ?int */
    private \Closure $exact;

    /** @var \Closure(string, string, int, int): ?int|null */
    private ?\Closure $add;

    /**
     * @param callable      $increment (bucket, key, window, limit) => count so far, or null when the counter
     *                                 cannot be reached
     * @param callable|null $exact     the same for exact buckets; $increment when null
     * @param callable|null $add       (bucket, key, by, window) => count so far, to count several requests in an
     *                                 exact bucket in one write; $exact once per request when null
     */
    public function __construct(callable $increment, private Clock $clock, ?callable $exact = null, ?callable $add = null)
    {
        $this->increment = \Closure::fromCallable($increment);
        $this->exact     = $exact !== null ? \Closure::fromCallable($exact) : $this->increment;
        $this->add       = $add !== null ? \Closure::fromCallable($add) : null;
    }

    /**
     * The site's limiter: exact buckets with RateLimit, the others in the object cache, or in
     * samples (SampledCounter) when the cache cannot hold counts.
     */
    public static function fromSite(Clock $clock): self
    {
        $store = CacheStore::of(\Object_Cache_Factory::getInstance());
        if ($store === null) {
            return self::sampled($clock);
        }
        [$db, $add, $count] = self::counters($clock);
        $counter = new BufferedCounter($store, $clock, $add, $db, seed: $count);

        return new self([$counter, 'increment'], $clock, $db, $add);
    }

    /**
     * The limiter of a site without an object cache: exact buckets with RateLimit, the others in samples.
     *
     * @param callable|null $draw (every) => whether this request writes; random when null
     */
    public static function sampled(Clock $clock, ?callable $draw = null): self
    {
        [$db, $add, $count] = self::counters($clock);

        return new self([new SampledCounter($add, $count, $draw), 'increment'], $clock, $db, $add);
    }

    /**
     * RateLimit's increment, add and count, on the limiter's clock.
     *
     * @return array{0:\Closure,1:\Closure,2:\Closure}
     */
    private static function counters(Clock $clock): array
    {
        return [
            static fn (string $context, string $key, int $window, int $limit = 0): ?int => RateLimit::increment($context, $key, $window, true, $clock->now()),
            static fn (string $context, string $key, int $by, int $window): ?int => RateLimit::add($context, $key, $by, $window, true, $clock->now()),
            static fn (string $context, string $key, int $window): ?int => RateLimit::count($context, $key, $window, $clock->now()),
        ];
    }

    /**
     * Count $n requests in a bucket, one by default.
     *
     * @param bool $failOpen false refuses the request when the counter cannot be reached
     */
    public function hit(RateBucket $bucket, bool $failOpen = true, int $n = 1): RateLimitResult
    {
        $window = $bucket->window();
        $reset  = $window - ($this->clock->now() % $window);
        if ($bucket->max() <= 0) {
            return new RateLimitResult($bucket, true, 0, $reset);
        }
        $count = $this->count($bucket, max(1, $n));
        if ($count === null) {
            return new RateLimitResult($bucket, $failOpen, $failOpen ? $bucket->max() : 0, $reset);
        }

        return new RateLimitResult($bucket, $count <= $bucket->max(), max(0, $bucket->max() - $count), $reset);
    }

    /**
     * Count $n requests; an exact bucket takes them in one write when an $add counter was given.
     */
    private function count(RateBucket $bucket, int $n): ?int
    {
        if ($bucket->exact() && $n > 1 && $this->add !== null) {
            return ($this->add)($bucket->name(), $bucket->key(), $n, $bucket->window());
        }
        $counter = $bucket->exact() ? $this->exact : $this->increment;
        $count   = null;
        for ($i = 0; $i < $n; $i++) {
            $count = $counter($bucket->name(), $bucket->key(), $bucket->window(), $bucket->max());
        }

        return $count;
    }

    /**
     * Count $n requests in a bucket at once and refuse them all past the limit.
     *
     * @param bool $failOpen false refuses the request when the counter cannot be reached
     *
     * @throws ProblemException 429 past the limit
     */
    public function enforce(RateBucket $bucket, string $message, bool $failOpen = true, int $n = 1): void
    {
        $result = $this->hit($bucket, $failOpen, $n);
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
