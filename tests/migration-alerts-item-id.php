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
 * Migration 0089 adds t_alerts.fk_i_item_id and idx_item, fills the listing id of every product
 * alert over more than one batch, leaves saved searches empty, and can run again.
 *
 * Usage:  php tests/migration-alerts-item-id.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\search\AlertEnvelope;

$admin = scratchdb_session('osc_migration_alerts_item_id');
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;
$mig   = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0089_alerts_item_id.php';

$admin->query("ALTER TABLE {$p}t_alerts DROP INDEX idx_item");
$admin->query("ALTER TABLE {$p}t_alerts DROP COLUMN fk_i_item_id");
$admin->query("DELETE FROM {$p}t_alerts");

$values = [];
for ($i = 1; $i <= 1200; $i++) {
    $json     = $admin->real_escape_string(AlertEnvelope::product($i, null, $i % 2 ? 'stock' : 'price', $i % 2 ? null : 5_000_000));
    $values[] = "('a$i@example.test', '$json', 's', 1, 'CUSTOM', NOW())";
}
$values[] = "('saved@example.test', '{\"v\":2,\"params\":{\"sPattern\":\"lamp\"}}', 's', 1, 'DAILY', NOW())";
$values[] = "('broken@example.test', '{\"v\":2,\"params\":{\"product\":{\"listing\":\"x\"}}}', 's', 1, 'CUSTOM', NOW())";
$admin->query("INSERT INTO {$p}t_alerts (s_email, s_search, s_secret, b_active, e_type, dt_date) VALUES " . implode(', ', $values));

$mig->up($conn);
$filled = static fn (): array => $admin->query("SELECT s_email, fk_i_item_id FROM {$p}t_alerts WHERE s_email IN ('a1@example.test', 'a2@example.test', 'a1200@example.test', 'saved@example.test', 'broken@example.test') ORDER BY pk_i_id")->fetch_all(MYSQLI_NUM);
$expect = [['a1@example.test', '1'], ['a2@example.test', '2'], ['a1200@example.test', '1200'], ['saved@example.test', null], ['broken@example.test', null]];
pin('every product alert gets its listing id, past the first batch; others stay empty', $expect, $filled());
pin('all 1200 product alerts are filled', 1200, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_alerts WHERE fk_i_item_id IS NOT NULL")->fetch_row()[0]);
pin('the index is added', 1, $admin->query("SHOW INDEX FROM {$p}t_alerts WHERE Key_name = 'idx_item'")->num_rows);

$admin->query("UPDATE {$p}t_alerts SET fk_i_item_id = NULL WHERE s_email = 'a2@example.test'");
$mig->up($conn);
pin('a second run fills only what is missing and changes nothing else', $expect, $filled());
pin('and adds no second column or index', [1, 1], [
    $admin->query("SHOW COLUMNS FROM {$p}t_alerts LIKE 'fk_i_item_id'")->num_rows,
    $admin->query("SHOW INDEX FROM {$p}t_alerts WHERE Key_name = 'idx_item'")->num_rows,
]);

exit(harness_result());
