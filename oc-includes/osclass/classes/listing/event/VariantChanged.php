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

namespace mindstellar\listing\event;

use mindstellar\event\Event;

/**
 * A variant's stock or selling price changed. The write has committed.
 */
abstract class VariantChanged extends Event
{
    public function __construct(
        private int $variantId,
        private int $itemId,
        private ?int $from,
        private ?int $to
    ) {
    }

    public function variantId(): int
    {
        return $this->variantId;
    }

    public function itemId(): int
    {
        return $this->itemId;
    }

    public function from(): ?int
    {
        return $this->from;
    }

    public function to(): ?int
    {
        return $this->to;
    }

    /**
     * The value went down. False when either side is unknown.
     */
    public function dropped(): bool
    {
        return $this->from !== null && $this->to !== null && $this->to < $this->from;
    }

    /**
     * Old-style callback: ($variantId, $itemId, $from, $to).
     */
    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->variantId, $this->itemId, $this->from, $this->to);
    }
}
