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
 * The admin asks which payment methods take no real money yet, to show one test-mode notice.
 * A listener adds its name and settings link. Old callbacks on `admin_payment_test_modes`
 * get (array name => settings url) and return it.
 */
final class PaymentTestModes extends PaymentNameList
{
    public static function legacyNames(): array
    {
        return ['admin_payment_test_modes'];
    }
}
