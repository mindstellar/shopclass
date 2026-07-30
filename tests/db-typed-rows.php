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
 * Typed-row parity pins.
 *
 * The legacy query layer reads results through mysqli::query(), which hands
 * back every column as a PHP string. The parameterized layer runs prepared
 * statements, which carry column metadata, so numeric columns come back as
 * native int/float. Moving a model method from one to the other therefore
 * changes the TYPE of values it returns, silently, for every caller that
 * compares with === or concatenates.
 *
 * This script measures that divergence on the platform actually running the
 * suite instead of taking it on faith, and pins the contract of the
 * osc_db_stringify_row()/osc_db_stringify_rows() helpers that converted read
 * methods use to cancel it.
 *
 * It also pins the less obvious half: Connection::select() falls back to
 * mysqli::query() when the params array is EMPTY, so the same helper is
 * string-typed or native-typed depending on whether the query happened to carry
 * a placeholder.
 *
 * Usage:  php tests/db-typed-rows.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root — the throwaway container)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_typed_rows');

$locale   = seed_locale($admin);
$country  = seed_country($admin);
seed_currency($admin);
$regionId = seed_region($admin, $country, 'Alpha');
$catId    = seed_category($admin, 'Motors', null, $locale);
$userId   = seed_user($admin, 'tester', 'tester@example.test');
$itemId   = seed_item($admin, $catId, $userId, 'A listing', 19.50, 1, 1, $locale, $country);

$prefix = DB_TABLE_PREFIX;

/* A row deliberately spanning the interesting column types:
 *   pk_i_id            INT UNSIGNED
 *   i_price            BIGINT
 *   f_price            FLOAT
 *   b_active           TINYINT(1)
 *   s_contact_email    VARCHAR
 *   dt_pub_date        DATETIME
 *   s_contact_phone    VARCHAR, NULL in the fixture
 */
$columns = 'pk_i_id, i_price, f_price, b_active, s_contact_email, dt_pub_date, s_contact_phone';
$sql     = "SELECT $columns FROM {$prefix}t_item WHERE pk_i_id = ";

/* ----------------------------------------------------------------------------
 * 1. Legacy path — mysqli::query() through DBCommandClass.
 * ------------------------------------------------------------------------- */
harness_section('1. Legacy path (DBCommandClass::query)');

// DBCommandClass takes its handle by reference, so it needs a variable rather
// than a method return value.
$handle    = DBConnectionClass::newInstance()->getOsclassDb();
$legacy    = new DBCommandClass($handle);
$legacyRs  = $legacy->query($sql . (int)$itemId);
$legacyRow = $legacyRs ? $legacyRs->row() : array();

check('legacy query returned a row', $legacyRow !== array() && isset($legacyRow['pk_i_id']));
check('legacy row: every value is a string or null', all_values_string($legacyRow), describe($legacyRow));
pin('legacy INT column is a string', 'string', gettype($legacyRow['pk_i_id']));
pin('legacy BIGINT column is a string', 'string', gettype($legacyRow['i_price']));
pin('legacy FLOAT column is a string', 'string', gettype($legacyRow['f_price']));
pin('legacy TINYINT column is a string', 'string', gettype($legacyRow['b_active']));
pin('legacy NULL column stays null', 'NULL', gettype($legacyRow['s_contact_phone']));

/* ----------------------------------------------------------------------------
 * 2. New path WITH params — the prepared-statement branch.
 * ------------------------------------------------------------------------- */
harness_section('2. Parameterized path, non-empty params (prepared)');

$preparedRow = osc_db_select_one($sql . '?', array($itemId));

check('prepared query returned a row', is_array($preparedRow) && isset($preparedRow['pk_i_id']));
check(
    'prepared row is NOT all-strings (this is the divergence)',
    !all_values_string($preparedRow),
    describe($preparedRow)
);
pin('prepared INT column is an int', 'integer', gettype($preparedRow['pk_i_id']));
pin('prepared BIGINT column is an int', 'integer', gettype($preparedRow['i_price']));
pin('prepared FLOAT column is a float', 'double', gettype($preparedRow['f_price']));
pin('prepared TINYINT column is an int', 'integer', gettype($preparedRow['b_active']));
pin('prepared VARCHAR column stays a string', 'string', gettype($preparedRow['s_contact_email']));
pin('prepared DATETIME column stays a string', 'string', gettype($preparedRow['dt_pub_date']));
pin('prepared NULL column stays null', 'NULL', gettype($preparedRow['s_contact_phone']));

/* DECIMAL is the exception worth knowing: the driver hands it back as a string,
 * so a DECIMAL column needs no stringify treatment. */
$decRow = osc_db_select_one(
    "SELECT d_coord_lat FROM {$prefix}t_user WHERE pk_i_id = ?",
    array($userId)
);
pin('prepared DECIMAL column is a string, not a float', 'string', gettype($decRow['d_coord_lat']));

/* ----------------------------------------------------------------------------
 * 3. New path with EMPTY params — falls back to mysqli::query().
 * ------------------------------------------------------------------------- */
harness_section('3. Parameterized path, EMPTY params (query fallback)');

$unparamRow = osc_db_select_one($sql . (int)$itemId);

