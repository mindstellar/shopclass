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

use mindstellar\base\ObjectCache;

/**
 * Object_Cache_apcu class
 *
 * @author Navjot Tomer
 */
class Object_Cache_apcu extends ObjectCache
{
    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @param int|string $key    What to call the contents in the cache
     * @param mixed      $data   The contents to store in the cache
     * @param int        $expire When to expire the cache contents
     *
     * @return bool False if cache key and group already exist, true on success
     * @since 3.7
     *
     */
    public function add($key, $data, $expire = 0)
    {
        $id = $this->_key($key);

        $data = self::copy($data);

        $store_data = $data;

        if (is_array($data)) {
            $store_data = new ArrayObject($data);
        }

        $expire = $this->ttl($expire);
        $result = apcu_add($id, $store_data, $expire);
        if (false !== $result) {
            $this->cache[$key] = $data;
        }

        return $result;
    }

    /**
     * Atomically increment a numeric key, creating it at $initial on first sighting.
     *
     * apcu_add is create-only, so it seeds the counter at $initial exactly once; a
     * racing caller makes our add() fail and we fall through to the atomic apcu_inc,
     * so no count is lost. (apcu_inc alone would create a missing key at $by, not at
     * the caller's $initial — hence the add-then-inc.)
     *
     * @param int|string $key
     * @param int        $by
     * @param int        $initial value to create the key at on first sighting
     * @param int        $expire
     *
     * @return int the new counter value
     */
    public function increment($key, $by = 1, $initial = 0, $expire = 0)
    {
        $expire = $this->ttl($expire);
        $id     = $this->_key($key);

        if (apcu_add($id, $initial, $expire)) {
            $value = $initial;
        } else {
            $value = apcu_inc($id, $by);
            if (false === $value) {
                $value = $initial;
            }
        }

        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Remove the contents of the cache key in the group
     *
     * @param int|string $key What the contents in the cache are called
     *
     * @return bool False if the contents weren't deleted and true on success
     * @since 3.7
     *
     */
    public function delete($key)
    {
        $result = apcu_delete($this->_key($key));
        if (false !== $result) {
            unset($this->cache[$key]);
        }

        return $result;
    }

    /**
     * Clears the object cache of all data
     *
     * @return bool|string[] True on success, or the keys that could not be deleted.
     * @since 3.7
     *
     */
    public function flush()
    {
        $this->cache = array();
        // Only this install's entries. apcu_clear_cache() would wipe the shared
        // store for every other install in the same PHP pool.
        if (extension_loaded('apcu')) {
            if (class_exists('APCUIterator')) {
                return apcu_delete(new APCUIterator('/^' . preg_quote($this->site_prefix, '/') . '/'));
            }

            return apcu_clear_cache();
        }

        return true;
    }

    /**
     * Retrieves the cache contents, if it exists
     *
     * @param int|string $key   What the contents in the cache are called
     * @param bool       $found if can be retrieved from cache
     *
     * @return bool|mixed False on failure to retrieve contents or the cache
     *        contents on success
     * @since 3.7
     *
     */
    public function get($key, &$found = null)
    {
        $value = $this->local($key, $found);
        if ($found) {
            return $value;
        }
        $value = apcu_fetch($this->_key($key), $found);
        if (!$found) {
            ++$this->cache_misses;

            return false;
        }
        if (is_object($value) && 'ArrayObject' === get_class($value)) {
            $value = $value->getArrayCopy();
        }
        if (null === $value) {
            $value = false;
        }
        $this->cache[$key] = self::copy($value);
        ++$this->cache_hits;

        return $value;
    }

    /**
     * Sets the data contents into the cache
     *
     * @param int|string $key    What to call the contents in the cache
     * @param mixed      $data   The contents to store in the cache
     * @param int        $expire Not Used
     *
     * @return bool Always returns true on success, false on failure
     * @since 3.7
     *
     */
    public function set($key, $data, $expire = 0)
    {
        $data = self::copy($data);

        $store_data = $data;

        if (is_array($data)) {
            $store_data = new ArrayObject($data);
        }

        $this->cache[$key] = $data;

        $expire = $this->ttl($expire);

        return apcu_store($this->_key($key), $store_data, $expire);
    }

    /**
     * Normalised cache statistics for the admin's cache screen.
     *
     * Deliberately NOT part of iObject_Cache: third-party drivers implement that
     * interface, and adding a required method would fatal them. Callers probe with
     * method_exists() instead. The legacy stats() is left alone — it echoes debug
     * markup and anything already calling it keeps working.
     *
     * @return array<string,int|string|null>|null Null when the driver has nothing to report.
     */
    public function statsData()
    {
        if (!function_exists('apcu_cache_info')) {
            return null;
        }
        $info = @apcu_cache_info(true);
        $sma  = function_exists('apcu_sma_info') ? @apcu_sma_info(true) : array();
        if (!is_array($info)) {
            return null;
        }
        $free  = isset($sma['avail_mem']) ? (int)$sma['avail_mem'] : null;
        $total = null;
        if (isset($sma['num_seg'], $sma['seg_size'])) {
            $total = (int)$sma['num_seg'] * (int)$sma['seg_size'];
        }

        return array(
            'entries'      => isset($info['num_entries']) ? (int)$info['num_entries'] : null,
            'hits'         => isset($info['num_hits']) ? (int)$info['num_hits'] : null,
            'misses'       => isset($info['num_misses']) ? (int)$info['num_misses'] : null,
            'memory_used'  => ($total !== null && $free !== null) ? ($total - $free) : null,
            'memory_total' => $total,
            'uptime'       => isset($info['start_time']) ? (time() - (int)$info['start_time']) : null,
            'evictions'    => isset($info['expunges']) ? (int)$info['expunges'] : null,
            'server'       => null,
        );
    }

    /**
     * Whether the APCu extension is loaded and enabled.
     *
     * @return bool
     */
    public static function is_supported()
    {
        if (!extension_loaded('apcu') or ini_get('apc.enabled') != '1') {
            error_log('The APCu PHP extension must be loaded to use APCu Cache.');

            return false;
        }

        return true;
    }

    /**
     * The driver's identifier, as accepted by OSC_CACHE.
     *
     * @return string
     */
    public function _get_cache()
    {
        return 'apcu';
    }

    protected function statsTitle(): string
    {
        return 'APC stats';
    }
}
