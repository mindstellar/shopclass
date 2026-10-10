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
 * A Redis-protocol client in plain PHP, for a server with no phpredis extension. It opens the
 * socket on the first command, signs in and picks the database, then sends each command and
 * reads its reply.
 */
final class RespClient implements RedisClient
{
    /** @var resource|null */
    private $stream;

    /**
     * @param array{host?:string,port?:int,password?:string,username?:string,database?:int,timeout?:float} $config
     * @param resource|null $stream an open stream to use instead of connecting, for tests
     */
    public function __construct(private array $config, $stream = null)
    {
        $this->stream = $stream;
    }

    public function command(string ...$args): mixed
    {
        if ($this->stream === null) {
            $this->open();
        }
        $this->write($args);

        return $this->read();
    }

    /**
     * Connect, sign in and pick the database. A host starting with "/" is a Unix socket, and
     * "tls://host" connects over TLS.
     */
    private function open(): void
    {
        $host    = (string) ($this->config['host'] ?? '127.0.0.1');
        $timeout = (float) ($this->config['timeout'] ?? 1.0);
        $address = $host[0] === '/'
            ? 'unix://' . $host
            : (str_contains($host, '://') ? $host : 'tcp://' . $host) . ':' . (int) ($this->config['port'] ?? 6379);

        $stream = @stream_socket_client($address, $code, $message, $timeout);
        if ($stream === false) {
            throw new \RuntimeException('Redis server ' . $address . ' did not answer: ' . $message);
        }
        stream_set_timeout($stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
        $this->stream = $stream;

        // A wrong password or database is as good as no server: every later command would fail.
        try {
            $password = (string) ($this->config['password'] ?? '');
            if ($password !== '') {
                $username = (string) ($this->config['username'] ?? '');
                $username === '' ? $this->command('AUTH', $password) : $this->command('AUTH', $username, $password);
            }
            $database = (int) ($this->config['database'] ?? 0);
            if ($database > 0) {
                $this->command('SELECT', (string) $database);
            }
        } catch (\UnexpectedValueException $e) {
            throw $this->drop('refused the sign-in: ' . $e->getMessage());
        }
    }

    /**
     * @param string[] $args
     */
    private function write(array $args): void
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        $stream = $this->stream;
        for ($sent = 0, $length = strlen($out); $sent < $length; $sent += $written) {
            $written = @fwrite($stream, substr($out, $sent));
            if ($written === false || $written === 0) {
                throw $this->drop('could not send');
            }
        }
    }

    private function read(): mixed
    {
        $line = @fgets($this->stream);
        if ($line === false) {
            throw $this->drop('did not reply');
        }
        $type = $line[0];
        $body = substr($line, 1, -2);

        switch ($type) {
            case '+':
                return true;
            case '-':
                throw new \UnexpectedValueException('Redis refused the command: ' . $body);
            case ':':
                return (int) $body;
            case '$':
                return (int) $body < 0 ? null : $this->readBytes((int) $body);
            case '*':
                if ((int) $body < 0) {
                    return null;
                }
                $list = [];
                for ($i = 0, $count = (int) $body; $i < $count; $i++) {
                    $list[] = $this->read();
                }

                return $list;
        }
        throw $this->drop('sent a reply it does not understand');
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = @fread($this->stream, $length + 2 - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw $this->drop('stopped replying');
            }
            $data .= $chunk;
        }

        return substr($data, 0, $length);
    }

    /**
     * Close a connection that broke, so the next command starts a new one, and say why.
     */
    private function drop(string $what): \RuntimeException
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;

        return new \RuntimeException('Redis server ' . $what);
    }
}
