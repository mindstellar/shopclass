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
 * Behaviour pins for \mindstellar\security\ActionThrottle, the per-address limit on public
 * forms. A count read one low lets an extra send through, one read high refuses a visitor.
 * Fixtures are added with RateLimit::addRolling() (pinned in ratelimit.php), not record().
 *
 * Usage:  php tests/models/actionthrottle.php      (standalone, own scratch database)
 *         php tests/run-models.php actionthrottle  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\security\ActionThrottle;

$admin = scratchdb_session('osc_models_actionthrottle');
// exceededFor() reads its limits through the filter API.
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
$table  = DB_TABLE_PREFIX . 't_rate_counter';
$ledger = DB_TABLE_PREFIX . 't_login_attempt';

$truncate = static function () use ($admin, $table, $ledger): void {
    $admin->query("TRUNCATE TABLE $table");
    $admin->query("TRUNCATE TABLE $ledger");
};

/** One event for $ip, $secondsAgo in the past. */
$seed = static function ($context, $ip, $secondsAgo): void {
    \mindstellar\security\RateLimit::addRolling($context, $ip, 86400, time() - $secondsAgo);
};

/** Events counted, across every row. */
$events = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COALESCE(SUM(i_count), 0) FROM $table")->fetch_row()[0];
};

/** Point the limiter at a known source address (or none, when ''). */
$setIp = static function ($ip): void {
    if ($ip === '') {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }
    Params::init();
};

