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

namespace mindstellar\api\ratelimit;

/**
 * APCu as a CounterStore. APCu is per server, so a pool of servers counts separately.
 */
final class ApcuStore implements CounterStore
{
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function add(string $key, int $value, int $ttl): bool
    {
        return apcu_add($key, $value, $ttl);
    }

    public function inc(string $key): ?int
    {
        $value = apcu_inc($key);

        return $value === false ? null : $value;
    }

    public function get(string $key): ?int
    {
        $value = apcu_fetch($key, $found);

        return $found ? (int) $value : null;
    }

    public function set(string $key, int $value, int $ttl): void
    {
        apcu_store($key, $value, $ttl);
    }
}