check('empty-params query returned a row', is_array($unparamRow) && isset($unparamRow['pk_i_id']));
check(
    'empty-params row IS all-strings — same helper, different typing',
    all_values_string($unparamRow),
    describe($unparamRow)
);
pin('empty-params INT column is a string', 'string', gettype($unparamRow['pk_i_id']));
pin('empty-params row equals the legacy row exactly', $legacyRow, $unparamRow);

/* ----------------------------------------------------------------------------
 * 4. The countermeasure — osc_db_stringify_row()/_rows().
 * ------------------------------------------------------------------------- */
harness_section('4. osc_db_stringify_row / osc_db_stringify_rows');

$fixed = osc_db_stringify_row($preparedRow);

check('stringified row: every value is a string or null', all_values_string($fixed), describe($fixed));
pin('stringified INT matches the legacy string', $legacyRow['pk_i_id'], $fixed['pk_i_id']);
pin('stringified BIGINT matches the legacy string', $legacyRow['i_price'], $fixed['i_price']);
pin('stringified TINYINT matches the legacy string', $legacyRow['b_active'], $fixed['b_active']);
pin('stringify preserves null', null, $fixed['s_contact_phone']);
pin('stringify leaves an existing string untouched', $legacyRow['s_contact_email'], $fixed['s_contact_email']);

/* The documented fidelity limit: a FLOAT arrives already widened to a PHP float,
 * so the lexical form MySQL would have rendered is not recoverable. Pinned so the
 * limitation is a known, tested fact rather than a surprise during a conversion. */
harness_section('4b. FLOAT fidelity limit (documented, not a defect)');

$floatMatches = $fixed['f_price'] === $legacyRow['f_price'];
check(
    'FLOAT round-trips to the same string as legacy '
    . '(informational: legacy="' . $legacyRow['f_price'] . '", stringified="' . $fixed['f_price'] . '")',
    $floatMatches,
    'known limit — use an explicit CAST when a caller depends on the rendered form'
);
check('FLOAT is at least numerically equal after stringify', (float)$fixed['f_price'] === (float)$legacyRow['f_price']);

/* Whole-list helper, including the empty case. */
$preparedRows = osc_db_select("SELECT $columns FROM {$prefix}t_item WHERE pk_i_id >= ?", array($itemId));
check('rows helper: input was not all-strings', !all_rows_string($preparedRows));
check('rows helper: output is all-strings', all_rows_string(osc_db_stringify_rows($preparedRows)));
pin('rows helper on an empty list returns an empty list', array(), osc_db_stringify_rows(array()));

/* Booleans map to MySQL's rendering, not PHP's (string)false === ''. */
pin('stringify maps bool true to "1"', array('x' => '1'), osc_db_stringify_row(array('x' => true)));
pin('stringify maps bool false to "0", not ""', array('x' => '0'), osc_db_stringify_row(array('x' => false)));

/* ----------------------------------------------------------------------------
 * 5. The query-count probe used for N+1 proofs.
 * ------------------------------------------------------------------------- */
harness_section('5. harness_query_count() self-calibration');

$idle = harness_query_count(static function () {
});
pin('an empty callable costs 0 queries', 0, $idle);

$oneLegacy = harness_query_count(static function () use ($legacy, $sql, $itemId) {
    $legacy->query($sql . (int)$itemId);
});
pin('one legacy query counts as 1', 1, $oneLegacy);

/* The counter treats a prepared statement as ONE statement: MySQL's Questions
 * status variable excludes COM_STMT_PREPARE, counting only the execute. So a
 * converted method costs the same number of Questions as the legacy one it
 * replaced, and an N+1 assertion can pin an absolute count rather than only a
 * growth rate. Pinned because the whole N+1 proof mechanism rests on it. */
$onePrepared = harness_query_count(static function () use ($sql, $itemId) {
    osc_db_select_one($sql . '?', array($itemId));
});
pin('one prepared query also counts as 1 (COM_STMT_PREPARE is not counted)', 1, $onePrepared);
pin('legacy and prepared cost the same, so counts compare across a conversion', $oneLegacy, $onePrepared);

$threePrepared = harness_query_count(static function () use ($sql, $itemId) {
    for ($i = 0; $i < 3; $i++) {
        osc_db_select_one($sql . '?', array($itemId));
    }
});
pin('three prepared queries count as 3', 3, $threePrepared);

/* The N+1 assertion helper itself: a batched read is flat across fixture sizes,
 * a per-row read is not. Both directions are exercised so the helper is known to
 * be able to fail, not just to pass. */
$batched = static function (int $n) use ($prefix, $itemId) {
    osc_db_select("SELECT pk_i_id FROM {$prefix}t_item WHERE pk_i_id >= ? LIMIT $n", array($itemId));
};
harness_assert_no_n_plus_1('a batched read is flat across fixture sizes', $batched, 2, 8);

$perRow = static function (int $n) use ($prefix, $itemId) {
    for ($i = 0; $i < $n; $i++) {
        osc_db_select_one("SELECT pk_i_id FROM {$prefix}t_item WHERE pk_i_id = ?", array($itemId));
    }
};
$qSmall = harness_query_count(static function () use ($perRow) {
    $perRow(2);
});
$qLarge = harness_query_count(static function () use ($perRow) {
    $perRow(8);
});
check(
    'the N+1 detector can actually fail: a per-row read scales with n '
    . "(n=2 -> $qSmall, n=8 -> $qLarge)",
    $qSmall === 2 && $qLarge === 8
);

exit(harness_result());

/* file end: ./tests/db-typed-rows.php */
