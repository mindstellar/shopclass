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

namespace mindstellar\location;

/**
 * Thrown by a geocode provider whose request allowance is used up. The lookup job waits
 * retryAfter() seconds and tries again, without counting it as a failure.
 */
final class GeocodeLimitReached extends \RuntimeException
{
    public function __construct(string $message, private int $retryAfter)
    {
        parent::__construct($message);
    }

    /** Seconds until the allowance renews; at least one minute. */
    public function retryAfter(): int
    {
        return max(60, $this->retryAfter);
    }
}
