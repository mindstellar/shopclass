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

namespace mindstellar\job;

/**
 * Thrown by a handler that fails and wants its own retry timing: how long to wait, and how
 * many tries the job gets in all. Other failures use the queue's standard backoff.
 */
final class JobRetry extends \RuntimeException
{
    public function __construct(string $message, private int $delay, private int $maxAttempts)
    {
        parent::__construct($message);
    }

    public function delay(): int
    {
        return $this->delay;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
