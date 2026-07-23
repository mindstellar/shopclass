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
 * Characterization pins for the Log model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * insertLog()'s body moves to the parameterized query layer.
 *
 * insertLog() calls DBCommandClass::insert($table, $set) — the two-argument
 * legacy form, not DAO::insert($values) — and returns exactly what query()
 * returns for a write statement: bool true on success, bool false on any
 * driver-level failure (pinned below via a NOT NULL violation), with no
 * partial row left behind on failure.
 *
 * The REMOTE_ADDR fallback does not behave the way its own "// CRON." comment
 * suggests. Params reads server values from a snapshot Params::init() takes
 * once, not live off $_SERVER, so setting $_SERVER['REMOTE_ADDR'] inside
 * insertLog() has no effect on the very next read of the same key a few lines
 * later. The fallback DOES mutate $_SERVER for whatever runs after it in the
 * same request, but the s_ip column THIS call writes still comes from the
 * stale snapshot and lands as an empty string. Both halves are pinned below
 * because that is what actually happens on a live install.
 *
 * Params::getServerParam() runs every value through purify(), which builds an
 * HTMLPurifier instance the first time REMOTE_ADDR is actually present in the
 * snapshot; that constructor reaches for osc_uploads_path(). scratchdb.php
 * supplies the minimal stand-in that makes the "REMOTE_ADDR present" branch
 * reachable at all.
 *
 * Usage:  php tests/models/log.php          (standalone, own scratch database)
 *         php tests/run-models.php log      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_log');
$table = DB_TABLE_PREFIX . 't_log';

$log = Log::newInstance();

// t_log declares no primary key, so a fixture reads back cleanly only when the
// table holds exactly the row(s) the current scenario just wrote.
$truncateLog = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/**
 * Read every row back with raw mysqli, never through the code under test.
 *
 * @return array
 */
$logRows = static function () use ($admin, $table): array {
    $res  = $admin->query("SELECT * FROM $table");
    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
};

// Saved once, up front, and restored at the very end so nothing this file does
// to the REMOTE_ADDR fallback leaks into another model test under the runner.
$savedRemoteAddr = array_key_exists('REMOTE_ADDR', $_SERVER) ? $_SERVER['REMOTE_ADDR'] : null;

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Log: public surface');

pin(
    'insertLog signature is unchanged',
    'public insertLog($section, $action, $id, $data, $who, $whoId)',
    harness_method_signature('Log', 'insertLog')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Log', 'newInstance'));
check('Log still extends DAO', is_subclass_of('Log', 'DAO'));
check('$log->dao is a live DBCommandClass (C5)', $log->dao instanceof DBCommandClass);
pin('table name is unchanged', DB_TABLE_PREFIX . 't_log', $log->getTableName());
pin(
    'field allowlist is unchanged',
    array('dt_date', 's_section', 's_action', 'fk_i_id', 's_data', 's_ip', 's_who', 'fk_i_who_id'),
    $log->getFields()
);
pin(
    'the model adds exactly one method of its own',
    array('__construct', 'insertLog', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Log')),
        array('__construct', 'insertLog', 'newInstance')
    ))
);

/* ----------------------------------------------------------------------------
 * insertLog() — the return ledger and the row it writes.
 * ------------------------------------------------------------------------- */
harness_section('Log::insertLog — success');

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
Params::init();

$truncateLog();
$ret = $log->insertLog('items', 'edit', 42, 'log payload', 'admin', 7);
pin('a successful write returns bool true', true, $ret);

$rows = $logRows();
check('exactly one row was written', count($rows) === 1, (string) count($rows));
$row = $rows[0] ?? array();
pin(
    'the row carries exactly the eight schema columns',
    array('dt_date', 's_section', 's_action', 'fk_i_id', 's_data', 's_ip', 's_who', 'fk_i_who_id'),
    array_keys($row)
);
pin('s_section round-trips', 'items', $row['s_section'] ?? null);
pin('s_action round-trips', 'edit', $row['s_action'] ?? null);
pin('fk_i_id round-trips', '42', $row['fk_i_id'] ?? null);
pin('s_data round-trips', 'log payload', $row['s_data'] ?? null);
pin('s_ip round-trips the ambient REMOTE_ADDR', '203.0.113.9', $row['s_ip'] ?? null);
pin('s_who round-trips', 'admin', $row['s_who'] ?? null);
pin('fk_i_who_id round-trips', '7', $row['fk_i_who_id'] ?? null);
check(
    'dt_date is populated with a real timestamp',
    isset($row['dt_date']) && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['dt_date']),
    describe($row['dt_date'] ?? null)
);

harness_section('Log::insertLog — failure');

$truncateLog();
$failRet = $log->insertLog(null, 'edit', 42, 'log payload', 'admin', 7);
pin('a NOT NULL violation (null section) returns bool false', false, $failRet);
pin('a failed write leaves no row behind', array(), $logRows());

/* ----------------------------------------------------------------------------
 * The REMOTE_ADDR fallback — pinned exactly as observed, not as the method's
 * own "// CRON." comment implies.
 * ------------------------------------------------------------------------- */
harness_section('Log::insertLog — REMOTE_ADDR present');

$_SERVER['REMOTE_ADDR'] = '198.51.100.5';
Params::init();
$truncateLog();
$log->insertLog('items', 'edit', 1, 'x', 'admin', 1);
pin('an ambient REMOTE_ADDR is used as-is', '198.51.100.5', $logRows()[0]['s_ip'] ?? null);
pin('the superglobal is left untouched when REMOTE_ADDR was already present', '198.51.100.5', $_SERVER['REMOTE_ADDR']);

harness_section('Log::insertLog — REMOTE_ADDR absent');

unset($_SERVER['REMOTE_ADDR']);
Params::init();
$truncateLog();
$log->insertLog('items', 'edit', 1, 'x', 'admin', 1);
pin(
    's_ip stays empty: Params re-reads its own stale snapshot, not the $_SERVER the fallback just mutated',
    '',
    $logRows()[0]['s_ip'] ?? null
);
pin('the fallback still mutates the live superglobal for whatever runs next in the request', '127.0.0.1', $_SERVER['REMOTE_ADDR']);

/* ----------------------------------------------------------------------------
 * Query cost — a single insert is one statement, and stays one.
 * ------------------------------------------------------------------------- */
harness_section('Log: query cost');

pin('one insertLog() call costs one query', 1, harness_query_count(static function () use ($log) {
    $log->insertLog('items', 'edit', 1, 'x', 'admin', 1);
}));

if ($savedRemoteAddr !== null) {
    $_SERVER['REMOTE_ADDR'] = $savedRemoteAddr;
} else {
    unset($_SERVER['REMOTE_ADDR']);
}
Params::init(); // resync the snapshot so later model tests under the runner see the restored state

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/log.php */
