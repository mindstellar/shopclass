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
 * One rate limit a request is counted in: a named bucket, who is counted, and the limit.
 */
final class RateBucket
{
    public function __construct(
        private string $name,
        private string $key,
        private int $max,
        private int $window = 60
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function key(): string
    {
        return $this->key;
    }

    /** Requests per window; <= 0 is unlimited. */
    public function max(): int
    {
        return $this->max;
    }

    public function window(): int
    {
        return max(1, $this->window);
    }
}
