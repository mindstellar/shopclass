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

/**
 * The order was paid. Old callbacks on `billing_order_paid` get ($orderId, $userId, $credits).
 */
final class OrderPaid extends OrderEvent
{
    public function __construct(int $orderId, int $userId, private int $credits = 0)
    {
        parent::__construct($orderId, $userId);
    }

    public function credits(): int
    {
        return $this->credits;
    }

    public static function legacyNames(): array
    {
        return ['billing_order_paid'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->orderId(), $this->userId(), $this->credits);
    }
}
