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
 * A Redis-protocol client through the phpredis extension, which is faster than RespClient. It
 * connects on the first command and answers exactly as RespClient does.
 */
final class PhpRedisClient implements RedisClient
{
    private ?\Redis $redis = null;

    /**
     * @param array{host?:string,port?:int,password?:string,username?:string,database?:int,timeout?:float} $config
     */
    public function __construct(private array $config)
    {
    }

    public function command(string ...$args): mixed
    {
        try {
            $redis = $this->redis ?? $this->open();
            $redis->clearLastError();
            $reply = $redis->rawCommand(...$args);
            $error = $redis->getLastError();
        } catch (\RedisException $e) {
            $this->redis = null;

            throw new \RuntimeException('Redis server did not answer: ' . $e->getMessage(), 0, $e);
        }
        if ($error !== null) {
            throw new \UnexpectedValueException('Redis refused the command: ' . $error);
        }

        // phpredis reports a missing value as false; RespClient, and so every caller, as null.
        return $reply === false ? null : $reply;
    }

    private function open(): \Redis
    {
        $host     = (string) ($this->config['host'] ?? '') ?: '127.0.0.1';
        $port     = $host[0] === '/' ? 0 : (int) ($this->config['port'] ?? 6379);
        $timeout  = (float) ($this->config['timeout'] ?? 1.0);
        $password = (string) ($this->config['password'] ?? '');
        $username = (string) ($this->config['username'] ?? '');
        $redis    = new \Redis();
        // A web worker keeps its connection for the next request instead of opening one
        // per page. The CLI worker does not: its blocking wait needs a connection of its own.
        if (PHP_SAPI !== 'cli') {
            $id = 'osc-' . md5(implode('|', array($host, $port, $username, $this->config['database'] ?? 0, $timeout)));
            $redis->pconnect($host, $port, $timeout, $id, 0, $timeout);
        } else {
            $redis->connect($host, $port, $timeout, null, 0, $timeout);
        }

        if ($password !== '' && !$redis->auth($username === '' ? $password : [$username, $password])) {
            throw new \RedisException('the server refused the password');
        }
        $database = (int) ($this->config['database'] ?? 0);
        if ($database > 0 && !$redis->select($database)) {
            throw new \RedisException('the server refused database ' . $database);
        }

        return $this->redis = $redis;
    }
}
