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
 * A connection to a Redis-protocol server: Redis, Valkey, KeyDB or Dragonfly. RespClient speaks
 * the protocol itself; PhpRedisClient uses the phpredis extension when the server has it.
 */
interface RedisClient
{
    /**
     * Run one command and return its reply: a string, an int, a list, true for a status reply,
     * or null for a missing value.
     *
     * @throws \UnexpectedValueException when the server answers with an error
     * @throws \RuntimeException          when the server cannot be reached
     */
    public function command(string ...$args): mixed;
}