/* ----------------------------------------------------------------------------
 * Surface
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle: public surface');

pin(
    'exceeded signature',
    'public static exceeded($context, $max, $windowSeconds)',
    harness_method_signature('mindstellar\security\ActionThrottle', 'exceeded')
);
pin(
    'record signature',
    'public static record($context)',
    harness_method_signature('mindstellar\security\ActionThrottle', 'record')
);

/* ----------------------------------------------------------------------------
 * record() — one event for the current address, keyed by IP, no account
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle::record');

$truncate();
$setIp('203.0.113.9');
ActionThrottle::record('send_friend');
pin('one event is counted', 1, $events());
pin('for that address and context', 1, \mindstellar\security\RateLimit::countRolling('send_friend', '203.0.113.9', 3600));
pin('the sign-in ledger is left alone', '0', $admin->query("SELECT COUNT(*) FROM $ledger")->fetch_row()[0]);
$bucket = (string) $admin->query("SELECT s_bucket FROM $table LIMIT 1")->fetch_row()[0];
check('the address is not stored as given', strpos($bucket, '203.0.113.9') === false);

$truncate();
pin('one record() call costs one query', 1, harness_query_count(static function () {
    ActionThrottle::record('send_friend');
}));

$truncate();
$setIp('');
ActionThrottle::record('send_friend');
check('record() with no source address writes nothing', $events() === 0, (string)$events());

/* ----------------------------------------------------------------------------
 * exceeded() — the ceiling
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle::exceeded — the ceiling');

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 4; $i++) {
    $seed('send_friend', '198.51.100.1', 60);
}
check('four sends under a limit of five is allowed', ActionThrottle::exceeded('send_friend', 5, 3600) === false);

$seed('send_friend', '198.51.100.1', 60); // fifth
check('the fifth send reaches the limit and the next is refused', ActionThrottle::exceeded('send_friend', 5, 3600) === true);
check('a higher limit still lets it through', ActionThrottle::exceeded('send_friend', 10, 3600) === false);
check('exceededFor uses the default limit', ActionThrottle::exceededFor('send_friend', 5) === true);
$filterMax = 10;
osc_add_filter('action_throttle_limit', static function ($limit, $context) use (&$filterMax) {
    return $context === 'send_friend' && $filterMax !== null ? array('max' => $filterMax) + $limit : $limit;
});
check('the filter raises it for that form', ActionThrottle::exceededFor('send_friend', 5) === false);
for ($i = 0; $i < 5; $i++) {
    $seed('item_contact', '198.51.100.1', 60);
}
check('and leaves other forms at their default', ActionThrottle::exceededFor('item_contact', 5) === true);

harness_section('ActionThrottle::exceededFor — stored limit, default and filter');

$filterMax = null;
$truncate();
$setIp('198.51.100.7');
$fill = static function ($context, $n) use ($seed): void {
    for ($i = 0; $i < $n; $i++) {
        $seed($context, '198.51.100.7', 60);
    }
};
$fill('send_friend', 4);
check('no preference: the built-in default of 5 allows four', ActionThrottle::exceededFor('send_friend') === false);
$fill('send_friend', 1);
check('and refuses at five', ActionThrottle::exceededFor('send_friend') === true);
osc_set_preference('throttle_send_friend', '8');
check('a stored limit replaces the default', ActionThrottle::exceededFor('send_friend') === false);
osc_set_preference('throttle_send_friend', '5');
check('a stored limit can be lower too', ActionThrottle::exceededFor('send_friend') === true);
osc_set_preference('throttle_send_friend', '0');
check('0 means no limit', ActionThrottle::exceededFor('send_friend') === false);
osc_set_preference('throttle_send_friend', '2');
check('the stored limit wins over a caller default', ActionThrottle::exceededFor('send_friend', 50) === true);
$filterMax = 10;
check('the filter still has the last word', ActionThrottle::exceededFor('send_friend') === false);
$filterMax = null;
osc_delete_preference('throttle_send_friend');

harness_section('ActionThrottle::exceeded — a max of zero disables the limit');

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 20; $i++) {
    $seed('send_friend', '198.51.100.1', 60);
}
check('max <= 0 never refuses, however many events exist', ActionThrottle::exceeded('send_friend', 0, 3600) === false);

/* ----------------------------------------------------------------------------
 * The window, the context and the address each scope the count
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle::exceeded — window, context and address scope the count');

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 5; $i++) {
    $seed('send_friend', '198.51.100.1', 4000); // older than a 3600s window
}
check('events older than the window do not count', ActionThrottle::exceeded('send_friend', 5, 3600) === false);

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 5; $i++) {
    $seed('item_contact', '198.51.100.1', 60); // a different context
}
check('another context does not count against this one', ActionThrottle::exceeded('send_friend', 5, 3600) === false);
check('and the context that has the events is over its own limit', ActionThrottle::exceeded('item_contact', 5, 3600) === true);

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 5; $i++) {
    $seed('send_friend', '198.51.100.2', 60); // a different address
}
check("another address's events do not count against this one", ActionThrottle::exceeded('send_friend', 5, 3600) === false);

$truncate();
$setIp('2001:db8:5:6::1');
ActionThrottle::record('send_friend');
pin('an IPv6 source is counted as its /64', 1, \mindstellar\security\RateLimit::countRolling('send_friend', '2001:db8:5:6::/64', 3600));
$setIp('2001:db8:5:6:abcd::2');
check('two addresses in one /64 share a count', ActionThrottle::exceeded('send_friend', 1, 3600) === true);
$setIp('2001:db8:5:7::1');
check('the next /64 has its own', ActionThrottle::exceeded('send_friend', 1, 3600) === false);

harness_section('ActionThrottle::exceeded — with no source address');

$truncate();
$setIp('');
check('no address to key on is never refused', ActionThrottle::exceeded('send_friend', 1, 3600) === false);

/* ----------------------------------------------------------------------------
 * Fails open when the ledger is missing — the same guarantee LoginThrottle makes:
 * the table arrives with an upgrade and the files are in place before it runs, so
 * a missing ledger must let the action through, not take the form down.
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle: counter unavailable');

$setIp('203.0.113.50');
$admin->query("RENAME TABLE $table TO {$table}_gone");

check('exceeded() lets the action through when the table is gone', ActionThrottle::exceeded('send_friend', 1, 3600) === false);
check(
    'record() swallows the failure instead of throwing',
    (static function () {
        try {
            ActionThrottle::record('send_friend');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    })()
);
$admin->query("RENAME TABLE {$table}_gone TO $table");

harness_section('ActionThrottle: a short posting wait');

$truncate();
$setIp('198.51.100.9');
ActionThrottle::record('item_post');
check('a second post inside a 60s wait is refused', ActionThrottle::exceeded('item_post', 1, 60) === true);
$truncate();
$seed('item_post', '198.51.100.9', 60 + \mindstellar\security\RateLimit::SLICE + 1);
check('one past the wait (and a slice) is allowed', ActionThrottle::exceeded('item_post', 1, 60) === false);

$truncate();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/actionthrottle.php */
