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
 * How a request stood in one bucket after it was counted.
 */
final class RateLimitResult
{
    public function __construct(
        private RateBucket $bucket,
        private bool $allowed,
        private int $remaining,
        private int $reset
    ) {
    }

    public function bucket(): RateBucket
    {
        return $this->bucket;
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    /** Seconds until the window ends. */
    public function reset(): int
    {
        return $this->reset;
    }
}
