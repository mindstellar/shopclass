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
 * Behaviour pins for \mindstellar\security\LoginThrottle's use of the shared ledger.
 * t_login_attempt also holds contact-form and listing-post events for other limits.
 * Those must not count toward a sign-in block, and a sign-in must not clear them.
 *
 * Usage:  php tests/models/loginthrottle.php      (standalone, own scratch database)
 *         php tests/run-models.php loginthrottle  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\security\LoginThrottle;

$admin = scratchdb_session('osc_models_loginthrottle');
$table = DB_TABLE_PREFIX . 't_login_attempt';

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** Seed rows with raw mysqli, bypassing the code under test. */
$seed = static function ($context, $account, $ip, $count) use ($admin, $table): void {
    $date = date('Y-m-d H:i:s', time() - 60);
    $stmt = $admin->prepare("INSERT INTO $table (s_context, s_account, s_ip, dt_date) VALUES (?, ?, ?, ?)");
    for ($i = 0; $i < $count; $i++) {
        $stmt->bind_param('ssss', $context, $account, $ip, $date);
        $stmt->execute();
    }
    $stmt->close();
};

$countContext = static function ($context) use ($admin, $table): int {
    $res = $admin->query("SELECT COUNT(*) AS n FROM $table WHERE s_context = '" . $admin->real_escape_string($context) . "'");
    $n   = (int)$res->fetch_assoc()['n'];
    $res->free();

    return $n;
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
Params::init();

harness_section('Other limits do not block a sign-in');

$truncate();
$seed('item_contact', '', '203.0.113.50', osc_login_throttle_max_ip() + 5);
pin('contact-form events alone do not block', LoginThrottle::OK, LoginThrottle::evaluate('web', 'a@example.invalid')['status']);
pin('and are not listed as failed sign-ins', 0, count(LoginThrottle::activity()));

$seed('web', 'a@example.invalid', '203.0.113.50', osc_login_throttle_max_ip());
pin('sign-in failures at the limit block the address', LoginThrottle::BLOCKED, LoginThrottle::evaluate('web', 'b@example.invalid')['status']);

harness_section('A sign-in clears only sign-in failures');

LoginThrottle::clear('web', 'a@example.invalid');
pin('the address is free again', LoginThrottle::OK, LoginThrottle::evaluate('web', 'b@example.invalid')['status']);
pin('contact-form events are kept', osc_login_throttle_max_ip() + 5, $countContext('item_contact'));

harness_section('Unblocking an address');

$seed('admin-recover', 'c@example.invalid', '203.0.113.50', 2);
$seed('item_post', 'c@example.invalid', '203.0.113.50', 2);
LoginThrottle::unblockIp('203.0.113.50');
pin('removes its sign-in failures', 0, $countContext('admin-recover'));
pin('keeps its listing posts', 2, $countContext('item_post'));

harness_section('An IPv6 client is one /64');

$truncate();
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::a';
Params::init();
for ($i = 0; $i < osc_login_throttle_max_ip(); $i++) {
    LoginThrottle::recordFailure('web', 'd' . $i . '@example.invalid');
}
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:ffff::b';
Params::init();
pin('another address in the same /64 is blocked', LoginThrottle::BLOCKED, LoginThrottle::evaluate('web', 'e@example.invalid')['status']);
pin('the list shows the /64', '2001:db8:1:2::/64', LoginThrottle::activity()[0]['ip'] ?? null);
LoginThrottle::unblockIp('2001:db8:1:2::/64');
pin('unblocking the /64 frees it', LoginThrottle::OK, LoginThrottle::evaluate('web', 'e@example.invalid')['status']);
for ($i = 0; $i < osc_login_throttle_max_ip(); $i++) {
    LoginThrottle::recordFailure('web', 'f' . $i . '@example.invalid');
}
pin('the /64 is blocked again', LoginThrottle::BLOCKED, LoginThrottle::evaluate('web', 'e@example.invalid')['status']);
LoginThrottle::unblockIp('2001:db8:1:2:9::9');
pin('unblocking a bare address in the /64 frees it', LoginThrottle::OK, LoginThrottle::evaluate('web', 'e@example.invalid')['status']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
Params::init();

$truncate();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/loginthrottle.php */
