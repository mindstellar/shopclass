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
 * Listing upgrades under MariaDB snapshot isolation: a grant that waits on the upgrade row another
 * grant holds extends it instead of failing, and the premium end job is queued after the purchase
 * commits, so two purchases ending in the same second do not clash.
 * Usage: php tests/billing-features-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

$dbName = 'osc_billing_features_race';
$admin  = api_admin_boot($dbName);
$p      = DB_TABLE_PREFIX;
$child  = __DIR__ . '/lib/billing-features-child.php';

$locale = seed_locale($admin);
$cat    = seed_category($admin, 'Goods', null, $locale);
$item   = seed_item($admin, $cat);

harness_section('a grant waits on the upgrade row another grant holds');
$admin->query("INSERT INTO {$p}t_item_upgrade (fk_i_item_id, s_upgrade, dt_expiration, dt_date)
               VALUES ($item, 'item.highlight', DATE_ADD(NOW(), INTERVAL 10 DAY), NOW())");
[$waiting, $out] = race(
    $admin,
    "UPDATE {$p}t_item_upgrade SET dt_expiration = DATE_ADD(NOW(), INTERVAL 20 DAY) WHERE fk_i_item_id = $item",
    $dbName,
    [[$child, ['grant', $item]]],
    "%{$p}t_item_upgrade%"
);
pin('the grant waited on the held row', 1, $waiting);
pin('and succeeded', "ok\n", $out[0]);
$days = (int) $admin->query("SELECT TIMESTAMPDIFF(HOUR, NOW(), dt_expiration) FROM {$p}t_item_upgrade WHERE fk_i_item_id = $item")->fetch_row()[0];
pin('and extended the row the other grant had written (20 + 5 days)', 25, (int) round($days / 24));

harness_section('two first grants for one listing wait in line');
$item2 = seed_item($admin, $cat);
[$waiting, $out] = race(
    $admin,
    "SELECT pk_i_id FROM {$p}t_item WHERE pk_i_id = $item2 FOR UPDATE",
    $dbName,
    [[$child, ['grant', $item2]], [$child, ['grant', $item2]]],
    "%FROM {$p}t_item WHERE pk_i_id %FOR UPDATE%"
);
pin('both grants waited on the listing row', 2, $waiting);
pin('and both succeeded', ["ok\n", "ok\n"], array_values($out));
$days = (int) $admin->query("SELECT TIMESTAMPDIFF(HOUR, NOW(), dt_expiration) FROM {$p}t_item_upgrade WHERE fk_i_item_id = $item2")->fetch_row()[0];
pin('one row, extended by both (5 + 5 days)', 10, (int) round($days / 24));

harness_section('two premium purchases ending in the same second');
$end  = date('Y-m-d H:i:s', strtotime('+3 days'));
$time = strtotime($end);
[$waiting, $out] = race(
    $admin,
    "INSERT INTO {$p}t_job_queue (s_type, s_unique, s_payload, dt_next_run, dt_created) VALUES ('billing.premium_end', '$time', '{}', NOW(), NOW())",
    $dbName,
    [[$child, ['schedule', $item, $end]]],
    "%{$p}t_job_queue%"
);
pin('the purchase waited on the held job row', 1, $waiting);
pin('and succeeded with no error', "ok\n", $out[0]);
pin('one job for that second', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_unique = '$time'")->fetch_row()[0]);

exit(harness_result());
