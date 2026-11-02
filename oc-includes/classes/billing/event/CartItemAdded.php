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

/**
 * A variant was added to the saved cart. Old action: `cart_item_add_after`.
 */
final class CartItemAdded extends CartEvent implements Bridged
{
    /**
     * @param array<string,mixed> $cart
     */
    public function __construct(array $cart, private Line $line)
    {
        parent::__construct($cart);
    }

    public static function legacyNames(): array
    {
        return ['cart_item_add_after'];
    }

    public function line(): Line
    {
        return $this->line;
    }

    public function variantId(): int
    {
        return (int) ($this->line->meta()['variant'] ?? 0);
    }

    public function qty(): int
    {
        return (int) ($this->line->meta()['qty'] ?? 0);
    }

    public function itemId(): int
    {
        return (int) ($this->line->meta()['item_id'] ?? 0);
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->cart, $this->line->toArray());
    }
}
