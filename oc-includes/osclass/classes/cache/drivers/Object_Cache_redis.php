<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\base\ObjectCache;
use mindstellar\cache\PhpRedisClient;
use mindstellar\cache\RedisClient;
use mindstellar\cache\RespClient;

/**
 * Object cache on a Redis-protocol server: Redis, Valkey, KeyDB or Dragonfly. Set OSC_CACHE to
 * 'redis' and point the $_cache_config global (or OSC_CACHE_HOST and friends) at the server.
 * It uses the phpredis extension when installed, and a built-in client otherwise.
 */
class Object_Cache_redis extends ObjectCache
{
    private RedisClient $client;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * Set after the first call the server did not answer; the rest of the request skips it.
     *
     * @var bool
     */
    private $down = false;

    /**
     * @param RedisClient|null $client a client to use instead of the configured one, for tests
     */
    public function __construct(?RedisClient $client = null)
    {
        parent::__construct();
        global $_cache_config;
        $server       = (isset($_cache_config[0]) && is_array($_cache_config[0])) ? $_cache_config[0] : array();
        $this->config = array(
            'host'     => (string) ($server['default_host'] ?? '') ?: '127.0.0.1',
            'port'     => (int) ($server['default_port'] ?? 6379),
            'password' => (string) ($server['password'] ?? ''),
            'username' => (string) ($server['username'] ?? ''),
            'database' => (int) ($server['database'] ?? 0),
            'timeout'  => 1.0,
        );
        // phpredis is compiled C and faster; the built-in client is for servers without it.
        $this->client = $client ?? (class_exists('Redis') ? new PhpRedisClient($this->config) : new RespClient($this->config));
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
        $stored = $this->call('SET', $this->_key($key), $this->encode($data), 'EX', (string) $this->ttl($expire), 'NX') === true;
        if ($stored) {
            $this->cache[$key] = $data;
        }

        return $stored;
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

        return (int) $this->call('DEL', $this->_key($key)) > 0;
    }

    /**
     * Clears this site's keys. Other sites on the same server keep theirs.
     *
     * @return bool
     */
    public function flush()
    {
        $this->cache = array();
        $cursor      = '0';
        do {
            $reply = $this->call('SCAN', $cursor, 'MATCH', $this->site_prefix . '*', 'COUNT', '500');
            if (!is_array($reply) || count($reply) !== 2) {
                return false;
            }
            [$cursor, $keys] = $reply;
            if (is_array($keys) && $keys !== array()) {
                $this->call('DEL', ...array_map('strval', $keys));
            }
        } while ((string) $cursor !== '0');

        return !$this->down;
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

        $reply = $this->call('GET', $this->_key($key));
        if (!is_string($reply)) {
            $found = false;
            ++$this->cache_misses;

            return false;
        }

        $value             = $this->decode($reply);
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

        return $this->call('SET', $this->_key($key), $this->encode($data), 'EX', (string) $this->ttl($expire)) === true;
    }

    /**
     * Atomically increment a numeric key, creating it at $initial on first sighting.
     *
     * The create is SET NX, so of two callers racing to create the key one creates it and the
     * other counts on top; no count is lost.
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
        $rKey  = $this->_key($key);
        $value = $initial;
        if ($this->call('SET', $rKey, (string) $initial, 'EX', (string) $this->ttl($expire), 'NX') !== true) {
            $reply = $this->call('INCRBY', $rKey, (string) $by);
            $value = is_int($reply) ? $reply : $initial;
        }
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Normalised cache statistics for the admin's cache screen, from the server's INFO reply.
     * Not part of iObject_Cache; callers probe for it with method_exists().
     *
     * @return array<string,int|string|null>|null Null when the server does not answer.
     */
    public function statsData()
    {
        $reply = $this->call('INFO');
        if (!is_string($reply)) {
            return null;
        }
        $info = array();
        foreach (preg_split('/\r?\n/', $reply) as $line) {
            if (strpos($line, ':') !== false && $line[0] !== '#') {
                [$name, $value]      = explode(':', $line, 2);
                $info[trim($name)]   = trim($value);
            }
        }
        $db      = 'db' . (int) $this->config['database'];
        $entries = isset($info[$db]) && preg_match('/keys=(\d+)/', $info[$db], $m) ? (int) $m[1] : 0;
        $number  = static function (string $name) use ($info) {
            return isset($info[$name]) && is_numeric($info[$name]) ? (int) $info[$name] : null;
        };
        $host = (string) $this->config['host'];

        return array(
            'entries'      => $entries,
            'hits'         => $number('keyspace_hits'),
            'misses'       => $number('keyspace_misses'),
            'memory_used'  => $number('used_memory'),
            'memory_total' => $number('maxmemory') ?: null,
            'uptime'       => $number('uptime_in_seconds'),
            'evictions'    => $number('evicted_keys'),
            'server'       => ($host !== '' && $host[0] === '/' ? $host : $host . ':' . (int) $this->config['port'])
                . ' (' . ($this->client instanceof PhpRedisClient ? 'phpredis' : __('built-in client')) . ')',
        );
    }

    /**
     * One command, or null when the server is down. A refused command still means the server
     * answered; only a connection error marks it down for the rest of the request.
     *
     * @return mixed
     */
    private function call(string ...$args)
    {
        if ($this->down) {
            return null;
        }
        try {
            return $this->client->command(...$args);
        } catch (\UnexpectedValueException $e) {
            return null;
        } catch (\RuntimeException $e) {
            $this->down = true;
            error_log($e->getMessage());

            return null;
        }
    }

    /**
     * @param mixed $data
     */
    private function encode($data): string
    {
        // A whole number is stored as bare digits, so increment() can count on top of it.
        return is_int($data) ? (string) $data : serialize($data);
    }

    /**
     * @return mixed
     */
    private function decode(string $reply)
    {
        if (preg_match('/^-?\d+$/', $reply)) {
            return (int) $reply;
        }

        return unserialize($reply);
    }

    /**
     * True when phpredis is installed or PHP may open sockets, which the built-in client needs.
     *
     * @return bool
     */
    public static function is_supported()
    {
        return class_exists('Redis') || function_exists('stream_socket_client');
    }

    /**
     * The driver's identifier, as accepted by OSC_CACHE.
     *
     * @return string
     */
    public function _get_cache()
    {
        return 'redis';
    }

    protected function statsTitle(): string
    {
        return 'Redis stats';
    }
}
