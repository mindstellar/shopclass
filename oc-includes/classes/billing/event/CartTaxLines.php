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
 * The tax lines of a cart. Old filter: `cart_tax_lines`.
 */
final class CartTaxLines extends CartLineEvent
{
    public static function legacyNames(): array
    {
        return ['cart_tax_lines'];
    }

    protected function kind(): string
    {
        return 'tax';
    }
}
