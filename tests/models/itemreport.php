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
 * Characterization pins for the ItemReport model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * log()/countReporters()/reasonBreakdown()/clear() move to the parameterized
 * query layer.
 *
 * t_item_report_log is a deduplicated abuse-report log: its PRIMARY KEY is the
 * composite (fk_i_item_id, s_reporter), so one reporter is one vote per item no
 * matter how many times they hit the button. The model never calls
 * setPrimaryKey(), so getPrimaryKey() is null — pinned below.
 *
 * The reporter identity comes from reporterKey() (private): 'u:<id>' when a web
 * user is logged in, 'ip:<REMOTE_ADDR truncated to 64>' otherwise. This test
 * drives that fork through the real osc_is_web_user_logged_in() by controlling
 * the View's _loggedUser cache — an empty array short-circuits to anonymous
 * before the Cookie/Session/User branches are reached, and a valid user array
 * makes it report logged-in. Session writes are kept in-memory (the private
 * `started` flag is forced true by reflection) so no physical PHP session is
 * started under CLI.
 *
 * The three escape() sites in the legacy body (s_reporter, s_ip, s_reason) are
 * all INSERT VALUES, never WHERE comparisons. The amendment-T coercion only
 * changes an observable result when an is_numeric() value reaches a CHAR/VARCHAR
 * *comparison*; on an INSERT VALUE a bare numeric literal and a bound string
 * store byte-identical VARCHAR content. So amendment T changes nothing here and
 * this file has no amendment-T (red-against-legacy) section — the numeric-reason
 * pin below is an ordinary characterization, green in both worlds. The only WHERE
 * clauses in the model compare fk_i_item_id, always (int)-cast before use, so a
 * null/bad argument becomes 0 and never SQL NULL: the null-where correction does
 * not apply to any method here.
 *
 * log() and clear() discard the result of their write (legacy dao->query()
 * returned false on failure and the value was never read), so a failed query was
 * absorbed into a void return. The conversion mirrors that with a swallowed
 * DbException catch. Neither method is read for affectedRows()/insertedId()/
 * getErrorLevel() anywhere (callers: hSpam.php, ItemsDataTable.php,
 * CAdminItems.php — grepped), so traps 2.2/2.3 do not apply.
 *
 * Usage:  php tests/models/itemreport.php          (standalone, own scratch database)
 *         php tests/run-models.php itemreport      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// reporterKey() calls osc_is_web_user_logged_in()/osc_logged_user_id(); load the
// real helpers rather than stub them (a stub in a test file risks a redeclare
// fatal against hUsers.php's unguarded definitions).
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hUsers.php';

$admin = scratchdb_session('osc_models_itemreport');
$table = DB_TABLE_PREFIX . 't_item_report_log';

$model = ItemReport::newInstance();

/* Keep Session writes in memory: force the singleton's private `started` flag so
 * _set() never reaches session_start() (which fatals once harness output has
 * been sent under CLI). */
$_SESSION = array();
$startedProp = new ReflectionProperty('Session', 'started');
$startedProp->setAccessible(true);
$startedProp->setValue(Session::newInstance(), true);

$view = View::newInstance();

/**
 * Report as an anonymous visitor from $ip. An empty _loggedUser short-circuits
 * osc_is_web_user_logged_in() to false before it can touch Cookie/Session.
 */
$asAnon = static function (string $ip) use ($view): void {
    $view->_exportVariableToView('_loggedUser', array());
    $_SERVER['REMOTE_ADDR'] = $ip;
    Params::init();
};

/**
 * Report as logged-in web user $uid from $ip. A valid _loggedUser makes
 * osc_is_web_user_logged_in() return true and sets the session user id.
 */
