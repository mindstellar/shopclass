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

namespace mindstellar\base;

/**
 * What every object-cache driver shares: the in-request copy of each value, hit and miss
 * counts, a key prefix per site and the default time a value lives. A driver adds the calls
 * to its own store. A driver from a plugin may still implement \iObject_Cache directly.
 */
abstract class ObjectCache implements \iObject_Cache
{
    /**
     * Values already read or written in this request, by caller key.
     *
     * @var array<int|string,mixed>
     */
    public $cache = array();

    /** @var int */
    public $cache_hits = 0;

    /** @var int */
    public $cache_misses = 0;

    /**
     * Put before every key in a shared store, so several sites can use one server.
     *
     * @var string
     */
    public $site_prefix;

    /** @var int Seconds a value lives when the caller gives none. */
    public $default_expiration = 60;

    public function __construct()
    {
        $this->site_prefix = 'osc_' . substr(md5(defined('WEB_PATH') ? WEB_PATH : __DIR__), 0, 12) . '_';
    }

    /**
     * The value this request already holds for $key, counted as a hit; false when it holds none.
     *
     * @param int|string $key
     * @param bool|null  $found set to whether this request held the key
     *
     * @return mixed
     */
    protected function local($key, &$found)
    {
        $found = isset($this->cache[$key]);
        if (!$found) {
            return false;
        }
        ++$this->cache_hits;

        return self::copy($this->cache[$key]);
    }

    /**
     * A copy a caller may change without changing the cached value: objects are cloned.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected static function copy($value)
    {
        return is_object($value) ? clone $value : $value;
    }

    /**
     * The key in the shared store.
     *
     * @param int|string $key
     *
     * @return string
     */
    protected function _key($key)
    {
        return $this->site_prefix . $key;
    }

    /**
     * Seconds a value lives: $expire, or the default when none is given.
     *
     * @param int|string $expire
     *
     * @return int
     */
    protected function ttl($expire)
    {
        return (int) $expire > 0 ? (int) $expire : (int) $this->default_expiration;
    }

    /**
     * Whether this request holds a value for $key.
     *
     * @param int|string $key
     *
     * @return bool
     */
    protected function _exists($key)
    {
        return isset($this->cache[$key]);
    }

    /**
     * The name the debug panel shows.
     *
     * @return string
     */
    abstract protected function statsTitle(): string;

    /**
     * Echoes this request's hits and misses as a debug panel.
     *
     * @return void
     */
    public function stats()
    {
        echo "<div style='position:absolute;width:200px;top:0px;'><div style='float:right;margin-right:30px;"
            . "margin-top:15px;border: 1px red solid;border-radius: 17px;padding: 1em;'><h2>"
            . $this->statsTitle() . '</h2>';
        echo '<p>';
        echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
        echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
        echo '</p>';
        echo '</div></div>';
    }

    /**
     * Nothing to release: connections close with the request.
     *
     * @return void
     */
    public function __destruct()
    {
    }
}
