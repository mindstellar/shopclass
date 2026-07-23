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
 * Characterization pins for the ItemStats model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * increase()/getViews()/getAllViews() move to the parameterized query layer.
 *
 * increase() is already a single INSERT ... ON DUPLICATE KEY UPDATE statement in
 * the legacy body (unlike AlertsStats/UserEmailTmp, there is no separate
 * insert-then-update dance to collapse) — the conversion is a straight
 * value-substitution of the existing SQL text.
 *
 * getViews()/getAllViews() are SUM(...) aggregates with no GROUP BY, so they
 * always return exactly one row even when nothing matches — the aggregate is
 * SQL NULL in that case, not an empty result and not 0. The zero-row-match case
 * (NULL) and the SQL-error case (0, via the `!$result` fallback) are pinned as
 * two genuinely different values.
 *
 * getViews(null) is the one place those two branches actually diverge in a way
 * the null-where correction warns about: DBCommandClass::_where() only appends
 * a value when it is non-null, so where('fk_i_item_id', null) emits a bare
 * "fk_i_item_id =" with no right-hand side — a SQL syntax error. get() then
 * returns false and getViews()'s own fallback returns int 0. A bound `= ?`
 * with a null parameter does NOT reproduce this: it is valid SQL that matches
 * zero rows and yields SUM = NULL, not 0 (verified empirically against both
 * the legacy body and the new query layer directly before writing this pin).
 * The converted method therefore needs an explicit null guard reproducing 0,
 * not just a parameter binding.
 *
 * emptyRow() has no query text of its own — it hands its values straight to
 * DAO::insert(), the base method, out of scope for this effort. Pinned here as
 * a regression guard only, not converted.
 *
 * Usage:  php tests/models/itemstats.php          (standalone, own scratch database)
 *         php tests/run-models.php itemstats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_itemstats');
$table = DB_TABLE_PREFIX . 't_item_stats';

$model = ItemStats::newInstance();

$catId = seed_category($admin, 'Motors');
$itemA = seed_item($admin, $catId, null, 'Item A');
$itemB = seed_item($admin, $catId, null, 'Item B');

/** Row count in t_item_stats, read with raw mysqli. */
$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/**
 * A single item's stats row for CURDATE(), read with raw mysqli.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string legacy row it is meant to check against (the
 * trap is in the test, not the model — see the harness's typed-row notes).
 * $itemId is a fixture id this file controls, never external input.
 */
$rowFor = static function (int $itemId) use ($admin, $table): ?array {
    $row = $admin->query("SELECT * FROM $table WHERE fk_i_item_id = $itemId AND dt_date = CURDATE()")->fetch_assoc();

    return $row ?: null;
};

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('ItemStats: public surface');

pin(
    'increase signature is unchanged',
    'public increase($column, $itemId)',
    harness_method_signature('ItemStats', 'increase')
);
pin('emptyRow signature is unchanged', 'public emptyRow($itemId)', harness_method_signature('ItemStats', 'emptyRow'));
pin('getViews signature is unchanged', 'public getViews($itemId)', harness_method_signature('ItemStats', 'getViews'));
pin('getAllViews signature is unchanged', 'public getAllViews()', harness_method_signature('ItemStats', 'getAllViews'));
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('ItemStats', 'newInstance')
);
check('ItemStats still extends DAO', is_subclass_of('ItemStats', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_i_item_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array(
        'fk_i_item_id',
        'i_num_views',
        'i_num_spam',
        'i_num_repeated',
        'i_num_bad_classified',
        'i_num_offensive',
        'i_num_expired',
        'i_num_premium_views',
        'dt_date',
    ),
    $model->getFields()
);
pin(
    'the model declares exactly these five methods of its own',
    array('__construct', 'emptyRow', 'getAllViews', 'getViews', 'increase', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('ItemStats')),
        array('__construct', 'emptyRow', 'getAllViews', 'getViews', 'increase', 'newInstance')
    ))
);

/* seed_item() writes its own stats row per item (CURDATE(), all counters 0) —
 * clear it so every section below starts from a known, empty table. */
$truncate();

/* ----------------------------------------------------------------------------
 * increase() — rejected input, before any query is issued.
 * ------------------------------------------------------------------------- */
harness_section('increase — rejected column');

pin('the primary key column is rejected', false, $model->increase('fk_i_item_id', $itemA));
pin('the date column is rejected', false, $model->increase('dt_date', $itemA));
pin('an unknown column is rejected', false, $model->increase('not_a_column', $itemA));
pin('the empty string column is rejected', false, $model->increase('', $itemA));
pin('nothing was written by any rejected column', 0, $rowCount());
pin('a rejected column costs no queries at all', 0, harness_query_count(static function () use ($model, $itemA) {
    $model->increase('not_a_column', $itemA);
}));

harness_section('increase — rejected item id');

pin('a non-numeric item id is rejected', false, $model->increase('i_num_views', 'abc'));
pin('a null item id is rejected', false, $model->increase('i_num_views', null));
pin('the empty string item id is rejected', false, $model->increase('i_num_views', ''));
pin('nothing was written by any rejected item id', 0, $rowCount());
pin('a rejected item id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increase('i_num_views', 'abc');
}));

/* ----------------------------------------------------------------------------
 * increase() — a well-formed call whose item id does not exist. t_item_stats
 * carries a FOREIGN KEY on fk_i_item_id, so the single INSERT ... ON DUPLICATE
 * KEY UPDATE statement fails at the database, and query()'s own false-on-error
 * path is what increase() returns — the same path a genuine SQL error takes.
 * ------------------------------------------------------------------------- */
