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

harness_section('views with a Redis-protocol cache');

$lcServer = getenv('OSC_TEST_REDIS');
if (!$lcServer) {
    echo "  (no OSC_TEST_REDIS server: the Redis checks were not run)\n";
} else {
    if (!function_exists('osc_job_enqueue')) {
        function osc_job_enqueue(string $type, array $payload = array(), array $options = array()): int
        {
            return \mindstellar\job\JobQueue::getInstance()->enqueue($type, $payload, $options);
        }
    }
    [$lcHost, $lcPort]        = explode(':', $lcServer) + [1 => '6379'];
    $lcShared                 = new ReflectionProperty(\mindstellar\cache\CacheManager::class, 'instance');
    $lcShared->setAccessible(true);
    $lcBefore                 = $lcShared->getValue();
    $lcConfigBefore           = $GLOBALS['_cache_config'] ?? null;
    $GLOBALS['_cache_config'] = [['default_host' => $lcHost, 'default_port' => (int) $lcPort]];
    $lcCache                  = new \mindstellar\cache\RedisCache();
    $lcCache->flush();
    $lcShared->setValue(null, $lcCache);
    $lcJobs    = DB_TABLE_PREFIX . 't_job_queue';
    $lcFlushes = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM $lcJobs WHERE s_type = '" . ListingCounters::FLUSH_JOB . "'")->fetch_row()[0];
    $lcPremium = static fn (int $id): int => (int) $admin->query("SELECT i_num_premium_views FROM $stats WHERE fk_i_item_id = $id")->fetch_row()[0];
    $admin->query("DELETE FROM $lcJobs WHERE s_type = '" . ListingCounters::FLUSH_JOB . "'");

    $lcViews   = $row($itemA)['i_num_views'];
    $lcPremA   = $lcPremium($itemA);
    $lcGone    = seed_item($admin, $catId, null, 'Deleted soon');
    $lcQueries = harness_query_count(static function () use ($itemA, $itemB, $lcGone): void {
        ListingCounters::addView($itemA);
        ListingCounters::addView($itemA);
        ListingCounters::addView($lcGone);
        ListingCounters::addPremiumViews(array($itemA, $itemB, $itemA));
    });
    pin('views are not written to the table at once', $lcViews, $row($itemA)['i_num_views']);
    pin('one job is queued to write them', 1, $lcFlushes());
    pin('which is the only database write for many views', 1, $lcQueries);
    $admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_item WHERE pk_i_id = $lcGone");
    pin('the job writes every listing still there', 3, ListingCounters::flush());
    pin('with all its views', $lcViews + 2, $row($itemA)['i_num_views']);
    pin('premium views once per listing shown', $lcPremA + 1, $lcPremium($itemA));
    pin('a deleted listing gets no views', 0, (int) $admin->query("SELECT COALESCE(SUM(i_num_views), 0) FROM $stats WHERE fk_i_item_id = $lcGone")->fetch_row()[0]);
    pin('a second run has nothing to write', 0, ListingCounters::flush());

    $setPref('0');
    ListingCounters::addView($itemA);
    pin('with views off nothing is added up', 0, ListingCounters::flush());
    $setPref('1');

    $GLOBALS['_cache_config'] = [['default_host' => '127.0.0.1', 'default_port' => 1]];
    $lcShared->setValue(null, new \mindstellar\cache\RedisCache());
    ListingCounters::addView($itemA);
    pin('when the server does not answer the view is written at once', $lcViews + 3, $row($itemA)['i_num_views']);

    $lcCache->flush();
    $lcShared->setValue(null, $lcBefore);
    $GLOBALS['_cache_config'] = $lcConfigBefore;
    $admin->query("DELETE FROM $lcJobs WHERE s_type = '" . ListingCounters::FLUSH_JOB . "'");
}

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/listing-counters.php */
