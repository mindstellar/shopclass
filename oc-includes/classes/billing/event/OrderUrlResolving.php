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

use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * The buyer's page for one order, as order e-mails link it. A theme with its own order page sets it.
 * Old callbacks on `billing_order_url` get ($url, $orderId).
 */
final class OrderUrlResolving extends Event implements Bridged
{
    public function __construct(private int $orderId, private string $url)
    {
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public static function legacyNames(): array
    {
        return ['billing_order_url'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->url = (string) $fn($this->url, $this->orderId);
    }
}
