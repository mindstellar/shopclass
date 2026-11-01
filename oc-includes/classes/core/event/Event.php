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

namespace mindstellar\event;

/**
 * Base for core's typed events. A listener that calls stopPropagation() is the last one to run.
 */
abstract class Event
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Whether a callback added on the old hook $name runs for this event; see Bridged.
     */
    public function accepts(string $name): bool
    {
        return true;
    }
}
