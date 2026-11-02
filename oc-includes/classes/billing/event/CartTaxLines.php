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

use mindstellar\billing\LineTax;

/**
 * The tax lines of a cart. Old filter: `cart_tax_lines`. A listener may also break the tax down per
 * item and shipping line with setLineTax(); core checks that breakdown against the tax lines.
 * The cart's `fields` holds the values the buyer sent, such as a tax number; check them before use.
 */
final class CartTaxLines extends CartLineEvent
{
    /** @var array{item:array<int,LineTax>,shipping:array<string,LineTax>} */
    private array $lineTax = array('item' => array(), 'shipping' => array());

    /**
     * Set the tax of one item line (by its cart position `line`) or shipping line (by its id). A later
     * call for the same line replaces the earlier one.
     *
     * @throws \InvalidArgumentException on another kind or a line the cart does not hold
     */
    public function setLineTax(string $kind, int|string $ref, LineTax $tax): void
    {
        if ($kind === 'item') {
            $positions = array_map(static fn ($l): int => is_array($l) ? (int) ($l['line'] ?? -1) : -1, (array) ($this->cart['lines'] ?? array()));
            if (!is_int($ref) || !in_array($ref, $positions, true)) {
                throw new \InvalidArgumentException(sprintf('The cart holds no item line %s.', (string) $ref));
            }
            $this->lineTax['item'][$ref] = $tax;

            return;
        }
        if ($kind === 'shipping') {
            $ids = array_map(static fn ($l): string => is_array($l) ? (string) ($l['id'] ?? '') : '', (array) ($this->cart['shipping_lines'] ?? array()));
            if (!is_string($ref) || $ref === '' || !in_array($ref, $ids, true)) {
                throw new \InvalidArgumentException(sprintf('The cart holds no shipping line "%s".', (string) $ref));
            }
            $this->lineTax['shipping'][$ref] = $tax;

            return;
        }

        throw new \InvalidArgumentException('Line tax is set on an item or a shipping line.');
    }

    /**
     * The line tax set so far: item tax by cart position, shipping tax by line id.
     *
     * @return array{item:array<int,LineTax>,shipping:array<string,LineTax>}
     */
    public function lineTax(): array
    {
        return $this->lineTax;
    }

    public static function legacyNames(): array
    {
        return ['cart_tax_lines'];
    }

    protected function kind(): string
    {
        return 'tax';
    }
}
