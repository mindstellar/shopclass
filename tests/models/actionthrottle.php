<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Behaviour pins for \mindstellar\security\ActionThrottle — the per-address rate
 * limit on the public share-a-listing and contact-seller forms.
 *
 * The limit is what stops one source driving the site's own address as a spam
 * relay, so the arithmetic is what matters: a count read one low lets an extra
 * send through, one read high refuses a legitimate visitor. Fixtures are written
 * with raw mysqli, never through the code under test, so a bug in record() cannot
 * hide by corrupting the rows exceeded() then reads.
 *
 * The source address is read through Params from $_SERVER['REMOTE_ADDR']; each
 * test sets it and re-inits Params so the limiter sees a known address (there is
 * no REMOTE_ADDR under CLI otherwise).
 *
 * Usage:  php tests/models/actionthrottle.php      (standalone, own scratch database)
 *         php tests/run-models.php actionthrottle  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\security\ActionThrottle;

$admin = scratchdb_session('osc_models_actionthrottle');
$table = DB_TABLE_PREFIX . 't_login_attempt';

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** Seed one row with raw mysqli, bypassing the code under test. */
$seed = static function ($context, $ip, $date) use ($admin, $table): void {
    $stmt = $admin->prepare(
        "INSERT INTO $table (s_context, s_account, s_ip, dt_date) VALUES (?, '', ?, ?)"
    );
    $stmt->bind_param('sss', $context, $ip, $date);
    $stmt->execute();
    $stmt->close();
};

/** @return array */
$rows = static function () use ($admin, $table): array {
    $res = $admin->query("SELECT * FROM $table ORDER BY pk_i_id");
    $out = array();
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $res->free();

    return $out;
};

$at = static function ($secondsAgo): string {
    return date('Y-m-d H:i:s', time() - $secondsAgo);
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

$written = $rows();
check('exactly one row was written', count($written) === 1, (string)count($written));
$row = $written[0] ?? array();
pin('s_context round-trips', 'send_friend', $row['s_context'] ?? null);
pin('s_account is empty — these limits key on the address', '', $row['s_account'] ?? null);
pin('s_ip is the source address', '203.0.113.9', $row['s_ip'] ?? null);

$truncate();
pin('one record() call costs one query', 1, harness_query_count(static function () {
    ActionThrottle::record('send_friend');
}));

$truncate();
$setIp('');
ActionThrottle::record('send_friend');
check('record() with no source address writes nothing', count($rows()) === 0, (string)count($rows()));

/* ----------------------------------------------------------------------------
 * exceeded() — the ceiling
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle::exceeded — the ceiling');

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 4; $i++) {
    $seed('send_friend', '198.51.100.1', $at(60));
}
check('four sends under a limit of five is allowed', ActionThrottle::exceeded('send_friend', 5, 3600) === false);

$seed('send_friend', '198.51.100.1', $at(60)); // fifth
check('the fifth send reaches the limit and the next is refused', ActionThrottle::exceeded('send_friend', 5, 3600) === true);
check('a higher limit still lets it through', ActionThrottle::exceeded('send_friend', 10, 3600) === false);

harness_section('ActionThrottle::exceeded — a max of zero disables the limit');

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 20; $i++) {
    $seed('send_friend', '198.51.100.1', $at(60));
}
check('max <= 0 never refuses, however many events exist', ActionThrottle::exceeded('send_friend', 0, 3600) === false);

/* ----------------------------------------------------------------------------
 * The window, the context and the address each scope the count
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle::exceeded — window, context and address scope the count');

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 5; $i++) {
    $seed('send_friend', '198.51.100.1', $at(4000)); // older than a 3600s window
}
check('events older than the window do not count', ActionThrottle::exceeded('send_friend', 5, 3600) === false);

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 5; $i++) {
    $seed('item_contact', '198.51.100.1', $at(60)); // a different context
}
check('another context does not count against this one', ActionThrottle::exceeded('send_friend', 5, 3600) === false);
check('and the context that has the events is over its own limit', ActionThrottle::exceeded('item_contact', 5, 3600) === true);

$truncate();
$setIp('198.51.100.1');
for ($i = 0; $i < 5; $i++) {
    $seed('send_friend', '198.51.100.2', $at(60)); // a different address
}
check("another address's events do not count against this one", ActionThrottle::exceeded('send_friend', 5, 3600) === false);

harness_section('ActionThrottle::exceeded — with no source address');

$truncate();
$setIp('');
check('no address to key on is never refused', ActionThrottle::exceeded('send_friend', 1, 3600) === false);

/* ----------------------------------------------------------------------------
 * Fails open when the ledger is missing — the same guarantee LoginThrottle makes:
 * the table arrives with an upgrade and the files are in place before it runs, so
 * a missing ledger must let the action through, not take the form down.
 * ------------------------------------------------------------------------- */
harness_section('ActionThrottle: ledger unavailable');

$setIp('203.0.113.50');
$admin->query("DROP TABLE IF EXISTS $table");

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

// Put the table back so the runner's inter-file truncate still finds it.
$admin->query(
    "CREATE TABLE $table ("
    . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
    . " s_context VARCHAR(20) NOT NULL DEFAULT '',"
    . " s_account VARCHAR(191) NOT NULL DEFAULT '',"
    . " s_ip VARCHAR(45) NOT NULL DEFAULT '',"
    . ' dt_date DATETIME NOT NULL,'
    . ' PRIMARY KEY (pk_i_id),'
    . ' INDEX idx_ip (s_ip, dt_date),'
    . ' INDEX idx_account (s_context, s_account(64), dt_date),'
    . ' INDEX idx_date (dt_date)'
    . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
);

$truncate();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/actionthrottle.php */
