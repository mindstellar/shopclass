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
 * A variant's selling price changed (its own, else the listing's). Old name: variant_price_changed.
 */
final class VariantPriceChanged extends VariantChanged implements Bridged
{
    public static function legacyNames(): array
    {
        return ['variant_price_changed'];
    }
}
