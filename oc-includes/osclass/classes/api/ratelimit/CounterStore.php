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
 * Shared memory for counters that expire.
 */
interface CounterStore
{
    /** Set a key only when it is absent; false when it exists. */
    public function add(string $key, int $value, int $ttl): bool;

    /** Add one and return the new value; null when the store cannot be used. */
    public function inc(string $key): ?int;

    public function get(string $key): ?int;

    public function set(string $key, int $value, int $ttl): void;
}
