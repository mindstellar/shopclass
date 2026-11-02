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
 * Work on the order started: it was paid, or a cash on delivery order, and is being packed or
 * shipped in parts. Fired by the `billing_order_processing` hook name.
 */
final class OrderProcessing extends OrderEvent
{
    public static function legacyNames(): array
    {
        return ['billing_order_processing'];
    }
}
