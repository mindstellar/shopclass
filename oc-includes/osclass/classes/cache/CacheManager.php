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

namespace mindstellar\cache;

/**
 * Hands out the request's one object-cache driver, picked by OSC_CACHE. A name not in DRIVERS
 * loads a plugin's own Object_Cache_<name> class; an unknown or unusable one falls back to
 * MemoryCache. The old name Object_Cache_Factory still works.
 */
final class CacheManager
{
    /** The OSC_CACHE values core knows, and their drivers. */
    public const DRIVERS = [
        'default'   => MemoryCache::class,
        'apcu'      => ApcuCache::class,
        'memcached' => MemcachedCache::class,
        'redis'     => RedisCache::class,
        // The memcache driver is gone; its sites move to memcached, which reads the same servers.
        'memcache'  => MemcachedCache::class,
    ];

    private static ?CacheDriver $instance = null;

    /**
     * The shared object-cache driver for this request, building it on first call.
     */
    public static function getInstance(): CacheDriver
    {
        return self::$instance ??= self::build();
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance(): CacheDriver
    {
        return self::getInstance();
    }

    /**
     * @deprecated 7.0.0 Use getInstance().
     */
    public static function getCache(): CacheDriver
    {
        return self::getInstance();
    }

    /**
     * The class for an OSC_CACHE value, or null when there is none.
     *
     * @return class-string<CacheDriver>|null
     */
    public static function driverClass(string $name): ?string
    {
        $class = self::DRIVERS[$name] ?? 'Object_Cache_' . $name;

        return class_exists($class) && is_subclass_of($class, CacheDriver::class) ? $class : null;
    }

    /**
     * Build the driver OSC_CACHE names. Tools > System info shows when it fell back.
     */
    private static function build(): CacheDriver
    {
        $name  = defined('OSC_CACHE') ? (string) OSC_CACHE : 'default';
        $class = self::driverClass($name);
        if ($class === null) {
            trigger_error('Cache ' . $name . ' UNKNOWN - loaded the in-request cache', E_USER_NOTICE);

            return new MemoryCache();
        }
        if (!$class::is_supported()) {
            trigger_error('Cache ' . $name . ' NOT SUPPORTED - loaded the in-request cache', E_USER_NOTICE);

            return new MemoryCache();
        }

        return new $class();
    }
}
