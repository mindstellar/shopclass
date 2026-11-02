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

namespace mindstellar\billing;

/**
 * A priced cart, as CartPricer built it from the database. Every amount is micros in one
 * currency. Item lines carry {line, variant, qty, item_id, title, options, sku, unit, amount,
 * available, stock_left, weight, shipping_class, listing: {id, title, item, photo}}; `item` is
 * the listing row a link is built from and `photo` its photo row or null. Shipping, tax,
 * discount and fee lines are {id, label, amount, currency, meta}; a discount's amount is
 * negative, and a tax line also carries `included` (true when the tax is already in the prices).
 * A problem is {line, code, pointer, message}: core's own name the request's line by position,
 * with pointer and message null; a `cart_problems` one has line null, a pointer and a message.
 */
final class Quote
{
    /**
     * @param array<int,array<string,mixed>>     $lines           item lines
     * @param array<int,array<string,mixed>>     $shippingMethods {id, label, eta, amount}
     * @param array<string,mixed>|null           $shipping        the chosen method, or null
     * @param array<string,array<int,array<string,mixed>>> $extra shipping, tax, discount and fee lines by kind
     * @param array<string,int>                  $totals          subtotal, shipping, discount, tax, tax_included, fee, total
     * @param array{amount:int,remaining:int}|null $freeOver      free delivery from this subtotal
     * @param array<int,array{line:?int,code:string,pointer:?string,message:?string}> $problems
     * @param array<string,mixed>                $cart            what the cart filters were given
     */
    public function __construct(
        private string $currency,
        private array $lines,
        private array $shippingMethods,
        private ?array $shipping,
        private array $extra,
        private array $totals,
        private ?array $freeOver,
        private array $problems,
        private array $cart
    ) {
    }

    /** ISO 4217 code of every amount here. */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * The item lines, available or not, in request order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * The shipping methods on offer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function shippingMethods(): array
    {
        return $this->shippingMethods;
    }

    /**
     * Whether anything in the cart is sent. False when every line is a download, so the
     * checkout asks for no address, delivery or cash on delivery.
     */
    public function needsShipping(): bool
    {
        return ($this->cart['needs_shipping'] ?? true) !== false;
    }

    /**
     * The chosen shipping method {id, label, eta, amount}, or null.
     *
     * @return array<string,mixed>|null
     */
    public function shipping(): ?array
    {
        return $this->shipping;
    }

    /**
     * Lines of one kind: shipping, tax, discount or fee.
     *
     * @return array<int,array<string,mixed>>
     */
    public function extraLines(string $kind): array
    {
        return $this->extra[$kind] ?? array();
    }

    /**
     * subtotal, shipping, discount (what comes off, not negative), tax (added on top),
     * tax_included (already in the prices, not added), fee and total.
     *
     * @return array<string,int>
     */
    public function totals(): array
    {
        return $this->totals;
    }

    /** What the gateway charges. */
    public function total(): int
    {
        return $this->totals['total'];
    }

    /**
     * Free delivery from this subtotal and how much is left to reach it, or null.
     *
     * @return array{amount:int,remaining:int}|null
     */
    public function freeOver(): ?array
    {
        return $this->freeOver;
    }

    /**
     * Core's line problems by position, then those the `cart_problems` filter added.
     *
     * @return array<int,array{line:?int,code:string,pointer:?string,message:?string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The cart array the filters saw.
     *
     * @return array<string,mixed>
     */
    public function cart(): array
    {
        return $this->cart;
    }
}
