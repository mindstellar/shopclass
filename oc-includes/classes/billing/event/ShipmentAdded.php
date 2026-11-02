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
 * A parcel of the order was sent. Old callbacks on `billing_shipment_add_after` get
 * ($orderId, $userId, $shipment), where $shipment is the parcel's number, counted from 1.
 */
final class ShipmentAdded extends Event implements Bridged
{
    private ?Order $order = null;

    public function __construct(private int $orderId, private int $userId, private int $shipment)
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
     * The parcel's number on the order, counted from 1.
     */
    public function shipment(): int
    {
        return $this->shipment;
    }

    /**
     * The order as it is stored now, or null when it is gone.
     */
    public function order(): ?Order
    {
        return $this->order ??= OrderStore::find($this->orderId);
    }

    public static function legacyNames(): array
    {
        return ['billing_shipment_add_after'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->orderId, $this->userId, $this->shipment);
    }
}
