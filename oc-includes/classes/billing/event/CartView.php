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
 * A read-only view of the cart being priced.
 */
final class CartView
{
    /**
     * @param array<string,mixed> $cart
     */
    public function __construct(private array $cart)
    {
    }

    /**
     * @return array<int,array<string,mixed>> {variant, qty, item_id, unit, amount, ...} each
     */
    public function lines(): array
    {
        return is_array($this->cart['lines'] ?? null) ? $this->cart['lines'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function address(): array
    {
        return is_array($this->cart['address'] ?? null) ? $this->cart['address'] : [];
    }

    public function currency(): string
    {
        return (string) ($this->cart['currency'] ?? '');
    }

    public function subtotal(): int
    {
        return (int) ($this->cart['subtotal'] ?? 0);
    }

    public function coupon(): ?string
    {
        return isset($this->cart['coupon']) ? (string) $this->cart['coupon'] : null;
    }

    public function userId(): int
    {
        return (int) ($this->cart['user_id'] ?? 0);
    }

    public function shippingMethod(): ?string
    {
        return isset($this->cart['shipping_method']) ? (string) $this->cart['shipping_method'] : null;
    }

    public function gateway(): ?string
    {
        return isset($this->cart['gateway']) ? (string) $this->cart['gateway'] : null;
    }

    public function needsShipping(): bool
    {
        return ($this->cart['needs_shipping'] ?? true) !== false;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->cart;
    }
}
