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
 * ListingCounters: views and report counters in t_item_stats, and the report log.
 *
 * Usage:  php tests/models/listing-counters.php
 *         php tests/run-models.php listing-counters
 */

use mindstellar\listing\ListingCounters;

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_listing_counters');
$stats = DB_TABLE_PREFIX . 't_item_stats';
$log   = DB_TABLE_PREFIX . 't_item_report_log';

$catId = seed_category($admin, 'Motors');
$itemA = seed_item($admin, $catId, null, 'Item A');
$itemB = seed_item($admin, $catId, null, 'Item B');

$row = static function (int $id) use ($admin, $stats): array {
    return array_map('intval', $admin->query(
        "SELECT i_num_views, i_num_spam, i_num_repeated, i_num_bad_classified, i_num_offensive, i_num_expired FROM $stats WHERE fk_i_item_id = $id"
    )->fetch_assoc() ?: array());
};
$logCount = static function (int $id) use ($admin, $log): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $log WHERE fk_i_item_id = $id")->fetch_assoc()['c'];
};
$seedReports = static function (int $id) use ($admin, $stats, $log): void {
    $admin->query("UPDATE $stats SET i_num_spam = 2, i_num_repeated = 3, i_num_bad_classified = 4, i_num_offensive = 5, i_num_expired = 6 WHERE fk_i_item_id = $id");
    $admin->query("INSERT IGNORE INTO $log (fk_i_item_id, s_reporter, s_reason, dt_date) VALUES ($id, 'ip:1', 'spam', NOW()), ($id, 'ip:2', 'offensive', NOW())");
};
$setPref = static function (string $value): void {
    osc_set_preference('item_views_enabled', $value, 'osclass', 'BOOLEAN');
    osc_reset_preferences();
};

harness_section('addView');

$setPref('1');
$before = $row($itemA)['i_num_views'];
check('a view is counted', ListingCounters::addView($itemA));
pin('the counter moved by one', $before + 1, $row($itemA)['i_num_views']);

$setPref('0');
check('with views off the call still succeeds', ListingCounters::addView($itemA));
pin('but nothing is counted', $before + 1, $row($itemA)['i_num_views']);
$setPref('1');

harness_section('clearReport');

$seedReports($itemA);
$seedReports($itemB);
pin('one counter is reset', 1, ListingCounters::clearReport($itemA, 'spam'));
pin('only that counter', array('i_num_views' => $before + 1, 'i_num_spam' => 0, 'i_num_repeated' => 3, 'i_num_bad_classified' => 4, 'i_num_offensive' => 5, 'i_num_expired' => 6), $row($itemA));
pin('a counter already at 0 changes no row', 0, ListingCounters::clearReport($itemA, 'spam'));
pin('an unknown name changes nothing', 0, ListingCounters::clearReport($itemA, 'views'));
foreach (array('duplicated' => 'i_num_repeated', 'bad' => 'i_num_bad_classified', 'offensive' => 'i_num_offensive', 'expired' => 'i_num_expired') as $name => $column) {
    ListingCounters::clearReport($itemA, $name);
    pin("'$name' resets $column", 0, $row($itemA)[$column]);
}
$seedReports($itemA);
pin("'all' resets every report counter", 1, ListingCounters::clearReport($itemA, 'all'));
pin('views are kept', array('i_num_views' => $before + 1, 'i_num_spam' => 0, 'i_num_repeated' => 0, 'i_num_bad_classified' => 0, 'i_num_offensive' => 0, 'i_num_expired' => 0), $row($itemA));
pin('the report log is kept', 2, $logCount($itemA));
pin('another listing is untouched', 2, $row($itemB)['i_num_spam']);

harness_section('clearAllReports');

$seedReports($itemA);
ListingCounters::clearAllReports($itemA);
pin('every report counter is reset', 0, array_sum(array_slice($row($itemA), 1)));
pin('the report log is emptied', 0, $logCount($itemA));
pin('another listing keeps its log', 2, $logCount($itemB));
pin('and its counters', 2, $row($itemB)['i_num_spam']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/listing-counters.php */
