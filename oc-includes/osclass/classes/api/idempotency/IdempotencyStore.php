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
     * Lock a key for a first request, or return what is stored under it. An expired key is
     * taken over, and so is a lock older than $lockTtl seconds (its request died), but only by
     * the same request.
     *
     * @param int    $now       Unix time
     * @param int    $expiresAt Unix time the key is forgotten
     * @param string $lock      this request's lock token, which complete() and release() need
     *
     * @return IdempotencyRecord|null null when this call took the lock
     */
    public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl, string $lock): ?IdempotencyRecord;

    /**
     * Store the answer, while the lock is still this request's.
     */
    public function complete(string $hash, string $lock, int $status, string $response): void;

    /**
     * Drop a lock whose request failed, while it is still this request's, so the key can be sent again.
     */
    public function release(string $hash, string $lock): void;
}
