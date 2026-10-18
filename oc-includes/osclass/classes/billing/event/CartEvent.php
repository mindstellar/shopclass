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

use mindstellar\event\Event;

/**
 * Base of every cart event: the cart as it stands when the event fires.
 */
abstract class CartEvent extends Event
{
    /**
     * @param array<string,mixed> $cart
     */
    public function __construct(protected array $cart)
    {
    }

    public function cart(): CartView
    {
        return new CartView($this->cart);
    }

    /**
     * The cart as the old hooks pass it.
     *
     * @return array<string,mixed>
     */
    public function cartArray(): array
    {
        return $this->cart;
    }

    /**
     * Check a line a listener adds and return it as an array. A discount always takes off; any
     * other kind below zero is refused, as is a line in another currency or without a label.
     *
     * @return array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}
     * @throws \InvalidArgumentException
     */
    protected function accept(Line $line, string $kind): array
    {
        $currency = (string) ($this->cart['currency'] ?? '');
        if (trim($line->label()) === '') {
            throw new \InvalidArgumentException('A cart line needs a label.');
        }
        if ($line->currency() !== $currency) {
            throw new \InvalidArgumentException(sprintf('The line "%s" is in %s; the cart is in %s.', $line->id(), $line->currency(), $currency));
        }
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $line->id()) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a line id.', $line->id()));
        }
        if ($line->amount() < 0 && $kind !== 'discount') {
            throw new \InvalidArgumentException(sprintf('The %s line "%s" is below zero.', $kind, $line->id()));
        }
        $row = $line->toArray();
        if ($kind === 'discount') {
            $row['amount'] = -abs($row['amount']);
        }

        return $row;
    }
}
