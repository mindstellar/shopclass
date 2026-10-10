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
 * The site's object cache (OSC_CACHE) as a CounterStore. With memcached or Redis every web server
 * shares one count; with APCu each server counts its own.
 */
final class CacheStore implements CounterStore
{
    /** @var \Closure(string, int, int, int): mixed */
    private \Closure $increment;

    private function __construct(private \iObject_Cache $cache, \Closure $increment)
    {
        $this->increment = $increment;
    }

    /**
     * A store over $cache when it outlives the request and counts atomically; null otherwise. The
     * default driver lasts one request, and the deprecated memcache one has no atomic increment.
     */
    public static function of(\iObject_Cache $cache): ?self
    {
        if ($cache->_get_cache() === 'default' || !method_exists($cache, 'increment')) {
            return null;
        }

        return new self($cache, \Closure::fromCallable([$cache, 'increment']));
    }

    public function add(string $key, int $value, int $ttl): bool
    {
        return (bool) $this->cache->add($key, $value, $ttl);
    }

    public function inc(string $key): ?int
    {
        // A count is at least 1, so the initial 0 means the key was gone or the server is down.
        $value = (int) ($this->increment)($key, 1, 0, 60);

        return $value > 0 ? $value : null;
    }

    public function get(string $key): ?int
    {
        $found = null;
        $value = $this->cache->get($key, $found);

        return $found && is_numeric($value) ? (int) $value : null;
    }

    public function set(string $key, int $value, int $ttl): void
    {
        $this->cache->set($key, $value, $ttl);
    }
}
