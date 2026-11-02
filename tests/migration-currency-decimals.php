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
 * Migration 0091 adds t_currency.i_decimals, sets each currency to its ISO 4217 digits, and can
 * run again, keeping a value an admin set. A fresh struct.sql install already has the column.
 *
 * Usage:  php tests/migration-currency-decimals.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_migration_currency_decimals');
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;
$mig   = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0091_currency_decimals.php';

$cols = static fn (): int => $admin->query("SHOW COLUMNS FROM {$p}t_currency LIKE 'i_decimals'")->num_rows;
pin('a fresh install has the column', 1, $cols());

$admin->query("ALTER TABLE {$p}t_currency DROP COLUMN i_decimals");
$admin->query("DELETE FROM {$p}t_currency");
$admin->query("INSERT INTO {$p}t_currency (pk_c_code, s_name, s_description) VALUES ('JPY', 'Yen', '¥'), ('KWD', 'Dinar', 'KD'), ('USD', 'Dollar', '$')");
pin('the column is gone before the run', 0, $cols());

$mig->up($conn);
$got = static fn (): array => $admin->query("SELECT pk_c_code, i_decimals FROM {$p}t_currency ORDER BY pk_c_code")->fetch_all(MYSQLI_NUM);
pin('JPY 0, KWD 3, USD 2', [['JPY', '0'], ['KWD', '3'], ['USD', '2']], $got());

$admin->query("UPDATE {$p}t_currency SET i_decimals = 4 WHERE pk_c_code = 'USD'");
$admin->query("UPDATE {$p}t_currency SET i_decimals = 1 WHERE pk_c_code = 'KWD'");
$admin->query("UPDATE {$p}t_currency SET i_decimals = 2 WHERE pk_c_code = 'JPY'");
$mig->up($conn);
pin('a second run keeps values an admin set, fixes a row left at the default, and adds no column', [[['JPY', '0'], ['KWD', '1'], ['USD', '4']], 1], [$got(), $cols()]);

exit(harness_result());
