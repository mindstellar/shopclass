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

namespace mindstellar\listing\event;

use mindstellar\event\Bridged;

/**
 * A variant's stock changed. Old name: variant_stock_changed.
 */
final class VariantStockChanged extends VariantChanged implements Bridged
{
    public static function legacyNames(): array
    {
        return ['variant_stock_changed'];
    }

    /**
     * It was sold out and has stock again (or stock is no longer counted).
     */
    public function backInStock(): bool
    {
        return $this->from() === 0 && ($this->to() === null || $this->to() > 0);
    }
}
