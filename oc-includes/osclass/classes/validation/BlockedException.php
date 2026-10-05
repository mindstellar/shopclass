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

namespace mindstellar\validation;

/**
 * Too many wrong tries in a row, or too many writes in a while; another is taken after
 * retryAfter() seconds.
 */
final class BlockedException extends RefusedException
{
    private bool $rateLimit = false;

    public function __construct(string $message, private int $retryAfter)
    {
        parent::__construct($message);
    }

    /**
     * Too many writes in the window, rather than wrong tries.
     */
    public static function rateLimit(string $message, int $retryAfter): self
    {
        $blocked            = new self($message, $retryAfter);
        $blocked->rateLimit = true;

        return $blocked;
    }

    public function isRateLimit(): bool
    {
        return $this->rateLimit;
    }

    public function retryAfter(): int
    {
        return max(1, $this->retryAfter);
    }
}
