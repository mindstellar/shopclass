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
 * Characterization pins for the KeywordBlock model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * search()/countKeywords() move to the parameterized query layer.
 *
 * search() carries two quirks worth stating up front, both observed rather than
 * assumed:
 *
 *  - 'total_results' and 'rows' start life as PHP int 0 and only ever become a
 *    string: DBCommandClass::query()'s row values are already strings, and the
 *    model overwrites the int default with that string ONLY when it is truthy
 *    (`if ($data['total'])`), so a genuinely empty count is pinned as int(0)
 *    while any nonzero count is pinned as a string.
 *  - the LIKE filter is built through DBCommandClass::escapeStr($v, true), which
 *    escapes '%' and '_' in the payload before it ever reaches the query — a
 *    literal percent or underscore typed into the admin search box is NOT a
 *    wildcard. That is the compat default this test locks in.
 *  - $start/$end reach DBCommandClass::limit($start, $end) directly (not
 *    $count/$offset order): limit()'s own aLimit/aOffset compile to the literal
 *    text "LIMIT $start, $end", which MySQL's two-argument form reads as
 *    OFFSET=$start, ROW_COUNT=$end — confirmed against
 *    KeywordBlocksDataTable::getDBParams(), which computes $start as
 *    (page-1)*length and passes it first. A non-numeric $start disables the
 *    whole LIMIT clause (DBCommandClass::limit() never sets aLimit, and
 *    _getSelect() gates the entire LIMIT/OFFSET block behind
 *    is_numeric($aLimit)), so every matching row comes back regardless of $end.
 *
 * Usage:  php tests/models/keywordblock.php          (standalone, own scratch database)
 *         php tests/run-models.php keywordblock      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_keywordblock');

$model = KeywordBlock::newInstance();
$table = DB_TABLE_PREFIX . 't_keyword_block';

/**
 * t_keyword_block has no seed helper in scratchdb.php, so it lives here as a
 * local closure, raw mysqli, never through the code under test.
 *
 * @return int The id just inserted
 */
$seedKeyword = static function (
    string $keyword,
    string $scope = 'all',
    int $substring = 0,
    string $date = '2026-01-01 00:00:00'
) use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table (s_keyword, s_scope, b_substring, dt_date) VALUES (?, ?, ?, ?)",
        'ssis',
        array($keyword, $scope, $substring, $date)
    );
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock: public surface');

pin(
    'search signature is unchanged',
    "public search(\$start = 0, \$end = 10, \$order_column = 'pk_i_id', \$order_direction = 'DESC', \$keyword = '')",
    harness_method_signature('KeywordBlock', 'search')
);
pin('countKeywords signature is unchanged', 'public countKeywords()', harness_method_signature('KeywordBlock', 'countKeywords'));
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('KeywordBlock', 'newInstance'));
check('KeywordBlock still extends DAO', is_subclass_of('KeywordBlock', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 's_keyword', 's_scope', 'b_substring', 'dt_date'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array('__construct', 'countKeywords', 'newInstance', 'search'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('KeywordBlock')),
        array('__construct', 'newInstance', 'search', 'countKeywords')
    ))
);

