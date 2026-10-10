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
 * Put another object cache in place of the site's, and build one on a real Redis or Valkey.
 */

/**
 * Make $cache the site's object cache.
 *
 * @return object|null the cache it replaced, to hand back when done
 */
function test_cache_swap(?object $cache): ?object
{
    $shared = new ReflectionProperty(\mindstellar\cache\CacheManager::class, 'instance');
    $shared->setAccessible(true);
    $before = $shared->getValue();
    $shared->setValue(null, $cache);

    return $before;
}

/**
 * An empty RedisCache on $server (host:port), OSC_TEST_REDIS by default.
 *
 * @return \mindstellar\cache\RedisCache|null null when no server is named
 */
function test_redis_cache(?string $server = null): ?\mindstellar\cache\RedisCache
{
    $server = $server ?? (string) getenv('OSC_TEST_REDIS');
    if ($server === '') {
        return null;
    }
    [$host, $port]            = explode(':', $server) + [1 => '6379'];
    $before                   = $GLOBALS['_cache_config'] ?? null;
    $GLOBALS['_cache_config'] = [['default_host' => $host, 'default_port' => (int) $port]];
    $cache                    = new \mindstellar\cache\RedisCache();
    $GLOBALS['_cache_config'] = $before;
    $cache->flush();

    return $cache;
}
