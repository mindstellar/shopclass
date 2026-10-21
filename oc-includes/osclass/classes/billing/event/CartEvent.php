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
     * Check a line a listener adds and return it as core keeps it. See Line::fault() for the rules.
     *
     * @return array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}
     * @throws \InvalidArgumentException
     */
    protected function accept(Line $line, string $kind): array
    {
        $fault = Line::fault($line->id(), $line->label(), $line->amount(), $line->currency(), $kind, (string) ($this->cart['currency'] ?? ''));
        if ($fault !== null) {
            throw new \InvalidArgumentException($fault);
        }

        return Line::row($line->id(), $line->label(), $line->amount(), $line->currency(), $kind, $line->meta());
    }

    /**
     * @param mixed $rows
     *
     * @throws \InvalidArgumentException when the event already holds Line::MAX_PER_EVENT lines
     */
    protected static function roomFor($rows): void
    {
        if (is_array($rows) && count($rows) >= Line::MAX_PER_EVENT) {
            throw new \InvalidArgumentException(sprintf('A cart event holds at most %d lines.', Line::MAX_PER_EVENT));
        }
    }
}
