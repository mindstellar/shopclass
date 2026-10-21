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

namespace mindstellar\billing\event;

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * An order changed status. Listen to this class to hear all six moves, or to one subclass for a
 * single move. Old callbacks on the matching `billing_order_*` hook get ($orderId, $userId).
 */
abstract class OrderEvent extends Event implements Bridged
{
    private ?Order $order = null;

    public function __construct(private int $orderId, private int $userId)
    {
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * The order as it is stored now, or null when it is gone.
     */
    public function order(): ?Order
    {
        return $this->order ??= OrderStore::find($this->orderId);
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->orderId, $this->userId);
    }
}
