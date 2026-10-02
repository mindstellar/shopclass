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
 * RateLimit: one counter per key per window, the key stored hashed, windows pruned
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
pin('a limit of 0 allows and counts nothing', array(true, 2), array(RateLimit::hit('test_api', 'key-three', 0, 60), $count()));

harness_section('pruning');

$admin->query("INSERT INTO $table (s_bucket, i_window, i_expires, i_count) VALUES ('old', 1, 2, 5)");
pin('an ended window is dropped', 1, RateLimit::prune());
pin('and a live one stays', 2, $count());

harness_section('failing open');

$admin->query("RENAME TABLE $table TO {$table}_gone");
pin('with no table the request is allowed', true, RateLimit::hit('test_api', 'key-one', 1, 3600));
pin('unless the caller asks to refuse', false, RateLimit::hit('test_api', 'key-one', 1, 3600, false));
$admin->query("RENAME TABLE {$table}_gone TO $table");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
