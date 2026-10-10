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

use mindstellar\base\Cache;

/**
 * Object cache on a Redis-protocol server: Redis, Valkey, KeyDB or Dragonfly. Set OSC_CACHE to
 * 'redis' and point the $_cache_config global (or OSC_CACHE_HOST and friends) at the server.
 * It uses the phpredis extension when installed, and a built-in client otherwise.
 */
class RedisCache extends Cache
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

    /** A second connection for waitSignal(), whose reads may block for a while. */
    private ?RedisClient $waiter = null;

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
        $this->client = $client ?? self::client($this->config);
    }

    /**
     * Tell a waiting worker that $name has work. At most one signal is kept: one wakes it.
     *
     * @param string $name
     *
     * @return void
     */
    public function signal(string $name): void
    {
        $key = $this->_key('signal_' . $name);
        $this->call('LPUSH', $key, '1');
        $this->call('LTRIM', $key, '0', '0');
    }

    /**
     * Wait up to $seconds for a signal().
     *
     * @param string $name
     * @param int    $seconds
     *
     * @return bool|null true when one came, false on a timeout, null when the server cannot be reached
     */
    public function waitSignal(string $name, int $seconds): ?bool
    {
        $this->waiter ??= self::client(array('timeout' => (float) ($seconds + 5)) + $this->config);
        try {
            // A timeout is null from the built-in client and an empty list from phpredis.
            return !empty($this->waiter->command('BLPOP', $this->_key('signal_' . $name), (string) max(1, $seconds)));
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * The site's object cache when it is this driver, else null.
     */
    public static function site(): ?self
    {
        $cache = CacheManager::getInstance();

        return $cache instanceof self ? $cache : null;
    }

    /**
     * Add $by to the counter $name, which lives $ttl seconds from its first count.
     *
     * @return int|null the new count; null when the server cannot be reached
     */
    public function counter(string $name, int $by, int $ttl): ?int
    {
        $key = $this->_key('counter_' . $name);
        if ($this->call('SET', $key, (string) $by, 'EX', (string) max(1, $ttl), 'NX') === true) {
            return $by;
        }
        $reply = $this->call('INCRBY', $key, (string) $by);

        return is_int($reply) ? $reply : null;
    }

    /**
     * The counters $names, 0 for one not set.
     *
     * @param string[] $names
     *
     * @return array<string,int>|null name => count; null when the server cannot be reached
     */
    public function counters(array $names): ?array
    {
        $names = array_values($names);
        if ($names === array()) {
            return array();
        }
        $reply = $this->call('MGET', ...array_map(fn (string $n): string => $this->_key('counter_' . $n), $names));
        if (!is_array($reply)) {
            return null;
        }
        $out = array();
        foreach ($names as $i => $name) {
            $out[$name] = (int) ($reply[$i] ?? 0);
        }

        return $out;
    }

    /**
     * Add $by to $field of the hash $name, which then lives $ttl seconds.
     *
     * @return int|null the field's new count; null when the server cannot be reached
     */
    public function hashAdd(string $name, string $field, int $by, int $ttl): ?int
    {
        $key   = $this->_key('hash_' . $name);
        $reply = $this->call('HINCRBY', $key, $field, (string) $by);
        if (!is_int($reply)) {
            return null;
        }
        $this->call('EXPIRE', $key, (string) max(1, $ttl));

        return $reply;
    }

    /**
     * Every field of the hash $name.
     *
     * @return array<string,int>|null field => count; null when the server cannot be reached
     */
    public function hashRead(string $name): ?array
    {
        $reply = $this->call('HGETALL', $this->_key('hash_' . $name));

        return is_array($reply) ? self::pairs($reply) : null;
    }

    /**
     * Remove $fields from the hash $name.
     *
     * @param string[] $fields
     */
    public function hashDelete(string $name, array $fields): void
    {
        if ($fields !== array()) {
            $this->call('HDEL', $this->_key('hash_' . $name), ...array_values($fields));
        }
    }

    /**
     * Read the hash $name and remove it, so counts added meanwhile start a new one.
     *
     * @return array<string,int>|null field => count, empty when there is none; null when the
     *                                server cannot be reached
     */
    public function hashTake(string $name): ?array
    {
        $key   = $this->_key('hash_' . $name);
        $taken = $key . ':taking:' . bin2hex(random_bytes(6));
        // RENAME is refused when there is no hash, so nothing was counted since the last take.
        if ($this->call('RENAME', $key, $taken) !== true) {
            return $this->down ? null : array();
        }
        $reply = $this->call('HGETALL', $taken);
        $this->call('DEL', $taken);

        return is_array($reply) ? self::pairs($reply) : null;
    }

    /**
     * A flat field, value, field, value list as field => int. phpredis may already key it.
     *
     * @param array<int|string,mixed> $reply
     *
     * @return array<string,int>
     */
    private static function pairs(array $reply): array
    {
        if ($reply !== array_values($reply)) {
            return array_map('intval', $reply);
        }
        $out = array();
        for ($i = 0, $n = count($reply) - 1; $i < $n; $i += 2) {
            $out[(string) $reply[$i]] = (int) $reply[$i + 1];
        }

        return $out;
    }

    /**
     * phpredis is compiled C and faster; the built-in client is for servers without it.
     *
     * @param array<string,mixed> $config
     *
     * @return RedisClient
     */
    private static function client(array $config): RedisClient
    {
        return class_exists('Redis') ? new PhpRedisClient($config) : new RespClient($config);
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
     * @param string    $storeKey
     * @param bool|null $found
     *
     * @return mixed
     */
    protected function fetch(string $storeKey, &$found)
    {
        $reply = $this->call('GET', $storeKey);
        $found = is_string($reply);

        return $found ? $this->decode($reply) : false;
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
     * Not part of CacheDriver; callers probe for it with method_exists().
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