$asUser = static function (int $uid, string $ip) use ($view): void {
    $view->_exportVariableToView('_loggedUser', array(
        'pk_i_id'        => $uid,
        'b_enabled'      => 1,
        'b_active'       => 1,
        's_name'         => 'U' . $uid,
        's_email'        => 'u' . $uid . '@example.test',
        's_phone_mobile' => '',
        's_phone_land'   => '',
    ));
    $_SERVER['REMOTE_ADDR'] = $ip;
    Params::init();
};

/**
 * Every row for one item, read back with raw UNPREPARED mysqli (never through
 * the code under test). Unprepared so the verification read yields all-string
 * columns, matching what the legacy layer produced (a prepared read would return
 * native ints and disagree — the trap would be in the test, not the model).
 *
 * @return array
 */
$rowsFor = static function (int $itemId) use ($admin, $table): array {
    $res  = $admin->query("SELECT * FROM $table WHERE fk_i_item_id = $itemId ORDER BY s_reporter ASC");
    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

$catId = seed_category($admin, 'Motors');
$itemA = seed_item($admin, $catId, null, 'Listing A');
$itemB = seed_item($admin, $catId, null, 'Listing B');

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * Public + protected only (reporterKey() is private and intentionally omitted).
 * ------------------------------------------------------------------------- */
harness_section('ItemReport: public surface');

pin('log signature is unchanged', 'public log($itemId, $reason)', harness_method_signature('ItemReport', 'log'));
pin('countReporters signature is unchanged', 'public countReporters($itemId)', harness_method_signature('ItemReport', 'countReporters'));
pin('reasonBreakdown signature is unchanged', 'public reasonBreakdown($itemId)', harness_method_signature('ItemReport', 'reasonBreakdown'));
pin('clear signature is unchanged', 'public clear($itemId)', harness_method_signature('ItemReport', 'clear'));
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('ItemReport', 'newInstance'));

check('ItemReport still extends DAO', is_subclass_of('ItemReport', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged (never set: composite PK lives only in the schema)', null, $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('fk_i_item_id', 's_reporter', 'fk_i_user_id', 's_ip', 's_reason', 'dt_date'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array('__construct', 'clear', 'countReporters', 'log', 'newInstance', 'reasonBreakdown'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('ItemReport')),
        array('__construct', 'clear', 'countReporters', 'log', 'newInstance', 'reasonBreakdown')
    ))
);

/* ----------------------------------------------------------------------------
 * log() — the write. Returns nothing (void); its effect is the row.
 * ------------------------------------------------------------------------- */
harness_section('ItemReport::log — a non-positive item id is a no-op');

$truncate();
$asAnon('203.0.113.7');
pin('log(0) writes nothing and costs no query', 0, harness_query_count(static function () use ($model) {
    $model->log(0, 'spam');
}));
pin('log(-5) writes nothing and costs no query', 0, harness_query_count(static function () use ($model) {
    $model->log(-5, 'spam');
}));
pin('log(null) writes nothing and costs no query', 0, harness_query_count(static function () use ($model) {
    $model->log(null, 'spam');
}));
pin('the table is still empty', 0, $rowCount());

harness_section('ItemReport::log — one anonymous report writes one row');

$truncate();
$asAnon('203.0.113.7');
$model->log($itemA, 'spam');

$rows = $rowsFor($itemA);
check('exactly one row was written', count($rows) === 1, (string) count($rows));
$row = $rows[0] ?? array();
pin(
    'the row carries exactly the six schema columns',
    array('fk_i_item_id', 's_reporter', 'fk_i_user_id', 's_ip', 's_reason', 'dt_date'),
    array_keys($row)
);
pin('fk_i_item_id round-trips', (string) $itemA, $row['fk_i_item_id']);
pin('s_reporter is the ip: key for an anonymous reporter', 'ip:203.0.113.7', $row['s_reporter']);
pin('fk_i_user_id is SQL NULL for an anonymous reporter', null, $row['fk_i_user_id']);
pin('s_ip is the reporter address', '203.0.113.7', $row['s_ip']);
pin('s_reason is the reason as given', 'spam', $row['s_reason']);
check(
    'dt_date is populated with a real timestamp (NOW())',
    isset($row['dt_date']) && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['dt_date']),
    describe($row['dt_date'] ?? null)
);
check('every value in the row is a string or null (raw readback, C4)', all_values_string($row), describe($row));

