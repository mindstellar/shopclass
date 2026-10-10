<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The buffered rate counter: counts in the object cache, writes to the database at most every few
 * seconds, keeps the limit exact, and falls back to the database when the store fails. Without an
 * object cache, plain buckets write in samples and exact ones on every request. DB-free.
 * Usage: php tests/api-buffered-counter.php
 */

require_once __DIR__ . '/lib/api-boot.php';
require_once __DIR__ . '/lib/test-clock.php';

use mindstellar\api\ratelimit\BufferedCounter;
use mindstellar\api\ratelimit\CacheStore;
use mindstellar\api\ratelimit\CounterStore;
use mindstellar\api\ratelimit\RateBucket;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\ratelimit\SampledCounter;

final class ArrayStore implements CounterStore
{
    public array $data = [];
    public bool $broken = false;
    public int $now = 1000;
    private array $expires = [];

    public function add(string $key, int $value, int $ttl): bool
    {
        if (isset($this->data[$key]) && $this->expires[$key] > $this->now) {
            return false;
        }
        $this->set($key, $value, $ttl);

        return true;
    }

    public function inc(string $key): ?int
    {
        return $this->broken ? null : ($this->data[$key] = ($this->data[$key] ?? 0) + 1);
    }

    public function get(string $key): ?int
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, int $value, int $ttl): void
    {
        $this->data[$key]    = $value;
        $this->expires[$key] = $this->now + $ttl;
    }
}

/** An object cache driver held in memory, named like the driver it stands in for. */
final class MemoryCacheDriver implements \mindstellar\cache\CacheDriver
{
    public array $data = [];
    public bool $down = false;
    /** A miss that still hands back a value, as a driver returning a stale or default one does. */
    public mixed $missValue = false;

    public function __construct(private string $name = 'memcached')
    {
    }

    public static function is_supported()
    {
        return true;
    }

    public function add($key, $data, $expire = 0)
    {
        if ($this->down || isset($this->data[$key])) {
            return false;
        }
        $this->data[$key] = $data;

        return true;
    }

    public function set($key, $data, $expire = 0)
    {
        $this->data[$key] = $data;

        return true;
    }

    public function get($key, &$found = null)
    {
        $found = !$this->down && isset($this->data[$key]);

        return $found ? $this->data[$key] : $this->missValue;
    }

    public function increment($key, $by = 1, $initial = 0, $expire = 0)
    {
        if ($this->down) {
            return $initial;
        }
        if (!isset($this->data[$key])) {
            return $this->data[$key] = $initial;
        }

        return $this->data[$key] += $by;
    }

    public function delete($key)
    {
        unset($this->data[$key]);

        return true;
    }

    public function flush()
    {
        $this->data = [];

        return true;
    }

    public function stats()
    {
    }

    public function _get_cache()
    {
        return $this->name;
    }

    public function __destruct()
    {
    }
}

$store   = new ArrayStore();
$now     = &$store->now;
$clock   = new TestClock(static function () use (&$now): int {
    return $now;
});
$writes  = [];
$reads   = 0;
$counter = new BufferedCounter(
    $store,
    $clock,
    static function (string $c, string $k, int $by, int $w) use (&$writes): ?int {
        $writes[] = $by;

        return null;
    },
    static function () use (&$reads): int {
        return ++$reads;
    }
);

harness_section('counting in memory');
$counts = [];
for ($i = 0; $i < 10; $i++) {
    $counts[] = $counter->increment('api', 'k', 60);
}
pin('counts run 1..10', range(1, 10), $counts);
pin('one database write for the whole burst: the first request', [1], $writes);

$now = 1006;
$counter->increment('api', 'k', 60);
pin('after the flush interval the new requests are written as one delta', [1, 10], $writes);
$counter->increment('api', 'k', 60);
pin('no second write happens until the flush interval passes again', [1, 10], $writes);

harness_section('windows and keys');
$now = 1020;
pin('a new window starts at 1', 1, $counter->increment('api', 'k', 60));
pin('another key has its own count', 1, $counter->increment('api', 'other', 60));

harness_section('store failure');
$store->broken = true;
pin('the database counter answers', 1, $counter->increment('api', 'k', 60));

harness_section('limiter over the counter');
$store->broken = false;
$now           = 2000;
$limiter       = new RateLimiter([$counter, 'increment'], $clock);
$bucket        = new RateBucket('anon', '1.2.3.4', 3, 60);
$results       = [];
for ($i = 0; $i < 5; $i++) {
    $r         = $limiter->hit($bucket);
    $results[] = [$r->allowed(), $r->remaining()];
}
pin('the 4th request is refused, remaining stops at 0', [[true, 2], [true, 1], [true, 0], [false, 0], [false, 0]], $results);

