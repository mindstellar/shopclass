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
 * One of two buyers taking a unit of the same variant at once, for tests/item-variant.php.
 * Prints "won" when VariantStore::adjustStock() took the unit, "lost" when it refused.
 *
 * Usage:  php tests/lib/variant-stock-child.php <database> <variant id>
 */

require_once __DIR__ . '/scratchdb.php';

[, $database, $id] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);

echo \mindstellar\listing\VariantStore::adjustStock((int) $id, -1) ? "won\n" : "lost\n";
