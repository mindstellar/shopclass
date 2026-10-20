<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A clock a test moves: it reads the time from a closure, usually one bound to a variable.
 */
final class TestClock implements \mindstellar\utility\Clock
{
    public function __construct(private \Closure $now)
    {
    }

    public function now(): int
    {
        return ($this->now)();
    }
}
