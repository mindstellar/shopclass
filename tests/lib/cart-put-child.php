<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * One of two saved-cart writes by the same user at once, for tests/api-cart.php. Prints "saved"
 * and one "fired" per cart_item_add_after.
 *
 * Usage:  php tests/lib/cart-put-child.php <database> <user id> <variant id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $user, $variant] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

osc_add_hook('cart_item_add_after', static function (): void {
    echo "fired\n";
});
\mindstellar\billing\CartStore::replace((int) $user, [['variant' => (int) $variant, 'qty' => 1]]);
echo "saved\n";
