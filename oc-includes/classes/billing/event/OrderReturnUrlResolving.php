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
 * Where a payment gateway sends the buyer back after paying. A listener may set another page.
 * Old callbacks on `billing_order_return_url` get ($url, $orderId).
 */
final class OrderReturnUrlResolving extends Event implements Bridged
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
        return ['billing_order_return_url'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->url = (string) $fn($this->url, $this->orderId);
    }
}
