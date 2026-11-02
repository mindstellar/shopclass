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
 * The free-delivery meter, asked once the delivery method is chosen. Old filter: `cart_free_over`.
 * Core works out what remains from the subtotal.
 */
final class CartFreeOver extends CartEvent implements Bridged
{
    /** @var mixed {amount, remaining} or null for none */
    private $freeOver;

    /**
     * @param array<string,mixed>                      $cart
     * @param array{amount:int,remaining:int}|null     $freeOver core's own meter
     */
    public function __construct(array $cart, ?array $freeOver)
    {
        parent::__construct($cart);
        $this->freeOver = $freeOver;
    }

    public static function legacyNames(): array
    {
        return ['cart_free_over'];
    }

    /**
     * Free delivery from this amount in micros, or null for no meter.
     */
    public function setFreeOver(?int $amount): void
    {
        $this->freeOver = $amount === null ? null : ['amount' => $amount, 'remaining' => max(0, $amount - $this->cart()->subtotal())];
    }

    /**
     * The meter as the old filter sees and returns it.
     *
     * @return mixed
     */
    public function freeOver()
    {
        return $this->freeOver;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->freeOver = $fn($this->freeOver, $this->cart);
    }
}
