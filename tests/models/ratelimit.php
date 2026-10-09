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
 * RateLimit: one counter per key per window, read back by count(), the key stored hashed, windows pruned
 * once they end, and a missing table allowing the request rather than refusing it.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\security\RateLimit;

$admin = scratchdb_session('osc_models_ratelimit');
$table = DB_TABLE_PREFIX . 't_rate_counter';

$count = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
};

harness_section('counting');

$admin->query("TRUNCATE TABLE $table");
// A long window, so the test cannot straddle two of them.
$got = array();
for ($i = 0; $i < 4; $i++) {
    $got[] = RateLimit::hit('test_api', 'key-one', 3, 3600);
}
pin('the first three are allowed, the fourth is not', array(true, true, true, false), $got);
pin('another key has its own count', true, RateLimit::hit('test_api', 'key-two', 3, 3600));
pin('one row per key, not per request', 2, $count());
$bucket = $admin->query("SELECT s_bucket FROM $table LIMIT 1")->fetch_row()[0];
check('the key is not stored as given', strpos($bucket, 'key-one') === false && strpos($bucket, 'key-two') === false);
pin('count() reads the current window without counting', array(4, 4), array(RateLimit::count('test_api', 'key-one', 3600), RateLimit::count('test_api', 'key-one', 3600)));
pin('increment() returns the new count, in one query', [1, 2, 3], [RateLimit::increment('test_inc', 'k', 3600), RateLimit::increment('test_inc', 'k', 3600), RateLimit::increment('test_inc', 'k', 3600)]);
pin('increment() costs one query', 1, harness_query_count(static fn () => RateLimit::increment('test_inc', 'k', 3600)));
pin('and agrees with count()', 4, RateLimit::count('test_inc', 'k', 3600));
pin('hit() costs one query too', 1, harness_query_count(static fn () => RateLimit::hit('test_inc', 'k2', 5, 3600)));
$admin->query("DELETE FROM $table WHERE s_bucket LIKE 'test_inc:%'");
pin('count() of an unseen key is 0', 0, RateLimit::count('test_api', 'key-none', 3600));
pin('count() of another window length is its own bucket', 0, RateLimit::count('test_api', 'key-one', 60));
pin('a limit of 0 allows and counts nothing', array(true, 2), array(RateLimit::hit('test_api', 'key-three', 0, 60), $count()));

harness_section('a given time');

$past = 1_700_000_000;
pin('add() opens the window of the time it is given', 1, RateLimit::add('test_now', 'k', 1, 3600, true, $past));
pin('a row for that window, not now\'s', array((string) ($past - $past % 3600), (string) ($past - $past % 3600 + 3600)),
    $admin->query("SELECT i_window, i_expires FROM $table WHERE s_bucket LIKE 'test_now:%'")->fetch_row());
pin('count() at that time sees it', 1, RateLimit::count('test_now', 'k', 3600, $past));
pin('count() now does not', 0, RateLimit::count('test_now', 'k', 3600));
pin('countMany() at that time sees it', array('k' => 1), RateLimit::countMany('test_now', array('k'), 3600, $past));
pin('hit() at that time counts on it', array(true, false), array(RateLimit::hit('test_now', 'k', 2, 3600, true, $past), RateLimit::hit('test_now', 'k', 2, 3600, true, $past)));

require_once __DIR__ . '/../lib/test-clock.php';
$clockAt  = $past + 7200;
$failures = new \mindstellar\api\auth\FailureCounter(null, null, static fn (): int => PHP_INT_MAX, static fn (int $t): bool => true, new TestClock(static function () use (&$clockAt): int {
    return $clockAt;
}));
$failures->record('192.0.2.77', 'AAAAAAAAAAAAAAAA', true);
$window = $clockAt - $clockAt % \mindstellar\api\auth\FailureCounter::WINDOW;
pin('a Clock given to FailureCounter reaches RateLimit', '1', $admin->query("SELECT COUNT(*) FROM $table WHERE s_bucket LIKE 'api_auth_fail:%' AND i_window = $window")->fetch_row()[0]);
check('and its count is read at that time', $failures->keyBlocked('192.0.2.77', 'AAAAAAAAAAAAAAAA') === false
    && RateLimit::countMany('api_auth_fail', array('key:192.0.2.77|AAAAAAAAAAAAAAAA'), \mindstellar\api\auth\FailureCounter::WINDOW, $clockAt) === array('key:192.0.2.77|AAAAAAAAAAAAAAAA' => 1));
