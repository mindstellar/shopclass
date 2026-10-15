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

$recent  = seed_user($admin, 'recent', 'recent@example.test');
$right   = seed_user($admin, 'right', 'right@example.test');
$nulled  = seed_user($admin, 'nulled', 'nulled@example.test');
$old     = seed_user($admin, 'old', 'old@example.test');
$ancient = seed_user($admin, 'ancient', 'ancient@example.test');
$low     = seed_user($admin, 'low', 'low@example.test');
$high    = seed_user($admin, 'high', 'high@example.test');
$users   = array($recent, $right, $nulled, $old, $ancient, $low, $high);

$listing = static function (?int $user, string $expires, array $set = array()) use ($admin, $cat, $locale, $country, $p): int {
    $id = seed_item($admin, $cat, $user, 'A listing', 10.0, 1, 1, $locale, $country);
    $set += array('dt_pub_date' => date('Y-m-d H:i:s', strtotime('-60 days')), 'dt_mod_date' => date('Y-m-d H:i:s', strtotime('-60 days')));
    $set['dt_expiration'] = $expires;
    foreach ($set as $column => $value) {
        $admin->query("UPDATE {$p}t_item SET $column = '" . $admin->real_escape_string((string) $value) . "' WHERE pk_i_id = $id");
    }

    return $id;
};
$ago   = static fn (string $interval): string => date('Y-m-d H:i:s', strtotime('-' . $interval));
$later = $ago('-30 days');

// recent: 2 live; expired an hour ago; expired but premium; spam; disabled; inactive.
$listing($recent, $later);
$listing($recent, $later);
$listing($recent, $ago('1 hour'));
$listing($recent, $ago('2 hours'), array('b_premium' => 1));
$listing($recent, $later, array('b_spam' => 1));
$listing($recent, $later, array('b_enabled' => 0));
$listing($recent, $later, array('b_active' => 0));
// right: in the window, count already right. nulled: in the window, count NULL.
$listing($right, $later);
$listing($right, $ago('1 hour'));
$listing($nulled, $ago('1 hour'));
// A guest listing in the window.
$listing(null, $ago('1 hour'));
// old: expired ten days ago. ancient: forty days ago, past the 30-day limit.
$listing($old, $later);
$listing($old, $ago('10 days'));
$listing($ancient, $ago('40 days'));
// low and high expire exactly on the bounds of a window three to two days ago.
$from = $ago('3 days');
$to   = $ago('2 days');
$listing($low, $from);
$listing($high, $to);

$admin->query("UPDATE {$p}t_user SET i_items = 9 WHERE pk_i_id IN (" . implode(',', $users) . ')');
$admin->query("UPDATE {$p}t_user SET i_items = 1 WHERE pk_i_id = $right");
$admin->query("UPDATE {$p}t_user SET i_items = NULL WHERE pk_i_id = $nulled");
$items = static function () use ($admin, $p, $users): array {
    $out = array();
    foreach ($admin->query("SELECT i_items FROM {$p}t_user WHERE pk_i_id IN (" . implode(',', $users) . ') ORDER BY pk_i_id') as $row) {
        $out[] = $row['i_items'] === null ? null : (int) $row['i_items'];
    }

    return $out;
};

harness_section('the daily recount');
$changed = UserStore::recountExpired($ago('1 day'), date('Y-m-d H:i:s'), 1);
pin('sellers with a listing that expired in the window are recounted, one per batch', array(3, 1, 0, 9, 9, 9, 9), $items());
pin('it counts only the sellers that changed', 2, $changed);
pin('a second run changes nothing', 0, UserStore::recountExpired($ago('1 day'), date('Y-m-d H:i:s')));
pin('an empty window changes nothing', 0, UserStore::recountExpired($ago('5 days'), $ago('4 days')));

harness_section('window bounds');
pin('a listing expiring exactly on either bound is in the window', 2, UserStore::recountExpired($from, $to));
pin('so both sellers go to 0', array(3, 1, 0, 9, 9, 0, 0), $items());

harness_section('a long break');
UserStore::recountExpired('1000-01-01 00:00:00', date('Y-m-d H:i:s'));
pin('the window goes back 30 days at most', array(3, 1, 0, 1, 9, 0, 0), $items());

harness_section('a listing posted or edited already expired');
$imported = seed_user($admin, 'imported', 'imported@example.test');
$edited   = seed_user($admin, 'edited', 'edited@example.test');
$listing($imported, $later);
$listing($imported, $ago('10 days'), array('dt_pub_date' => $ago('1 hour')));
$listing($edited, $ago('5 days'), array('dt_mod_date' => $ago('1 hour')));
$admin->query("UPDATE {$p}t_user SET i_items = 2 WHERE pk_i_id IN ($imported, $edited)");
UserStore::recountExpired($ago('1 day'), date('Y-m-d H:i:s'));
pin('posted already expired, or edited to a past expiry, is recounted too', array(1, 0), array(
    (int) $admin->query("SELECT i_items FROM {$p}t_user WHERE pk_i_id = $imported")->fetch_row()[0],
    (int) $admin->query("SELECT i_items FROM {$p}t_user WHERE pk_i_id = $edited")->fetch_row()[0],
));
pin('the other sellers are untouched', array(3, 1, 0, 1, 9, 0, 0), $items());

exit(harness_result());
