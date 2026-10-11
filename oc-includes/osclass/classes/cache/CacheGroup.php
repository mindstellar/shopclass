<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cache;

/**
 * Cached lookups grouped by a generation number. Every key in a group carries the group's
 * generation, so bumping it drops the whole group at once, however many keys it holds.
 * Without a persistent cache the store lives for one request, so nothing can go stale.
 */
final class CacheGroup
{
    /** Groups most pages read; with a driver that reads many keys at once they come in one round trip. */
    private const COMMON = array('preference', 'locale', 'category', 'currency', 'widget', 'page', 'search');

    /** 30 days, the longest relative expiry memcached accepts. */
    private const GENERATION_TTL = 2592000;

    /** @var array<string,int> generations read in this request; a page asks for the same one dozens of times */
    private static array $generations = array();

    /**
     * The group's current generation, the same on every site locale.
     *
     * @param string $group
     *
     * @return int
     */
    public static function generation(string $group): int
    {
        if (!isset(self::$generations[$group])) {
            $cache = CacheManager::getInstance();
            $read  = true;
            if (method_exists($cache, 'getMany')) {
                $groups = array_diff(array_unique(array_merge(self::COMMON, array($group))), array_keys(self::$generations));
                $keys   = array_combine(array_map(static fn (string $name): string => self::key($name), $groups), $groups);
                foreach ($cache->getMany(array_keys($keys)) as $key => $gen) {
                    if (is_numeric($gen)) {
                        self::$generations[$keys[$key]] = (int)$gen;
                    }
                }
                $read = false;
            }
            self::$generations[$group] ??= self::load($cache, $group, $read);
        }

        return self::$generations[$group];
    }

    /**
     * Read every generation from the cache again. A process that outlives a request, such as
     * the job worker, calls it before each job so it sees what other processes dropped.
     */
    public static function forgetGenerations(): void
    {
        self::$generations = array();
    }

    /**
     * Drop every cached entry in the group.
     *
     * @param string $group
     *
     * @return int the new generation
     */
    public static function invalidate(string $group): int
    {
        unset(self::$generations[$group]);
        $cache = CacheManager::getInstance();
        if (method_exists($cache, 'increment')) {
            // Atomic, so two drops at once both count; a missing key starts from the clock as in load().
            $gen = (int)$cache->increment(self::key($group), 1, self::fresh(), self::GENERATION_TTL);
        } else {
            $gen = self::generation($group) + 1;
            $cache->set(self::key($group), $gen, self::GENERATION_TTL);
        }
        self::$generations[$group] = $gen;

        return $gen;
    }

    /**
     * The stored generation, or a new one stored when there is none. A new one starts from the
     * clock, not 0, so entries kept from before the key expired or was evicted stay unreachable.
     */
    private static function load(CacheDriver $cache, string $group, bool $read): int
    {
        $key   = self::key($group);
        $found = false;
        $gen   = $read ? $cache->get($key, $found) : null;
        $start = self::fresh();
        if (!$found) {
            if ($cache->add($key, $start, self::GENERATION_TTL)) {
                return $start;
            }
            // Another request created it first, or the store is down.
            $gen = $cache->get($key, $found);
        }
        if ($found && is_numeric($gen)) {
            return (int)$gen;
        }
        if ($found) {
            $cache->set($key, $start, self::GENERATION_TTL);
        }

        return $start;
    }

    private static function key(string $group): string
    {
        return 'osc_' . $group . '_cache_gen';
    }

    /** Milliseconds now: above any generation counted up from an older start. */
    private static function fresh(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    /**
     * The cached value, or what $load returns, stored for $ttl seconds (OSC_CACHE_TTL when
     * null). A null from $load (a failed query) is returned but not stored.
     *
     * @param string   $group
     * @param string   $key   unique within the group
     * @param callable $load
     * @param int|null $ttl
     *
     * @return mixed
     */
    public static function remember(string $group, string $key, callable $load, ?int $ttl = null)
    {
        $cache = CacheManager::getInstance();
        $base  = defined('WEB_PATH') ? WEB_PATH : '';
        $full  = 'osc_' . $group . ':' . md5($base . '|' . self::generation($group) . '|' . $key);
        $found = null;
        $value = $cache->get($full, $found);
        if ($found) {
            return $value;
        }
        $value = $load();
        if ($value !== null) {
            $cache->set($full, $value, $ttl ?? (defined('OSC_CACHE_TTL') ? OSC_CACHE_TTL : 60));
        }

        return $value;
    }
}
