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
 * Characterization pins for the LatestSearches model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer.
 *
 * getSearches()/getSearchesByDate() select a raw "d_date, s_search,
 * COUNT(s_search) as i_total" list with a comma-separated column string the new
 * builder's identifier allowlist would reject, so the conversion keeps hand
 * written SQL rather than a builder chain.
 *
 * getSearchesByDate()'s name and docblock ("given since time") both suggest a
 * range comparison, but the body does `where('d_date', $formatted)` -- an EXACT
 * equality, not `>=`. Observed, not assumed: with any realistic $time value this
 * matches nothing at all, which is what the "default $time" pin below shows.
 *
 * purgeNumber()'s single call to `$this->dao->limit($number, 1)` is the one
 * legacy two-argument limit() site in this model. DBCommandClass's own
 * "aLimit"/"aOffset" property names are misleading: with two arguments the
 * FIRST becomes the emitted clause's offset and the SECOND its row count
 * (`_getSelect()` emits the literal text "LIMIT $number, 1", and MySQL's comma
 * form of LIMIT is offset-then-count) -- so $number is the OFFSET here, not a
 * row count, despite the parameter name. Two further legacy gates ride along:
 * a non-numeric $number disables the whole clause (the query runs unbounded --
 * `is_numeric()` gates emission in DBCommandClass::limit()/_getSelect()), and a
 * negative numeric $number compiles an invalid clause, the query fails, and
 * purgeNumber()'s own next line calls ->row() on that failed (bool) result
 * BEFORE its "if ($result == false)" check -- an uncaught PHP Error, not a
 * graceful false. All three are pinned below with a count != offset fixture so
 * an accidental argument swap fails loudly rather than passing by coincidence.
 * The second slot of that legacy call is a literal `1`, always > 0, so the
 * "second value only emitted when > 0" gate never actually triggers for this
 * model -- noted, not pinned, since there is no input that reaches it.
 *
 * Usage:  php tests/models/latestsearches.php      (standalone, own scratch database)
 *         php tests/run-models.php latestsearches  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_latestsearches');
$table = DB_TABLE_PREFIX . 't_latest_searches';

/**
 * t_latest_searches has no primary key and no seed helper in scratchdb.php, so
 * it lives here, in the style of PluginCategory's/CityArea's own local seeds.
 */