/* ----------------------------------------------------------------------------
 * search() — empty table.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — empty table');

$empty = $model->search();
pin('rows is int 0 on an empty table', 0, $empty['rows']);
pin('total_results is int 0 on an empty table', 0, $empty['total_results']);
pin('keywords is an empty array', array(), $empty['keywords']);

/* ----------------------------------------------------------------------------
 * search() — basic listing, default order (pk_i_id DESC).
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — basic listing');

$idA = $seedKeyword('viagra', 'all', 0, '2026-01-01 00:00:00');
$idB = $seedKeyword('casino', 'title', 1, '2026-01-02 00:00:00');
$idC = $seedKeyword('replica', 'description', 0, '2026-01-03 00:00:00');

$list = $model->search();
check('keywords is an array', is_array($list['keywords']), describe($list['keywords']));
pin('all three rows come back', 3, count($list['keywords']));
pin('rows counts the whole table (string, C4-consistent)', '3', $list['rows']);
pin('total_results counts the matched set (string)', '3', $list['total_results']);
pin(
    'default order is pk_i_id DESC — the last inserted row comes first',
    (string) $idC,
    $list['keywords'][0]['pk_i_id']
);
pin('row carries exactly the five schema columns', array('pk_i_id', 's_keyword', 's_scope', 'b_substring', 'dt_date'), array_keys($list['keywords'][0]));
check('every value in every row is a string or null (C4)', all_rows_string($list['keywords']), describe($list['keywords']));

/* ----------------------------------------------------------------------------
 * search() — explicit ordering, ascending.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — explicit ascending order');

$asc = $model->search(0, 10, 'pk_i_id', 'ASC');
pin('ascending order puts the first inserted row first', (string) $idA, $asc['keywords'][0]['pk_i_id']);
pin('ascending order puts the last inserted row last', (string) $idC, $asc['keywords'][2]['pk_i_id']);

/* ----------------------------------------------------------------------------
 * search() — pagination, count != offset so an inversion fails loudly (§3.3).
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — pagination (start != end)');

$idD = $seedKeyword('rolex', 'all', 1, '2026-01-04 00:00:00');
$idE = $seedKeyword('pharmacy', 'meta', 0, '2026-01-05 00:00:00');
// Five rows now, ascending pk order: A, B, C, D, E.

$page = $model->search(2, 2, 'pk_i_id', 'ASC');
pin('start=2,end=2 returns exactly two rows (the count, not the offset)', 2, count($page['keywords']));
pin('the window starts at the third row (offset=2)', (string) $idC, $page['keywords'][0]['pk_i_id']);
pin('the window\'s second row is the fourth inserted row', (string) $idD, $page['keywords'][1]['pk_i_id']);
pin('total_results still reflects the full unpaginated match count', '5', $page['total_results']);
pin('rows still reflects the whole table', '5', $page['rows']);

$page2 = $model->search(4, 2, 'pk_i_id', 'ASC');
pin('start=4,end=2 returns only the one remaining row', 1, count($page2['keywords']));
pin('the last row is the fifth inserted row', (string) $idE, $page2['keywords'][0]['pk_i_id']);

harness_section('KeywordBlock::search — non-numeric start disables the LIMIT clause entirely');

/* DBCommandClass::limit() only ever sets $aLimit when is_numeric($value); a
 * non-numeric $start leaves $aLimit at its initial `false`, and _getSelect()
 * gates the WHOLE LIMIT/OFFSET clause behind is_numeric($aLimit) — so $end is
 * silently ignored too, and every matching row comes back. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$noLimit = $model->search('abc', 2, 'pk_i_id', 'ASC');
error_reporting($prevLevel);
pin('a non-numeric start returns every row, ignoring end', 5, count($noLimit['keywords']));

/* ----------------------------------------------------------------------------
 * search() — keyword filter, match / no-match.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — keyword filter, match');

$found = $model->search(0, 10, 'pk_i_id', 'ASC', 'rolex');
pin('exactly one row matches', 1, count($found['keywords']));
pin('it is the seeded rolex row', (string) $idD, $found['keywords'][0]['pk_i_id']);
pin('total_results reflects only the filtered match', '1', $found['total_results']);
pin('rows still reflects the whole table, unfiltered', '5', $found['rows']);

harness_section('KeywordBlock::search — keyword filter, no match');

$none = $model->search(0, 10, 'pk_i_id', 'ASC', 'nosuchkeyword');
pin('no rows match', array(), $none['keywords']);
pin('total_results is int 0 when the filtered count is zero', 0, $none['total_results']);
pin('rows is still a nonzero string — the unfiltered table is not empty', '5', $none['rows']);

/* ----------------------------------------------------------------------------
 * search() — LIKE wildcard preservation: a literal % or _ typed by an admin
 * stays literal (DBCommandClass::escapeStr($v, true) escapes both before the
 * query runs). This is the compat default QueryBuilder::like() also honours,
 * but is NOT what a naive '%'.$v.'%' interpolation would give.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — LIKE wildcard preservation');

$idPercent    = $seedKeyword('50% off', 'all', 0, '2026-01-06 00:00:00');
$idNoPercent  = $seedKeyword('50 percent off', 'all', 0, '2026-01-07 00:00:00');
$idUnderscore = $seedKeyword('5_bonus', 'all', 0, '2026-01-08 00:00:00');
$idAnyChar    = $seedKeyword('5xbonus', 'all', 0, '2026-01-09 00:00:00');

$percentSearch = $model->search(0, 10, 'pk_i_id', 'ASC', '50%');
pin(
    'a literal "%" in the keyword matches only the row containing a literal percent sign',
    array((string) $idPercent),
    array_column($percentSearch['keywords'], 'pk_i_id')
);

$underscoreSearch = $model->search(0, 10, 'pk_i_id', 'ASC', '5_bonus');
pin(
    'a literal "_" in the keyword does not act as a single-character wildcard',
    array((string) $idUnderscore),
    array_column($underscoreSearch['keywords'], 'pk_i_id')
);

/* ----------------------------------------------------------------------------
 * search() — malformed order_column falls back to pk_i_id.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — order_column outside the allowlist falls back');

$bogusColumn = $model->search(0, 3, 'pk_i_id; DROP TABLE ' . $table . ' -- ', 'ASC');
check('an invalid order_column does not raise or corrupt the query', is_array($bogusColumn['keywords']));
pin('the fallback orders by pk_i_id ascending — first row is the first inserted', (string) $idA, $bogusColumn['keywords'][0]['pk_i_id']);
check('the table is untouched by the injection attempt', $rowCount() === 9, (string) $rowCount());

/* ----------------------------------------------------------------------------
 * search() — malformed order_direction falls back to ASC.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::search — order_direction outside ASC/DESC falls back to ASC');

$bogusDirection = $model->search(0, 3, 'pk_i_id', 'sideways');
pin(
    'an unrecognised direction behaves as ASC — first row is the first inserted',
    (string) $idA,
    $bogusDirection['keywords'][0]['pk_i_id']
);

/* The legacy direction check tested trim($direction) for truthiness rather than
 * comparing against '', so the one non-empty string PHP treats as falsy — '0' —
 * skipped validation entirely and was concatenated onto the column name. That
 * builds an invalid identifier, the query fails, and the caller gets the same
 * all-zero shape an empty table produces. Pinned because it is a genuinely
 * different outcome from the 'sideways' fallback directly above. */
$zeroDirection = $model->search(0, 3, 'pk_i_id', '0');
pin('a "0" direction yields the failed-query shape, not an ASC sort', array(), $zeroDirection['keywords']);
pin('a "0" direction reports no total_results', 0, $zeroDirection['total_results']);
pin('a "0" direction reports no rows', 0, $zeroDirection['rows']);

/* ----------------------------------------------------------------------------
 * countKeywords() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock::countKeywords');

pin('countKeywords returns the full row count as a native int', 9, $model->countKeywords());

/* A fresh scratch-style check: emptying the table returns 0. Truncate directly
 * with raw mysqli rather than through the model, which has no delete method. */
$admin->query("TRUNCATE TABLE $table");
pin('countKeywords returns int 0 on an empty table', 0, $model->countKeywords());

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('KeywordBlock: query cost');

$seedKeyword('cost-probe', 'all', 0, '2026-01-10 00:00:00');

pin('one search() call costs three statements (list + FOUND_ROWS + COUNT)', 3, harness_query_count(static function () use ($model) {
    $model->search();
}));
pin('one countKeywords() call costs one statement', 1, harness_query_count(static function () use ($model) {
    $model->countKeywords();
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/keywordblock.php */
