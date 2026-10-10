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
 * Object cache on one or more memcached servers, through the memcached extension. Point the
 * $_cache_config global (or OSC_CACHE_HOST) at the servers.
 */
class MemcachedCache extends Cache
{
    protected $_memcache_conf = array(
        'default' => array(
            'default_host'   => '127.0.0.1',
            'default_port'   => 11211,
            'default_weight' => 1
        )
    );

    /**
     * Holds the Memcached client.
     *
     * @var \Memcached
     */
    private $memcached;

    /**
     * Set after the first call the server did not answer; the rest of the request skips it.
     *
     * @var bool
     */
    private $down = false;

    /**
     * Sets up object properties and connects to the configured server(s).
     */
    public function __construct()
    {
        parent::__construct();
        $cache_server      = array();
        global $_cache_config;
        if (!isset($_cache_config) || !is_array($_cache_config)) {
            $cache_server[] = array(
                'hostname' => $this->_memcache_conf['default']['default_host'],
                'port'     => $this->_memcache_conf['default']['default_port'],
                'weight'   => $this->_memcache_conf['default']['default_weight']
            );
        } else {
            foreach ($_cache_config as $_server) {
                $cache_server[] = array(
                    'hostname' => $_server['default_host'],
                    'port'     => $_server['default_port'],
                    'weight'   => $_server['default_weight']
                );
            }
        }

        $this->memcached = new \Memcached();
        // Short limits so a hung server costs about a second per request, not a hang.
        $this->memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 1000);
        $this->memcached->setOption(\Memcached::OPT_POLL_TIMEOUT, 1000);
        $this->memcached->setOption(\Memcached::OPT_SEND_TIMEOUT, 1000000);
        $this->memcached->setOption(\Memcached::OPT_RECV_TIMEOUT, 1000000);
        foreach ($cache_server as $_config) {
            $this->memcached->addServer($_config['hostname'], $_config['port'], $_config['weight']);
        }
    }

    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @param int|string $key
     * @param mixed      $data
     * @param int        $expire
     *
     * @return bool False if the key already exists, true on success.
     */
    public function add($key, $data, $expire = 0)
    {
        $data = self::copy($data);

        if ($this->down) {
            return false;
        }
        $expire = $this->ttl($expire);
        $result = $this->memcached->add($this->_key($key), $data, $expire);
        $this->answered();
        if (false !== $result) {
            $this->cache[$key] = $data;
        }

        return $result;
    }

    /**
     * Remove the contents of the cache key.
     *
     * @param int|string $key
     *
     * @return bool
     */
    public function delete($key)
    {
        unset($this->cache[$key]);
        if ($this->down) {
            return false;
        }
        $result = $this->memcached->delete($this->_key($key));
        $this->answered();

        return $result;
    }

    /**
     * Clears the object cache of all data.
     *
     * @return bool
     */
    public function flush()
    {
        $this->cache = array();
        if ($this->down) {
            return false;
        }

        return $this->memcached->flush();
    }

    /**
     * Retrieves the cache contents, if it exists.
     *
     * @param int|string $key
     * @param bool       $found set true if the key was present, false otherwise
     *
     * @return bool|mixed The cached contents, or false on miss.
     */
    public function get($key, &$found = null)
    {
        $value = $this->local($key, $found);
        if ($found) {
            return $value;
        }

        if ($this->down) {
            $found = false;
            ++$this->cache_misses;

            return false;
        }
        $value = $this->memcached->get($this->_key($key));
        // Only a real answer is a hit: a dead or unreachable server reports a miss, so the
        // caller loads from the database instead of getting false as if it were the value.
        if ($this->memcached->getResultCode() !== \Memcached::RES_SUCCESS) {
            $this->answered();
            $found = false;
            ++$this->cache_misses;

            return false;
        }

        $found             = true;
        $this->cache[$key] = self::copy($value);
        ++$this->cache_hits;

        return $value;
    }

    /**
     * Sets the data contents into the cache.
     *
     * @param int|string $key
     * @param mixed      $data
     * @param int        $expire
     *
     * @return bool
     */
    public function set($key, $data, $expire = 0)
    {
        $data = self::copy($data);

        $this->cache[$key] = $data;

        if ($this->down) {
            return false;
        }
        $expire = $this->ttl($expire);
        $result = $this->memcached->set($this->_key($key), $data, $expire);
        $this->answered();

        return $result;
    }

    /**
     * Atomically increment a numeric key, creating it at $initial on first sighting.
     *
     * Unlike a get()/set() read-modify-write, concurrent callers do not clobber each
     * other, which is what a hit counter needs. NOT the 4-arg
     * \Memcached::increment($key, $by, $initial, $expiry): its auto-create only works
     * under the binary protocol, and the default ASCII protocol warns and returns
     * false there. So: 2-arg increment (atomic), and on a miss add() the key at
     * $initial. add() is create-only, so if a second caller raced us to create it our
     * add fails and we increment once more — no count is lost.
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
        $mKey   = $this->_key($key);
        if ($this->down) {
            $this->cache[$key] = $initial;

            return $initial;
        }

        $value = $this->memcached->increment($mKey, $by);
        if (false === $value && !$this->answered()) {
            $value = $initial;
        } elseif (false === $value) {
            if ($this->memcached->add($mKey, $initial, $expire)) {
                $value = $initial;
            } else {
                $value = $this->memcached->increment($mKey, $by);
                if (false === $value) {
                    $value = $initial;
                }
            }
        }

        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Normalised cache statistics for the admin's cache screen.
     *
     * Deliberately NOT part of CacheDriver: third-party drivers implement that
     * interface, and adding a required method would fatal them. Callers probe with
     * method_exists() instead. The legacy stats() is left alone — it echoes debug
     * markup and anything already calling it keeps working.
     *
     * @return array<string,int|string|null>|null Null when the driver has nothing to report.
     */
    public function statsData()
    {
        if (!is_object($this->memcached) || !method_exists($this->memcached, 'getStats')) {
            return null;
        }
        $all = @$this->memcached->getStats();
        if (!is_array($all) || $all === array()) {
            return null;
        }
        // getStats() is keyed by "host:port"; report the first server that answered
        // and name it, so a multi-server setup does not silently show only one.
        $server = null;
        $stats  = null;
        foreach ($all as $name => $row) {
            if (is_array($row) && isset($row['uptime'])) {
                $server = $name;
                $stats  = $row;
                break;
            }
        }
        if ($stats === null) {
            return null;
        }

        return array(
            'entries'      => isset($stats['curr_items']) ? (int)$stats['curr_items'] : null,
            'hits'         => isset($stats['get_hits']) ? (int)$stats['get_hits'] : null,
            'misses'       => isset($stats['get_misses']) ? (int)$stats['get_misses'] : null,
            'memory_used'  => isset($stats['bytes']) ? (int)$stats['bytes'] : null,
            'memory_total' => isset($stats['limit_maxbytes']) ? (int)$stats['limit_maxbytes'] : null,
            'uptime'       => isset($stats['uptime']) ? (int)$stats['uptime'] : null,
            'evictions'    => isset($stats['evictions']) ? (int)$stats['evictions'] : null,
            'server'       => $server,
        );
    }

    /**
     * Whether the last call reached the server. Only a connection error marks it down:
     * a refused value (too large, not a number) still means the server answered.
     *
     * @return bool
     */
    private function answered(): bool
    {
        $connection = array(
            'RES_HOST_LOOKUP_FAILURE', 'RES_CONNECTION_FAILURE', 'RES_CONNECTION_BIND_FAILURE',
            'RES_CONNECTION_SOCKET_CREATE_FAILURE', 'RES_WRITE_FAILURE', 'RES_READ_FAILURE',
            'RES_UNKNOWN_READ_FAILURE', 'RES_NO_SERVERS', 'RES_ERRNO', 'RES_FAIL_UNIX_SOCKET',
            'RES_TIMEOUT', 'RES_SERVER_MARKED_DEAD', 'RES_SERVER_TEMPORARILY_DISABLED', 'RES_AUTH_FAILURE',
        );
        $code = $this->memcached->getResultCode();
        foreach ($connection as $name) {
            if (defined('Memcached::' . $name) && $code === constant('Memcached::' . $name)) {
                $this->down = true;

                return false;
            }
        }

        return true;
    }

    /**
     * Whether the memcached extension is loaded.
     *
     * @return bool
     */
    public static function is_supported()
    {
        if (!class_exists('Memcached')) {
            error_log('The memcached PHP extension must be loaded to use Memcached Cache.');

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
        return 'memcached';
    }

    protected function statsTitle(): string
    {
        return 'Memcached stats';
    }
}
