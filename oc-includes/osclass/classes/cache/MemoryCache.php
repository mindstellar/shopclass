<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\cache;

use mindstellar\base\Cache;

/**
 * The default cache: values live for one request only. It needs nothing installed.
 */
class MemoryCache extends Cache
{
    /**
     * Sets up object properties
     *
     * @since 2.4
     */
    public function __construct()
    {
        $this->site_prefix = '';
    }

    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @param int|string $key    What to call the contents in the cache
     * @param mixed      $data   The contents to store in the cache
     * @param int        $expire When to expire the cache contents
     *
     * @return bool False if cache key and group already exist, true on success
     * @since 3.4
     *
     */
    public function add($key, $data, $expire = 0)
    {
        $id = $key;

        if ($this->_exists($id)) {
            return false;
        }

        return $this->set($key, $data, $expire);
    }

    /**
     * Sets the data contents into the cache
     *
     * @param int|string $key    What to call the contents in the cache
     * @param mixed      $data   The contents to store in the cache
     * @param int        $expire Not Used
     *
     * @return bool Always returns true
     * @since 3.4
     *
     */
    public function set($key, $data, $expire = 0)
    {
        $data = self::copy($data);

        $this->cache[$key] = $data;

        return true;
    }

    /**
     * Remove the contents of the cache key
     *
     * @param int|string $key What the contents in the cache are called
     *
     * @return bool False if the contents weren't deleted and true on success
     * @since 3.4
     *
     */
    public function delete($key)
    {
        if (!$this->_exists($key)) {
            return false;
        }

        unset($this->cache[$key]);

        return true;
    }

    /**
     * Clears the object cache of all data
     *
     * @return bool Always returns true
     * @since 3.4
     *
     */
    public function flush()
    {
        $this->cache = array();

        return true;
    }

    /**
     * Retrieves the cache contents, if it exists
     *
     * @param int|string $key   What the contents in the cache are called
     * @param bool       $found if can be retrieved from cache
     *
     * @return bool|mixed False on failure to retrieve contents or the cache
     *      contents on success
     * @since 3.4
     *
     */
    public function get($key, &$found = null)
    {
        $value = $this->local($key, $found);
        if ($found) {
            return $value;
        }
        ++$this->cache_misses;

        return false;
    }

    /**
     * Normalised cache statistics for the admin's cache screen.
     *
     * Deliberately NOT part of CacheDriver: third-party drivers implement that
     * interface, and adding a required method would fatal them. Callers probe with
     * method_exists() instead. The legacy stats() is left alone — it echoes debug
     * markup and anything already calling it keeps working.
     *
     * @return null Always null: an in-request array has no accumulated state to report.
     */
    public function statsData()
    {
        // An in-request array: it is discarded when the request ends, so there is
        // no accumulated state worth showing.
        return null;
    }

    /**
     * Always available: this driver needs nothing beyond PHP itself.
     *
     * @return bool
     */
    public static function is_supported()
    {
        return true;
    }

    /**
     * The driver's identifier, as accepted by OSC_CACHE.
     *
     * @return string
     */
    public function _get_cache()
    {
        return 'default';
    }

    /**
     * Return hash of a given key
     *
     * @param int|string $key
     *
     * @return string
     */
    protected function _getKey($key)
    {
        return md5($key);
    }

    protected function statsTitle(): string
    {
        return 'Default(dummy) stats';
    }
}