harness_section('ItemReport::log — one report costs exactly one query');

$truncate();
$asAnon('203.0.113.7');
pin('one log() call is a single INSERT', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->log($itemA, 'spam');
}));

harness_section('ItemReport::log — a second report from the same reporter is ignored');

$truncate();
$asAnon('203.0.113.7');
$model->log($itemA, 'spam');
$model->log($itemA, 'badcat');   // same (item, ip:203.0.113.7) — dropped by INSERT IGNORE
$rows = $rowsFor($itemA);
pin('the duplicate report did not add a second row', 1, count($rows));
pin('the FIRST reason is the one that stands', 'spam', $rows[0]['s_reason']);

harness_section('ItemReport::log — a different anonymous address is a distinct reporter');

$asAnon('198.51.100.9');
$model->log($itemA, 'offensive');
$rows = $rowsFor($itemA);
pin('a second address adds a second row', 2, count($rows));
pin('ordered by reporter key, 198.51.100.9 sorts first', 'ip:198.51.100.9', $rows[0]['s_reporter']);
pin('its reason stands independently', 'offensive', $rows[0]['s_reason']);
pin('the first reporter row is untouched', 'ip:203.0.113.7', $rows[1]['s_reporter']);

harness_section('ItemReport::log — a logged-in web user reports under a u: key');

$truncate();
$asUser(42, '198.51.100.9');
$model->log($itemA, 'spam');
$rows = $rowsFor($itemA);
pin('exactly one row', 1, count($rows));
pin('s_reporter is the account key, not the address', 'u:42', $rows[0]['s_reporter']);
pin('fk_i_user_id carries the account id', '42', $rows[0]['fk_i_user_id']);
pin('s_ip still records the request address, independent of the account key', '198.51.100.9', $rows[0]['s_ip']);

harness_section('ItemReport::log — the same account is one vote from any address');

$asUser(42, '203.0.113.7');   // same user, different IP
$model->log($itemA, 'expired');
$rows = $rowsFor($itemA);
pin('the account reporting again from a new address adds no row', 1, count($rows));
pin('and the first reason still stands', 'spam', $rows[0]['s_reason']);

harness_section('ItemReport::log — text fields are truncated at the column widths');

$truncate();
$asAnon(str_repeat('a', 70));           // REMOTE_ADDR longer than 64
$model->log($itemA, str_repeat('z', 40)); // reason longer than 20
$rows = $rowsFor($itemA);
$row  = $rows[0];
pin('s_reason is truncated to 20 characters', str_repeat('z', 20), $row['s_reason']);
pin('s_ip is truncated to 64 characters', str_repeat('a', 64), $row['s_ip']);
pin('s_reporter is "ip:" + the 64-char truncated address', 'ip:' . str_repeat('a', 64), $row['s_reporter']);

harness_section('ItemReport::log — a numeric-looking reason stores identically (no escape() coercion on an INSERT value)');

/* The legacy escape() returns an is_numeric() value unquoted, but this is an
 * INSERT VALUE, not a WHERE comparison: a bare numeric literal and a bound
 * string both store the same VARCHAR content, so amendment T changes nothing
 * observable here. Green in both worlds. */
$truncate();
$asAnon('203.0.113.7');
$model->log($itemA, '12345');
$rows = $rowsFor($itemA);
pin('a numeric reason is stored as its string form', '12345', $rows[0]['s_reason']);

/* ----------------------------------------------------------------------------
 * countReporters() — DISTINCT-reporter count for one item. Always int.
 * ------------------------------------------------------------------------- */
harness_section('ItemReport::countReporters — the ledger');

