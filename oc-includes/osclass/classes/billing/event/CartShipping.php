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
 * Whether the cart is sent, and the delivery methods on offer. Old filters: `cart_needs_shipping`
 * and `cart_shipping_methods`. Methods are not asked for when the cart needs no shipping.
 */
final class CartShipping extends CartEvent implements Bridged
{
    /** @var mixed what an old `cart_needs_shipping` filter returned; only false means no shipping */
    private $needs = true;

    /** @var mixed the methods as arrays {id, label, eta, amount} */
    private $methods = [];

    public static function legacyNames(): array
    {
        return ['cart_needs_shipping', 'cart_shipping_methods'];
    }

    public function needsShipping(): bool
    {
        return $this->needs !== false;
    }

    public function setNeedsShipping(bool $needs): void
    {
        $this->needs = $needs;
    }

    /**
     * @throws \InvalidArgumentException when the id, label or amount is not one core accepts
     */
    public function addMethod(string $id, string $label, int $amount, ?string $eta = null): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a shipping method id.', $id));
        }
        if (trim($label) === '' || $amount < 0) {
            throw new \InvalidArgumentException(sprintf('The shipping method "%s" needs a label and an amount of zero or more.', $id));
        }
        $this->methods   = is_array($this->methods) ? $this->methods : [];
        $this->methods[] = ['id' => $id, 'label' => $label, 'eta' => $eta, 'amount' => $amount];
    }

    /**
     * @return array<int,array{id:string,label:string,eta:?string,amount:int}>
     */
    public function methods(): array
    {
        return is_array($this->methods) ? array_values(array_filter($this->methods, 'is_array')) : [];
    }

    /**
     * The methods as the old filter sees and returns them.
     *
     * @return mixed
     */
    public function rawMethods()
    {
        return $this->methods;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        if ($name === 'cart_needs_shipping') {
            $this->needs = $fn($this->needs, $this->cart);

            return;
        }
        if ($this->needsShipping()) {
            $this->methods = $fn($this->methods, $this->cart + ['needs_shipping' => true]);
        }
    }
}
