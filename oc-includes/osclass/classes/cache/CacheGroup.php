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
            $found = null;
            $gen   = CacheManager::getInstance()->get('osc_' . $group . '_cache_gen', $found);

            self::$generations[$group] = is_numeric($gen) ? (int)$gen : 0;
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
        $gen = self::generation($group) + 1;
        CacheManager::getInstance()->set('osc_' . $group . '_cache_gen', $gen, 0);
        self::$generations[$group] = $gen;

        return $gen;
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
