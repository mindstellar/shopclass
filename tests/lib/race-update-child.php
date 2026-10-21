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
 * Child for tests/race-helper.php: adds 1 to a row of race_probe in a transaction. "read-first"
 * does a plain SELECT before the UPDATE, "lock-first" updates first. Prints "ok", or "threw <code>".
 *
 * Usage:  php tests/lib/race-update-child.php <database> <row id> [read-first|lock-first]
 */

require_once __DIR__ . '/scratchdb.php';

[, $database, $id] = $argv;
$mode = $argv[3] ?? 'read-first';
scratchdb_bootstrap($database);
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $database, (int) ini_get('mysqli.default_port'));

$db->begin_transaction();
if ($mode === 'read-first') {
    $db->query('SELECT n FROM race_probe WHERE id = ' . (int) $id);
}
if (!$db->query('UPDATE race_probe SET n = n + 1 WHERE id = ' . (int) $id)) {
    echo 'threw ' . $db->errno . "\n";
    exit(0);
}
$db->commit();
echo "ok\n";
