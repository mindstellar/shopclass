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
 * The race helper in tests/lib/race.php: a child that reads before its UPDATE and then waits on a
 * held row fails with error 1020 on MariaDB 11.6+ (snapshot isolation ON) and succeeds elsewhere;
 * a child that updates first succeeds on every server.
 * Usage: php tests/race-helper.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/race.php';

$dbName = 'osc_race_helper';
$admin  = scratchdb_bootstrap($dbName);
$admin->query("DROP DATABASE IF EXISTS `$dbName`");
$admin->query("CREATE DATABASE `$dbName`");
$admin->select_db($dbName);
register_shutdown_function(static function () use ($admin, $dbName) {
    $admin->query("DROP DATABASE IF EXISTS `$dbName`");
});
$admin->query('CREATE TABLE race_probe (id INT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB');
$admin->query('INSERT INTO race_probe VALUES (1, 0), (2, 0)');

$child = __DIR__ . '/lib/race-update-child.php';
$strict = race_snapshot_isolation($admin);
$n = static fn (int $id): string => (string) $admin->query("SELECT n FROM race_probe WHERE id = $id")->fetch_row()[0];

harness_section('read before the lock');
[$waiting, $out] = race(
    $admin,
    'UPDATE race_probe SET n = n + 10 WHERE id = 1',
    $dbName,
    [[$child, [1, 'read-first']]],
    'UPDATE race_probe%'
);
pin('the child waited on the held row', 1, $waiting);
if ($strict) {
    pin('snapshot isolation ON: the child fails with 1020', "threw 1020\n", $out[0]);
    pin('and its update is not applied', '10', $n(1));
} else {
    pin('snapshot isolation off: the child succeeds', "ok\n", $out[0]);
    pin('and both updates are applied', '11', $n(1));
}

harness_section('lock before the read');
[$waiting, $out] = race(
    $admin,
    'UPDATE race_probe SET n = n + 10 WHERE id = 2',
    $dbName,
    [[$child, [2, 'lock-first']]],
    'UPDATE race_probe%'
);
pin('the child waited on the held row', 1, $waiting);
pin('the child succeeds', "ok\n", $out[0]);
pin('and both updates are applied', '11', $n(2));

exit(harness_result());
