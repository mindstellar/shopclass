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
 * A seller's live listing count (t_user.i_items) after listings expire: the daily recount of
 * UserStore::recountExpired().
 *
 * Usage:  php tests/user-recount-expired.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\user\UserStore;

$admin = scratchdb_session('osc_user_recount');
$p     = DB_TABLE_PREFIX;

$locale  = seed_locale($admin);
$country = seed_country($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Motors', null, $locale);

$recent = seed_user($admin, 'recent', 'recent@example.test');
$old    = seed_user($admin, 'old', 'old@example.test');
$none   = seed_user($admin, 'none', 'none@example.test');

$listing = static function (int $user, string $expires, array $set = array()) use ($admin, $cat, $locale, $country, $p): int {
    $id = seed_item($admin, $cat, $user, 'A listing', 10.0, 1, 1, $locale, $country);
    $set['dt_expiration'] = $expires;
    foreach ($set as $column => $value) {
        $admin->query("UPDATE {$p}t_item SET $column = '" . $admin->real_escape_string((string) $value) . "' WHERE pk_i_id = $id");
    }

    return $id;
};
$ago = static fn (string $interval): string => date('Y-m-d H:i:s', strtotime('-' . $interval));

// recent: 2 live, 1 expired an hour ago, 1 expired but premium, 1 spam, 1 disabled.
$listing($recent, $ago('-30 days'));
$listing($recent, $ago('-30 days'));
$listing($recent, $ago('1 hour'));
$listing($recent, $ago('2 hours'), array('b_premium' => 1));
$listing($recent, $ago('-30 days'), array('b_spam' => 1));
$listing($recent, $ago('-30 days'), array('b_enabled' => 0));
// old: 1 live, 1 expired ten days ago.
$listing($old, $ago('-30 days'));
$listing($old, $ago('10 days'));

$admin->query("UPDATE {$p}t_user SET i_items = 9 WHERE pk_i_id IN ($recent, $old, $none)");
$items = static function () use ($admin, $p, $recent, $old, $none): array {
    $out = array();
    foreach ($admin->query("SELECT pk_i_id, i_items FROM {$p}t_user WHERE pk_i_id IN ($recent, $old, $none) ORDER BY pk_i_id") as $row) {
        $out[] = (int) $row['i_items'];
    }

    return $out;
};

harness_section('the daily recount');
$changed = UserStore::recountExpired($ago('1 day'), date('Y-m-d H:i:s'));
pin('only the seller with a listing that expired in the window is recounted', array(3, 9, 9), $items());
pin('it says one seller changed', 1, $changed);
pin('a second run changes nothing', 0, UserStore::recountExpired($ago('1 day'), date('Y-m-d H:i:s')));
pin('an empty window changes nothing', 0, UserStore::recountExpired($ago('5 days'), $ago('4 days')));

exit(harness_result());