$admin->query("DELETE FROM $table WHERE s_bucket LIKE 'test_now:%' OR s_bucket LIKE 'api_auth_fail:%'");

harness_section('rolling counts');

$t = 1_700_000_005;
RateLimit::addRolling('test_roll', 'k', 3600, $t);
RateLimit::addRolling('test_roll', 'k', 3600, $t + 1);
RateLimit::addRolling('test_roll', 'k', 3600, $t + 600);
pin('events in one slice share a row', 2, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE s_bucket LIKE 'test_roll:%'")->fetch_row()[0]);
pin('all three count inside the window', 3, RateLimit::countRolling('test_roll', 'k', 3600, $t + 700));
pin('the window rolls: the first two age out', 1, RateLimit::countRolling('test_roll', 'k', 3600, $t + 3600 + RateLimit::SLICE + 1));
pin('a short window sees only the recent one', 1, RateLimit::countRolling('test_roll', 'k', 60, $t + 620));
pin('another key has its own count', 0, RateLimit::countRolling('test_roll', 'other', 3600, $t + 700));
pin('a fixed counter of the same name is apart', 0, RateLimit::count('test_roll', 'k', 3600, $t));
pin('a row is kept for the window it was added for', (string) ($t - $t % RateLimit::SLICE + RateLimit::SLICE + 3600),
    $admin->query("SELECT MIN(i_expires) FROM $table WHERE s_bucket LIKE 'test_roll:%'")->fetch_row()[0]);
$admin->query("DELETE FROM $table WHERE s_bucket LIKE 'test_roll:%'");

harness_section('pruning');

$admin->query("INSERT INTO $table (s_bucket, i_window, i_expires, i_count) VALUES ('old', 1, 2, 5)");
pin('an ended window is dropped', 1, RateLimit::prune());
pin('and a live one stays', 2, $count());

$digits = '(SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9)';
$admin->query("INSERT INTO $table (s_bucket, i_window, i_expires, i_count) SELECT CONCAT('bulk', a.n + 10 * b.n + 100 * c.n), 1, 2, 1 FROM $digits a, $digits b, $digits c");
pin('many ended windows are dropped across several batches', 1000, RateLimit::prune(300));
pin('and the live ones still stay', 2, $count());
$admin->query("INSERT INTO $table (s_bucket, i_window, i_expires, i_count) VALUES ('old1', 1, 2, 1), ('old2', 1, 2, 1), ('old3', 1, 2, 1)");
pin('the round cap leaves the rest for the next run', array(2, 1), array(RateLimit::prune(1, 2), RateLimit::prune(1, 2)));

harness_section('failing open');

$admin->query("RENAME TABLE $table TO {$table}_gone");
pin('with no table the request is allowed', true, RateLimit::hit('test_api', 'key-one', 1, 3600));
pin('count() with no table is null', null, RateLimit::count('test_api', 'key-one', 3600));
pin('increment() with no table is null', null, RateLimit::increment('test_api', 'key-one', 3600));
pin('unless the caller asks to refuse', false, RateLimit::hit('test_api', 'key-one', 1, 3600, false));
pin('countRolling() with no table is null', null, RateLimit::countRolling('test_api', 'key-one', 3600));
pin('addRolling() with no table says so', false, RateLimit::addRolling('test_api', 'key-one', 3600));
$admin->query("RENAME TABLE {$table}_gone TO $table");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