$truncate();
pin('an item with no reports returns int 0', 0, $model->countReporters($itemA));
check('the zero is a genuine int, not a string (C4)', $model->countReporters($itemA) === 0, describe($model->countReporters($itemA)));

$asAnon('203.0.113.7');
$model->log($itemA, 'spam');
$asAnon('198.51.100.9');
$model->log($itemA, 'offensive');
$asUser(42, '203.0.113.7');
$model->log($itemA, 'spam');
pin('three distinct reporters count as 3', 3, $model->countReporters($itemA));

$asAnon('203.0.113.7');
$model->log($itemB, 'spam');
pin('the count is scoped to its own item', 1, $model->countReporters($itemB));

pin('a null item id counts nothing ((int)null = 0, never SQL NULL)', 0, $model->countReporters(null));
pin('an unused item id counts nothing', 0, $model->countReporters(999999));

pin('one countReporters() call is a single query', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->countReporters($itemA);
}));

/* ----------------------------------------------------------------------------
 * reasonBreakdown() — reason => distinct-reporter count. Values are int.
 * No ORDER BY, so the map is compared order-independently (ksort'd).
 * ------------------------------------------------------------------------- */
harness_section('ItemReport::reasonBreakdown — the ledger');

$truncate();
pin('an item with no reports returns an empty array, not false', array(), $model->reasonBreakdown($itemA));

$asAnon('203.0.113.7');
$model->log($itemA, 'spam');
$asAnon('198.51.100.9');
$model->log($itemA, 'offensive');
$asUser(42, '203.0.113.7');
$model->log($itemA, 'spam');

$breakdown = $model->reasonBreakdown($itemA);
ksort($breakdown);
pin('the breakdown maps each reason to its distinct-reporter count', array('offensive' => 1, 'spam' => 2), $breakdown);
check(
    'every count is a genuine int (C4)',
    array_reduce($breakdown, static fn($carry, $v) => $carry && is_int($v), true),
    describe($breakdown)
);
check(
    'every reason key is a string (C4)',
    array_reduce(array_keys($breakdown), static fn($carry, $k) => $carry && is_string($k), true),
    describe(array_keys($breakdown))
);

pin('the breakdown is scoped to its own item', array(), $model->reasonBreakdown($itemB));
pin('a null item id returns an empty array', array(), $model->reasonBreakdown(null));

pin('one reasonBreakdown() call is a single query', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->reasonBreakdown($itemA);
}));

/* ----------------------------------------------------------------------------
 * clear() — forget one item's reports. Returns nothing (void).
 * ------------------------------------------------------------------------- */
harness_section('ItemReport::clear — removes only the named item\'s reports');

$truncate();
$asAnon('203.0.113.7');
$model->log($itemA, 'spam');
$asAnon('198.51.100.9');
$model->log($itemA, 'offensive');
$asAnon('203.0.113.7');
$model->log($itemB, 'spam');

pin('two items have reports before the clear', 3, $rowCount());
$model->clear($itemA);
pin('item A has no reports after the clear', 0, $model->countReporters($itemA));
pin('item B is untouched', 1, $model->countReporters($itemB));
pin('only item A\'s rows were removed', 1, $rowCount());

pin('clearing an item with no reports is harmless and costs one query', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->clear($itemA);
}));

/* ----------------------------------------------------------------------------
 * F3 baseline: the admin reported-listings table calls countReporters() and
 * reasonBreakdown() once per row (ItemsDataTable::reportersCell). This model
 * contributes 2 aggregate queries per reported row — pinned so a later batch
 * method (F3, separate work) has a documented starting point.
 * ------------------------------------------------------------------------- */
harness_section('ItemReport: F3 per-row aggregate baseline');

$truncate();
$asAnon('203.0.113.7');
$model->log($itemA, 'spam');
pin('the two per-row aggregates cost 2 queries together (F3 baseline)', 2, harness_query_count(static function () use ($model, $itemA) {
    $model->countReporters($itemA);
    $model->reasonBreakdown($itemA);
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemreport.php */
