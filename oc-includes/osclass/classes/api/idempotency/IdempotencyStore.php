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

namespace mindstellar\api\idempotency;

/**
 * Where Idempotency-Keys are kept: t_key_value in production, an array in tests.
 */
interface IdempotencyStore
{
    /**
     * Lock a key for a first request, or return what is stored under it. An expired key,
     * or a lock older than $lockTtl seconds (its request died), is taken over.
     *
     * @param int $now       Unix time
     * @param int $expiresAt Unix time the key is forgotten
     *
     * @return IdempotencyRecord|null null when this call took the lock
     */
    public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl): ?IdempotencyRecord;

    /**
     * Store the answer of the request holding the lock.
     */
    public function complete(string $hash, int $status, string $response): void;

    /**
     * Drop a lock whose request failed, so the key can be sent again.
     */
    public function release(string $hash): void;
}
