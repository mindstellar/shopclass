<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Object_Cache_apc class
 */
class Object_Cache_apc implements iObject_Cache
{

    /**
     * Holds the cached objects
     *
     * @var array
     * @access private
     * @since  3.4
     */
    public $cache = array();

    /**
     * The amount of times the cache data was already stored in the cache.
     *
     * @since  3.4
     * @access private
     * @var int
     */
    public $cache_hits = 0;

    /**
     * Amount of times the cache did not have the request in cache
     *
     * @var int
     * @access public
     * @since  3.4
     */
    public $cache_misses = 0;

    /**
     * The blog prefix to prepend to keys in non-global groups.
     *
     * @var int
     * @access private
     * @since  3.4
     */
    public $site_prefix;
    public $default_expiration = 60;

    /**
     * Sets up object properties; PHP 5 style constructor
     *
     * @since 3.4
     */
    public function __construct()
    {
        $this->site_prefix = 'osc_' . substr(md5(defined('WEB_PATH') ? WEB_PATH : __DIR__), 0, 12) . '_';
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
        $id = $this->_key($key);

        if (is_object($data)) {
            $data = clone $data;
        }

        $store_data = $data;

        if (is_array($data)) {
            $store_data = new ArrayObject($data);
        }

        $expire = ($expire == 0) ? $this->default_expiration : $expire;
        $result = apc_add($id, $store_data, $expire);
        if (false !== $result) {
            $this->cache[$key] = $data;
        }

        return $result;
    }

    /**
     * Remove the contents of the cache key in the group
     *
     * @param int|string $key What the contents in the cache are called
     *
     * @return bool False if the contents weren't deleted and true on success
     * @since 3.4
     *
     */
    public function delete($key)
    {

        $result = apc_delete($this->_key($key));
        if (false !== $result) {
            unset($this->cache[$key]);
        }

        return $result;
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
        if (extension_loaded('apcu')) {
            return apc_clear_cache();
        }

        return apc_clear_cache('user');
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
        if (isset($this->cache[$key])) {
            if (is_object($this->cache[$key])) {
                $value = clone $this->cache[$key];
            } else {
                $value = $this->cache[$key];
            }
            ++$this->cache_hits;
            $return = $value;
        } else {
            $value = apc_fetch($this->_key($key), $found);

            if (is_object($value) && 'ArrayObject' === get_class($value)) {
                $value = $value->getArrayCopy();
            }
            if (null === $value) {
                $value = false;
            }
            $this->cache[$key] = is_object($value) ? clone $value : $value;
            if ($found) {
                ++$this->cache_hits;
                $return = $this->cache[$key];
            } else {
                ++$this->cache_misses;
                $return = false;
            }
        }

        return $return;
    }

    /**
     * Sets the data contents into the cache
     *
     * @param int|string $key    What to call the contents in the cache
     * @param mixed      $data   The contents to store in the cache
     * @param int        $expire Not Used
     *
     * @return bool Always returns true on success, false on failure
     * @since 3.4
     *
     */
    public function set($key, $data, $expire = 0)
    {
        if (is_object($data)) {
            $data = clone $data;
        }

        $store_data = $data;

        if (is_array($data)) {
            $store_data = new ArrayObject($data);
        }

        $this->cache[$key] = $data;

        $expire = ($expire == 0) ? $this->default_expiration : $expire;

        return apc_store($this->_key($key), $store_data, $expire);
    }

    /**
     * Echoes the stats of the caching.
     * Gives the cache hits, and cache misses.
     *
     * @since 3.4
     */
    public function stats()
    {
        echo "<div style='position:absolute; width:200px;top:0px;'><div style='float:right;margin-right:30px;margin-top:15px;border: 1px red solid;
border-radius: 17px;
padding: 1em;'><h2>APC stats</h2>";
        echo '<p>';
        echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
        echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
        echo '</p>';
        echo '<ul>';
        echo '</ul></div></div>';
    }

    /**
     * is_supported()
     *
     * Check to see if APC is available on this system, bail if it isn't.
     */
    /**
     * Normalised cache statistics for the admin's cache screen.
     *
     * Deliberately NOT part of iObject_Cache: third-party drivers implement that
     * interface, and adding a required method would fatal them. Callers probe with
     * method_exists() instead. The legacy stats() is left alone — it echoes debug
     * markup and anything already calling it keeps working.
     *
     * @return array|null Null when the driver has nothing to report.
     */
    public function statsData()
    {
        if (!function_exists('apc_cache_info')) {
            return null;
        }
        $info = @apc_cache_info('user', true);
        $sma  = function_exists('apc_sma_info') ? @apc_sma_info(true) : array();
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
     * Namespace every key with a value unique to this install.
     *
     * APCu and memcached are shared stores: several installs can sit behind one
     * PHP-FPM pool or point at one memcached. site_prefix existed for exactly this
     * but was set to '' and never read, so two installs collided on identical keys
     * and could serve each other's cached values. Derived from WEB_PATH, so it is
     * stable across requests and different for each install.
     *
     * @param int|string $key
     *
     * @return string
     */
    private function _key($key)
    {
        return $this->site_prefix . $key;
    }

    public static function is_supported()
    {
        if (!extension_loaded('apc') or ini_get('apc.enabled') != '1') {
            error_log('The APC PHP extension must be loaded to use APC Cache.');

            return false;
        }

        return true;
    }

    /**
     *
     */
    public function __destruct()
    {
    }

    /**
     * @return string
     */
    public function _get_cache()
    {
        return 'apc';
    }

    /**
     * Reset keys
     *
     * @since      3.0.0
     * @deprecated 3.5.0
     */
    public function reset()
    {
        $this->cache = array();
    }

    /**
     * Utility function to determine whether a key exists in the cache.
     *
     * @param $key
     *
     * @return bool
     * @since  3.4
     *
     * @access protected
     *
     */
    protected function _exists($key)
    {
        return isset($this->cache[$key]);
    }
}