harness_section('increase — well-formed call, unknown item id');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->increase('i_num_views', 999999);
error_reporting($prevLevel);

pin('an unknown item id returns bool false', false, $ret);
pin('nothing was written for it', 0, $rowCount());
pin('the failed statement still costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $model->increase('i_num_views', 999999);
    error_reporting($prev);
}));

/* ----------------------------------------------------------------------------
 * increase() — the counter itself.
 * ------------------------------------------------------------------------- */
harness_section('increase — first call for an item');

pin('a fresh item/day returns bool true', true, $model->increase('i_num_views', $itemA));
$row = $rowFor($itemA);
check('the row was created', is_array($row));
pin('i_num_views started at 1', '1', $row['i_num_views']);
pin('every other counter stayed at its default 0', '0', $row['i_num_spam']);
pin("dt_date is today's date", date('Y-m-d'), $row['dt_date']);
pin('exactly one row exists', 1, $rowCount());

harness_section('increase — subsequent calls, same column');

pin('a repeat call also returns bool true', true, $model->increase('i_num_views', $itemA));
pin('the counter incremented to 2', '2', $rowFor($itemA)['i_num_views']);
pin('still exactly one row — it incremented, it did not duplicate', 1, $rowCount());

harness_section('increase — a different column, same item/day');

pin('a different column also returns bool true', true, $model->increase('i_num_spam', $itemA));
$row = $rowFor($itemA);
pin('the new column started at 1', '1', $row['i_num_spam']);
pin('the earlier column is untouched', '2', $row['i_num_views']);
pin('still one composite row for this item/day', 1, $rowCount());

harness_section('increase — the duplicated allowlist entry (i_num_expired) still works');

pin('i_num_expired is accepted', true, $model->increase('i_num_expired', $itemA));
pin('i_num_expired started at 1', '1', $rowFor($itemA)['i_num_expired']);

harness_section('increase — a second item is independent');

pin('a different item is a fresh insert again', true, $model->increase('i_num_views', $itemB));
pin('the new item counter starts at 1', '1', $rowFor($itemB)['i_num_views']);
pin('the first item is untouched', '2', $rowFor($itemA)['i_num_views']);
pin('both rows now exist', 2, $rowCount());

harness_section('increase — query cost');

pin('a fresh insert costs exactly one query', 1, harness_query_count(static function () use ($model, $itemB) {
    $model->increase('i_num_premium_views', $itemB);
}));
pin('an increment costs exactly one query', 1, harness_query_count(static function () use ($model, $itemB) {
    $model->increase('i_num_premium_views', $itemB);
}));

/* ----------------------------------------------------------------------------
 * getViews() — the return ledger. SUM() with no GROUP BY always returns one
 * row, so "no matching rows" and "a matching row summing to 0" are genuinely
 * different observable results (NULL vs the string "0").
 * ------------------------------------------------------------------------- */
harness_section('getViews — setup: known counts per item');

$truncate();
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, CURDATE(), 5)");
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, '2020-01-01', 7)");
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date) VALUES ($itemB, CURDATE())"); // i_num_views defaults to 0

harness_section('getViews — an item with two dated rows sums across both');

pin('the sum of both dates comes back as the string "12"', '12', $model->getViews($itemA));

harness_section('getViews — an item with a row whose count is genuinely 0');

pin('a real zero-count row returns the string "0", not null', '0', $model->getViews($itemB));

harness_section('getViews — an item with no stats row at all');

pin('no matching row returns null, not 0 and not the string "0"', null, $model->getViews(999999));

harness_section('getViews — a non-numeric item id matches nothing (no SQL error)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->getViews('abc');
error_reporting($prevLevel);
pin("a non-numeric item id also returns null, it does not error", null, $ret);

harness_section('getViews — a null item id (the null-where divergence)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->getViews(null);
error_reporting($prevLevel);
pin('a null item id returns int 0, NOT null (this is the SQL-error fallback, not a zero-row match)', 0, $ret);

harness_section('getViews — query cost');

pin('a matching lookup costs exactly one query', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->getViews($itemA);
}));
$nullCost = harness_query_count(static function () use ($model) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $model->getViews(null);
    error_reporting($prev);
});
check('a null item id costs no more than the legacy one failed query (' . $nullCost . ')', $nullCost <= 1);

/* ----------------------------------------------------------------------------
 * getAllViews() — same aggregate shape, no WHERE at all.
 * ------------------------------------------------------------------------- */
harness_section('getAllViews — empty table');

$truncate();
pin('an empty table returns null, not 0', null, $model->getAllViews());

harness_section('getAllViews — sums across every item and every date');

$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, CURDATE(), 5)");
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemB, CURDATE(), 10)");
pin('the total across two items is the string "15"', '15', $model->getAllViews());

$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, '2020-01-01', 3)");
pin('a third row (same item, different date) is included too: "18"', '18', $model->getAllViews());

harness_section('getAllViews — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->getAllViews();
}));

/* ----------------------------------------------------------------------------
 * emptyRow() — pinned as a regression guard only; it delegates straight to
 * DAO::insert(), the base method, which is out of scope for this effort.
 * ------------------------------------------------------------------------- */
harness_section('emptyRow — regression guard (not converted)');

$truncate();
pin('a fresh item/day insert returns bool true', true, $model->emptyRow($itemA));
$row = $rowFor($itemA);
check('the row was created', is_array($row));
pin('i_num_views defaults to 0', '0', $row['i_num_views']);

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->emptyRow($itemA);
error_reporting($prevLevel);
pin('a duplicate item/day insert returns bool false (composite PK collision)', false, $ret);
pin('still exactly one row — the duplicate did not land', 1, $rowCount());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemstats.php */