$seedSearch = static function (string $date, string $term) use ($admin, $table): void {
    seed_exec($admin, "INSERT INTO $table (d_date, s_search) VALUES (?, ?)", 'ss', array($date, $term));
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$clearTable = static function () use ($admin, $table): void {
    $admin->query("DELETE FROM $table");
};

$model = LatestSearches::newInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('LatestSearches: public surface');

pin(
    'getSearches signature is unchanged',
    'public getSearches($limit = 20)',
    harness_method_signature('LatestSearches', 'getSearches')
);
pin(
    'getSearchesByDate signature is unchanged',
    'public getSearchesByDate($time = NULL, $limit = 20)',
    harness_method_signature('LatestSearches', 'getSearchesByDate')
);
pin(
    'purgeNumber signature is unchanged',
    'public purgeNumber($number = NULL)',
    harness_method_signature('LatestSearches', 'purgeNumber')
);
pin(
    'purgeDate signature is unchanged',
    'public purgeDate($date = NULL)',
    harness_method_signature('LatestSearches', 'purgeDate')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('LatestSearches', 'newInstance')
);
check('LatestSearches still extends DAO', is_subclass_of('LatestSearches', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('no primary key was ever set', null, $model->getPrimaryKey());
pin('field allowlist is unchanged', array('d_date', 's_search'), $model->getFields());
pin(
    'the model adds exactly these methods of its own',
    array('__construct', 'getSearches', 'getSearchesByDate', 'newInstance', 'purgeDate', 'purgeNumber'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('LatestSearches')),
        array('__construct', 'newInstance', 'getSearches', 'getSearchesByDate', 'purgeNumber', 'purgeDate')
    ))
);

/* ----------------------------------------------------------------------------
 * getSearches() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('LatestSearches::getSearches — empty table');

pin('no rows at all returns an empty array, not false', array(), $model->getSearches(20));

harness_section('LatestSearches::getSearches — grouped match');

$seedSearch('2026-01-01 00:00:00', 'car');
$seedSearch('2026-01-02 00:00:00', 'car');
$seedSearch('2026-01-03 00:00:00', 'bike');

$rows = $model->getSearches(20);
check('three inserted rows collapse into two groups', is_array($rows) && count($rows) === 2, describe($rows));
pin('each row carries exactly the three selected columns', array('d_date', 's_search', 'i_total'), array_keys($rows[0]));
check('every value in every row is a string or null (C4), including the COUNT() aggregate', all_rows_string($rows), describe($rows));
pin('most recent group (by its own d_date) sorts first', 'bike', $rows[0]['s_search']);
pin('the repeated search term sorts second', 'car', $rows[1]['s_search']);
pin('its COUNT(s_search) is 2, as a string not an int', '2', $rows[1]['i_total']);

harness_section('LatestSearches::getSearches — LIMIT 0 is a valid, empty result');

pin('a zero limit returns an empty array, not false', array(), $model->getSearches(0));

harness_section('LatestSearches::getSearches — bounding, and the non-numeric/negative gates');

$seedSearch('2026-01-04 00:00:00', 'truck');
$seedSearch('2026-01-05 00:00:00', 'plane');
// Five distinct groups now exist: car, bike, truck, plane (4), the table has
// 5 rows total.

$rows = $model->getSearches(2);
pin('a numeric limit smaller than the group count bounds the result', 2, count($rows));
pin('the bounded result keeps the most recent groups first', 'plane', $rows[0]['s_search']);

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin(
    'a non-numeric limit disables the clause entirely -- ALL groups come back, not zero',
    4,
    count($model->getSearches('not-a-number'))
);
pin(
    'null (the same non-numeric gate) also returns every group',
    4,
    count($model->getSearches(null))
);
pin(
    'a negative numeric limit compiles an invalid LIMIT and is absorbed into false, not a crash',
    false,
    $model->getSearches(-1)
);
error_reporting($prevLevel);

harness_section('LatestSearches::getSearches — query cost');

pin('one getSearches() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->getSearches(20);
}));

/* ----------------------------------------------------------------------------
 * getSearchesByDate() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('LatestSearches::getSearchesByDate — exact-match semantics (not a range)');

$rows = $model->getSearchesByDate(strtotime('2026-01-03 00:00:00'), 20);
pin('an exact timestamp match returns that one group', 1, count($rows));
pin('it is the matching group', 'bike', $rows[0]['s_search']);
check('every value is a string or null (C4)', all_rows_string($rows), describe($rows));

pin(
    'a timestamp with no exact d_date match returns an empty array, not the whole table',
    array(),
    $model->getSearchesByDate(strtotime('2026-01-03 12:00:00'), 20)
);

harness_section('LatestSearches::getSearchesByDate — default $time (now - 7 days) matches nothing here');

pin(
    'the default "since" time is a real timestamp, not one of the seeded rows, so it returns empty',
    array(),
    $model->getSearchesByDate(null, 20)
);

harness_section('LatestSearches::getSearchesByDate — limit gates apply on top of the WHERE match');

pin(
    'LIMIT 0 excludes an otherwise-matching row',
    array(),
    $model->getSearchesByDate(strtotime('2026-01-03 00:00:00'), 0)
);

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin(
    'a negative limit is absorbed into false here too',
    false,
    $model->getSearchesByDate(strtotime('2026-01-03 00:00:00'), -1)
);
error_reporting($prevLevel);

harness_section('LatestSearches::getSearchesByDate — query cost');

pin('one getSearchesByDate() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->getSearchesByDate(strtotime('2026-01-03 00:00:00'), 20);
}));

check('nothing written by any read pin above', $rowCount() === 5, (string) $rowCount());

/* ----------------------------------------------------------------------------
 * purgeNumber() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('LatestSearches::purgeNumber — null/zero short-circuit before any query');

pin('null returns false without querying', false, $model->purgeNumber(null));
pin('0 (loosely == null) also returns false without querying', false, $model->purgeNumber(0));
pin('the short-circuit costs zero queries', 0, harness_query_count(static function () use ($model) {
    $model->purgeNumber(null);
}));
check('the table is untouched', $rowCount() === 5, (string) $rowCount());

harness_section('LatestSearches::purgeNumber — count != offset (the inversion trap)');

// purgeNumber()'s own query GROUPs BY s_search, so this fixture uses five
// DISTINCT search terms -- a repeated term (as used above, to pin the
// COUNT() aggregate) would collapse into one group and make the boundary
// row depend on which of its dates MySQL happens to pick as the group's
// representative, which is not guaranteed. Five groups ordered by d_date
// DESC: five, four, three, two, one. purgeNumber(3) must skip 3 (OFFSET 3)
// and take 1 (COUNT 1): the boundary row is "two" @ 2026-01-02. purgeDate()
// then removes everything <= that date -- "one" and "two" -- leaving three
// rows. A swapped mapping (COUNT 3, OFFSET 1) would instead land on "four"
// @ 2026-01-04 and delete 4 rows, which this distinguishes unambiguously.
$clearTable();
$seedSearch('2026-01-01 00:00:00', 'one');
$seedSearch('2026-01-02 00:00:00', 'two');
$seedSearch('2026-01-03 00:00:00', 'three');
$seedSearch('2026-01-04 00:00:00', 'four');
$seedSearch('2026-01-05 00:00:00', 'five');

pin('purgeNumber(3) deletes exactly the two oldest rows', 2, $model->purgeNumber(3));
pin('three rows remain', 3, $rowCount());
$remaining = array();
$res = $admin->query("SELECT s_search FROM $table ORDER BY d_date");
while ($r = $res->fetch_assoc()) {
    $remaining[] = $r['s_search'];
}
pin('the three most recent groups are exactly what is left', array('three', 'four', 'five'), $remaining);

harness_section('LatestSearches::purgeNumber — non-numeric $number disables the clause (unbounded, deletes all)');

$clearTable();
$seedSearch('2026-01-01 00:00:00', 'a');
$seedSearch('2026-01-02 00:00:00', 'b');
$seedSearch('2026-01-03 00:00:00', 'c');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a non-numeric $number runs the query unbounded and purges everything', 3, $model->purgeNumber('not-a-number'));
error_reporting($prevLevel);
pin('the table is now empty', 0, $rowCount());

harness_section('LatestSearches::purgeNumber — offset beyond the available groups');

$seedSearch('2026-01-01 00:00:00', 'a');
pin('an offset past every group finds nothing and returns false', false, $model->purgeNumber(5));
check('the table is untouched', $rowCount() === 1, (string) $rowCount());

harness_section('LatestSearches::purgeNumber — negative $number crashes rather than silently matching');

// Legacy: the invalid "LIMIT -1, 1" clause fails the query, and purgeNumber()
// reads ->row() off that failed (bool) result before its own false-check,
// raising an uncaught PHP Error. The converted code must not let the new
// offset()'s negative-clamps-to-zero behaviour turn this into a silent
// success against row 0 -- some Throwable must still escape. The exact
// class necessarily differs (Error before conversion, DbException after),
// so only "did something throw" is pinned, not which class.
$threw = false;
try {
    $model->purgeNumber(-1);
} catch (\Throwable $e) {
    $threw = true;
}
pin('a negative $number throws rather than returning a value', true, $threw);
check('nothing was deleted by the crashed call', $rowCount() === 1, (string) $rowCount());

harness_section('LatestSearches::purgeNumber — query cost on the success path');

$clearTable();
$seedSearch('2026-01-01 00:00:00', 'a');
$seedSearch('2026-01-02 00:00:00', 'b');
pin(
    'a successful purgeNumber() costs two queries (the boundary select, then the delete)',
    2,
    harness_query_count(static function () use ($model) {
        $model->purgeNumber(1);
    })
);

/* ----------------------------------------------------------------------------
 * purgeDate() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('LatestSearches::purgeDate — null short-circuit');

pin('null returns false without querying', false, $model->purgeDate(null));
pin('the short-circuit costs zero queries', 0, harness_query_count(static function () use ($model) {
    $model->purgeDate(null);
}));

harness_section('LatestSearches::purgeDate — deletes matching and older rows');

$clearTable();
$seedSearch('2026-01-01 00:00:00', 'a');
$seedSearch('2026-01-02 00:00:00', 'b');
$seedSearch('2026-01-03 00:00:00', 'c');

pin('purgeDate deletes the boundary row and everything older', 2, $model->purgeDate('2026-01-02 00:00:00'));
pin('one row remains', 1, $rowCount());

harness_section('LatestSearches::purgeDate — no match');

pin('a date older than everything left deletes nothing, returning 0 (not false)', 0, $model->purgeDate('1900-01-01 00:00:00'));
check('the remaining row is untouched', $rowCount() === 1, (string) $rowCount());

harness_section('LatestSearches::purgeDate — query cost');

pin('one purgeDate() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->purgeDate('1900-01-01 00:00:00');
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/latestsearches.php */
