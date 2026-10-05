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
$admin->query("RENAME TABLE {$table}_gone TO $table");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
