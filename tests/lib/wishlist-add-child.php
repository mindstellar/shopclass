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
 * One of two wishlist adds by the same user at once, for tests/api-wishlist.php. Prints "added".
 *
 * Usage:  php tests/lib/wishlist-add-child.php <database> <user id> <listing id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $user, $listing] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();

(new \mindstellar\listing\WishlistService())->add((int) $user, (int) $listing);
echo "added\n";
