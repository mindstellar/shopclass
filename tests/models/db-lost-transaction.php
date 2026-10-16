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
 * After a deadlock or snapshot conflict inside a transaction, every later query fails until the
 * caller rolls back, even when the failed statement's error was swallowed.
 *
 * Usage:  php tests/models/db-lost-transaction.php        (standalone, own scratch database)
 *         php tests/run-models.php db-lost-transaction    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

use mindstellar\database\ConnectionManager;
use mindstellar\database\Db;
use mindstellar\database\DbException;

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_db_lost_transaction');
$p     = DB_TABLE_PREFIX;
$admin->query("CREATE TABLE IF NOT EXISTS {$p}t_lost_probe (id INT PRIMARY KEY) ENGINE=InnoDB");

$swallow = static function (): void {
    try {
        Db::execute("SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1020, MESSAGE_TEXT = 'test snapshot conflict'");
    } catch (DbException $e) {
        // swallowed, as the DAO write helpers do
    }
};
$code = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (DbException $e) {
        return (int) $e->getCode();
    }

    return null;
};
$rows   = static fn (): int => (int) $admin->query("SELECT COUNT(*) AS n FROM {$p}t_lost_probe")->fetch_assoc()['n'];
$legacy = new DBCommandClass(ConnectionManager::getInstance()->getHandle());

harness_section('a swallowed conflict blocks every later query');
Db::beginTransaction();
Db::execute("INSERT INTO {$p}t_lost_probe (id) VALUES (1)");
@$swallow();
pin('a query through Db fails with the conflict', 1020, $code(static fn () => Db::execute("INSERT INTO {$p}t_lost_probe (id) VALUES (2)")));
pin('a legacy DAO query fails too', 1020, $code(static fn () => $legacy->query("INSERT INTO {$p}t_lost_probe (id) VALUES (3)")));
pin('commit is refused', false, Db::commit());
pin('nothing from the transaction is kept', 0, $rows());
pin('the next query works', 1, (int) Db::scalar('SELECT 1'));

harness_section('transaction() throws when its body swallowed a conflict');
pin('it throws the conflict', 1020, $code(static function () use ($swallow, $p): void {
    Db::transaction(static function () use ($swallow, $p): void {
        Db::execute("INSERT INTO {$p}t_lost_probe (id) VALUES (4)");
        @$swallow();
    });
}));
pin('the row is not kept', 0, $rows());
pin('no transaction is left open', false, Db::inTransaction());

harness_section('rollBack clears it, and only the outermost one');
Db::beginTransaction();
Db::beginTransaction();
@$swallow();
@Db::rollBack();
pin('an inner rollBack leaves queries blocked', 1020, $code(static fn () => Db::scalar('SELECT 1')));
Db::rollBack();
pin('the outer rollBack frees them', 1, (int) Db::scalar('SELECT 1'));

harness_section('outside a transaction a conflict blocks nothing');
@$swallow();
pin('the next query works', 1, (int) Db::scalar('SELECT 1'));

$admin->query("DROP TABLE IF EXISTS {$p}t_lost_probe");

exit(harness_result());
