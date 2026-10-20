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
 * A stored Idempotency-Key: the request it was first sent with, and its answer once there is one.
 */
final class IdempotencyRecord
{
    public const LOCKED = 'locked';
    public const DONE   = 'done';

    /**
     * @param string      $status   LOCKED while the first request runs, then DONE
     * @param string|null $response the stored answer as JSON, once DONE
     */
    public function __construct(private string $fingerprint, private string $status, private ?string $response = null)
    {
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function isLocked(): bool
    {
        return $this->status !== self::DONE;
    }

    public function response(): ?string
    {
        return $this->response;
    }
}
