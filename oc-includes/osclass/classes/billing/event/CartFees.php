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
 * Fee lines on top of the other lines, such as a payment fee. Old filter: `cart_totals`.
 */
final class CartFees extends CartEvent implements Bridged
{
    /** @var mixed the totals so far, with a `fees` list the old filter adds to */
    private $totals;

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $totals the sums the other lines make
     */
    public function __construct(array $cart, array $totals)
    {
        parent::__construct($cart);
        $totals['fees'] = [];
        $this->totals   = $totals;
    }

    public static function legacyNames(): array
    {
        return ['cart_totals'];
    }

    /**
     * The sums the other lines make; fees are not counted in them.
     *
     * @return array<string,mixed>
     */
    public function totals(): array
    {
        return is_array($this->totals) ? array_diff_key($this->totals, ['fees' => true]) : [];
    }

    /**
     * @throws \InvalidArgumentException when the fee is below zero or in another currency
     */
    public function addFee(Line $line): void
    {
        $this->totals   = is_array($this->totals) ? $this->totals : [];
        $fees           = is_array($this->totals['fees'] ?? null) ? $this->totals['fees'] : [];
        self::roomFor($fees);
        $fees[]         = $this->accept($line, 'fee');
        $this->totals['fees'] = $fees;
    }

    /**
     * The fee lines as arrays, for core to check.
     *
     * @return mixed
     */
    public function rawFees()
    {
        return is_array($this->totals) ? ($this->totals['fees'] ?? []) : [];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->totals = $fn($this->totals, $this->cart);
    }
}