harness_section('the object cache as the store');
$shared = new MemoryCacheDriver('memcached');
$noDb   = static fn (): ?int => null;
pin('memcached and apcu drivers hold counts; the per-request default does not', [true, true, null], [CacheStore::of($shared) !== null, CacheStore::of(new MemoryCacheDriver('apcu')) !== null, CacheStore::of(new MemoryCacheDriver('default'))]);
$serverA = new RateLimiter([new BufferedCounter(CacheStore::of($shared), $clock, $noDb, $noDb), 'increment'], $clock);
$serverB = new RateLimiter([new BufferedCounter(CacheStore::of($shared), $clock, $noDb, $noDb), 'increment'], $clock);
$bucket  = new RateBucket('api_anon', '1.2.3.4', 4, 60);
$left    = [];
foreach ([$serverA, $serverB, $serverA, $serverB, $serverA] as $server) {
    $left[] = $server->hit($bucket)->remaining();
}
pin('two servers share one count and the fifth request is refused', [[3, 2, 1, 0, 0], false], [$left, $serverB->hit($bucket)->allowed()]);
$shared->down = true;
$fellBack     = 0;
$downCounter  = new BufferedCounter(CacheStore::of($shared), $clock, $noDb, static function () use (&$fellBack): int {
    return ++$fellBack;
});
pin('a cache server that is down falls back to the database counter', [1, 1], [$downCounter->increment('api', 'k', 60), $fellBack]);
$plain = new MemoryCacheDriver('memcached');
$store = CacheStore::of($plain);
$store->set('rate:k', 9, 60);
pin('the cache store writes a set count to the driver', [9, 9], [$plain->data['rate:k'] ?? null, $store->get('rate:k')]);
$plain->missValue = 5;
pin('a miss is no count, whatever value the driver hands back', null, $store->get('rate:gone'));

harness_section('seeding from the database');
$fresh   = new ArrayStore();
$fresh->now = $now;
$seeds   = 0;
$flushed = [];
$seeded  = new BufferedCounter(
    $fresh,
    $clock,
    static function (string $c, string $k, int $by) use (&$flushed): ?int {
        $flushed[] = $by;

        return null;
    },
    static fn (): int => 0,
    static function () use (&$seeds): int {
        $seeds++;

        return 40;
    }
);
pin('a fresh memory count starts from the database count', 41, $seeded->increment('api', 'k', 60));
pin('only the new request is written back', [1], $flushed);
pin('the next request does not read the database again', [42, 1], [$seeded->increment('api', 'k', 60), $seeds]);

harness_section('exact buckets skip memory');
$memory = 0;
$exactN = 0;
$split  = new RateLimiter(
    static function () use (&$memory): int {
        return ++$memory;
    },
    $clock,
    static function () use (&$exactN): int {
        return ++$exactN;
    }
);
$split->hit(new RateBucket('api_anon', 'k', 10));
$split->hit(new RateBucket('api_register', 'k', 10, 3600, true));
pin('a plain bucket uses the fast counter, an exact one the database', [1, 1], [$memory, $exactN]);

$policy  = new \mindstellar\api\ratelimit\RatePolicy(new \mindstellar\apikey\ApiSettings(true));
$exactOf = static function (array $buckets): array {
    $out = [];
    foreach ($buckets as $bucket) {
        $out[$bucket->name()] = $bucket->exact();
    }

    return $out;
};
pin(
    'sign-up, new listing and photo fetch caps are exact',
    ['api_register' => true, 'api_register_site' => true, 'api_listing_post' => true, 'api_listing_ip' => true, 'api_photo_fetch' => true],
    $exactOf(array_merge([$policy->signUp('1.2.3.4'), $policy->signUpSite()], $policy->newListing(1, '1.2.3.4'), [$policy->photoFetch(1)]))
);

harness_section('sampled counting without an object cache');
$stored  = 0;
$adds    = 0;
$sampled = new SampledCounter(
    static function (string $c, string $k, int $by) use (&$stored, &$adds): int {
        $adds++;

        return $stored += $by;
    },
    static function () use (&$stored): int {
        return $stored;
    }
);
for ($i = 0; $i < 4000; $i++) {
    $sampled->increment('api_anon', 'k', 60);
}
pin('a limit under 300 writes about one request in 2, adding 2', [true, $adds * 2], [$adds > 1800 && $adds < 2200, $stored]);
$stored = 0;
$adds   = 0;
for ($i = 0; $i < 4000; $i++) {
    $sampled->increment('api_anon', 'k', 60, 300);
}
pin('a limit of 300 or more writes about one request in 4, adding 4', [true, $adds * 4], [$adds > 850 && $adds < 1150, $stored]);
pin('every() picks 2 below 300 and 4 from 300', [2, 2, 4, 4], [SampledCounter::everyFor(0), SampledCounter::everyFor(299), SampledCounter::everyFor(300), SampledCounter::everyFor(1000)]);

$stored = 0;
$adds   = 0;
$turn   = 0;
$every  = new SampledCounter(
    static function (string $c, string $k, int $by) use (&$stored, &$adds): int {
        $adds++;

        return $stored += $by;
    },
    static function () use (&$stored): int {
        return $stored;
    },
    static function (int $n) use (&$turn): bool {
        return $turn++ % $n === 0;
    }
);
$exactWrites = 0;
$limiter     = new RateLimiter([$every, 'increment'], $clock, static function () use (&$exactWrites): int {
    return ++$exactWrites;
});
$allowed = 0;
$results = [];
for ($i = 0; $i < 20; $i++) {
    $r         = $limiter->hit(new RateBucket('api_anon', 'k', 10));
    $allowed  += $r->allowed() ? 1 : 0;
    $results[] = $r;
}
pin('a read bucket still trips its limit, within 2 of it, judged on the stored count', [10, true, false, false], [$allowed, $results[9]->allowed(), $results[10]->allowed(), $results[19]->allowed()]);
pin('20 read requests write the counter 10 times', 10, $adds);
pin('its headers stay sensible', ['10', '0'], [$limiter->headers([$results[19]])['X-RateLimit-Limit'], $limiter->headers([$results[19]])['X-RateLimit-Remaining']]);
for ($i = 0; $i < 7; $i++) {
    $limiter->hit(new RateBucket('api_write', 'k', 100, 60, true));
}
pin('an exact bucket still writes on every request', [7, 10], [$exactWrites, $adds]);

exit(harness_result());
