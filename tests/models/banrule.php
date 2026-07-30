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
 * Characterization pins for the BanRule model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * search()/countRules() move to the parameterized query layer.
 *
 * search() is a SQL_CALC_FOUND_ROWS/FOUND_ROWS() paging pattern: the main
 * SELECT, the FOUND_ROWS() read and the unconditional COUNT(*) are three
 * separate statements on the legacy dao->query() path that must keep running
 * in that exact order on the same connection, or FOUND_ROWS() reports the
 * wrong query's count. 'rows' (COUNT(*)) always reflects the WHOLE table,
 * while 'total_results' (FOUND_ROWS()) honours the s_name filter but ignores
 * LIMIT — the two numbers are not the same thing even when there is no filter.
 *
 * search()'s pagination has its own quirk, independent of the general
 * LIMIT/OFFSET inversion: dao->limit($start, $end) only ever emits a LIMIT at
 * all when $start is numeric, and only appends the second (row-count) number
 * when $end is BOTH numeric and greater than zero. A caller passing $end = 0
 * therefore does not get "zero rows" or "no limit" — it gets a bare
 * `LIMIT $start`, which MySQL reads as COUNT = $start, OFFSET = 0. That is
 * pinned below precisely because it is the kind of thing an argument-order
 * fix would silently get backwards.
 *
 * countRules() is unconditional (no WHERE) and returns the DAO-style string
 * count, i.e. it goes through the exact same "row()['count'] as-is" shape as
 * DAO::count() and AlertsStats' counters.
 *
 * Usage:  php tests/models/banrule.php          (standalone, own scratch database)
 *         php tests/run-models.php banrule      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_banrule');
$table = DB_TABLE_PREFIX . 't_ban_rule';

/**
 * t_ban_rule has no seed helper in scratchdb.php, so it lives here as a local
 * closure, per house style (see useremailtmp.php's $storedRow/$rowCount).
 *
 * @return int The AUTO_INCREMENT id just inserted
 */
$seedBanRule = static function (string $name, string $ip = '', string $email = '') use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table (s_name, s_ip, s_email) VALUES (?, ?, ?)",
        'sss',
        array($name, $ip, $email)
    );
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$model = BanRule::newInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('BanRule: public surface');

pin(
    'search signature is unchanged',
    "public search(\$start = 0, \$end = 10, \$order_column = 'pk_i_id', \$order_direction = 'DESC', \$name = '')",
    harness_method_signature('BanRule', 'search')
);
pin('countRules signature is unchanged', 'public countRules()', harness_method_signature('BanRule', 'countRules'));
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('BanRule', 'newInstance')
);
check('BanRule still extends DAO', is_subclass_of('BanRule', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 's_name', 's_ip', 's_email'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array('__construct', 'countRules', 'newInstance', 'search'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('BanRule')),
        array('__construct', 'newInstance', 'search', 'countRules')
    ))
);

/* ----------------------------------------------------------------------------
 * search() — empty table.
 * ------------------------------------------------------------------------- */
harness_section('BanRule::search — empty table');

$empty = $model->search();
check('an empty table returns an array', is_array($empty), describe($empty));
pin('rows keys are exactly rows/total_results/rules', array('rows', 'total_results', 'rules'), array_keys($empty));
pin('rows is int 0, not string "0" (FOUND_ROWS/COUNT are falsy-gated)', 0, $empty['rows']);
pin('total_results is int 0, not string "0"', 0, $empty['total_results']);
pin('rules is an empty array', array(), $empty['rules']);

/* ----------------------------------------------------------------------------
 * search() — populated table, default paging/order (pk_i_id DESC).
 * ------------------------------------------------------------------------- */
harness_section('BanRule::search — seed 8 rows');

$id1 = $seedBanRule('Alpha Rule', '1.1.1.1', 'a@test.com');
$id2 = $seedBanRule('Beta Rule', '1.1.1.2', 'b@test.com');
$id3 = $seedBanRule('Gamma Rule', '1.1.1.3', 'c@test.com');
$id4 = $seedBanRule('Delta Rule', '1.1.1.4', 'd@test.com');
$id5 = $seedBanRule('Epsilon Rule', '1.1.1.5', 'e@test.com');
$id6 = $seedBanRule('Zeta Rule', '1.1.1.6', 'f@test.com');
$id7 = $seedBanRule('Eta Rule', '1.1.1.7', 'g@test.com');
$id8 = $seedBanRule('100% Match', '1.1.1.8', 'h@test.com');

harness_section('BanRule::search — default order is pk_i_id DESC');

$res = $model->search(0, 3);
pin('the first 3 rows in default (DESC) order are 8,7,6', array((string) $id8, (string) $id7, (string) $id6), array_column($res['rules'], 'pk_i_id'));
pin('rows is the unconditional table count', 8, (int) $res['rows']);
pin('total_results equals rows when there is no filter', 8, (int) $res['total_results']);
check('every value in every returned row is a string or null (C4)', all_rows_string($res['rules']), describe($res['rules']));

harness_section('BanRule::search — LIMIT/OFFSET, count != offset (§3.3)');

/* offset=3, count=2: dao->limit($start, $end) with $start=3, $end=2 compiles
 * the literal `LIMIT 3, 2`, which MySQL reads as OFFSET 3, COUNT 2 -- skip the
 * first 3 rows of the DESC order (8,7,6), return the next 2 (5,4). A count/
 * offset argument-order inversion would instead skip 2 and return 3, which
 * fails this pin loudly. */
$res = $model->search(3, 2);
pin('offset 3, count 2 returns exactly 2 rows: 5 then 4', array((string) $id5, (string) $id4), array_column($res['rules'], 'pk_i_id'));

harness_section('BanRule::search — ascending order');

$res = $model->search(0, 3, 'pk_i_id', 'ASC');
pin('ascending order returns 1,2,3', array((string) $id1, (string) $id2, (string) $id3), array_column($res['rules'], 'pk_i_id'));

harness_section('BanRule::search — an invalid order_column falls back to pk_i_id');

$res = $model->search(0, 3, 'pk_i_id; DROP TABLE', 'DESC');
pin(
    'an order_column failing the allowlist regex falls back to pk_i_id DESC, same as the default',
    array((string) $id8, (string) $id7, (string) $id6),
    array_column($res['rules'], 'pk_i_id')
);

/* ----------------------------------------------------------------------------
 * search() — the $end = 0 quirk (NOT the same as "no limit" or "zero rows").
 * ------------------------------------------------------------------------- */
harness_section('BanRule::search — $end = 0 collapses to a bare LIMIT $start');

/* dao->limit($start, $end) only appends the row-count half of the clause when
 * $end is both numeric and > 0. With $end = 0 the compiled SQL is the bare
 * `LIMIT 3` -- COUNT = 3, OFFSET = 0 (MySQL's default) -- NOT "3 rows starting
 * at offset 3" and NOT "zero rows". This is the trap a naive count/offset
 * mapping gets backwards. */
$res = $model->search(3, 0);
pin(
    '$end = 0 means COUNT = $start, OFFSET = 0: the first 3 rows, not an offset of 3',
    array((string) $id8, (string) $id7, (string) $id6),
    array_column($res['rules'], 'pk_i_id')
);

harness_section('BanRule::search — a non-numeric $start omits the LIMIT clause entirely');

/* dao->limit() only sets aLimit when is_numeric($start); a non-numeric $start
 * leaves it unset, so no LIMIT clause is emitted at all and every matching row
 * comes back regardless of $end. */
$res = $model->search('not-numeric', 2);
pin('a non-numeric $start returns every row, ignoring $end entirely', 8, count($res['rules']));

/* ----------------------------------------------------------------------------
 * search() — the s_name filter (LIKE), and the wildcard-escaping quirk.
 * ------------------------------------------------------------------------- */
harness_section('BanRule::search — a name filter');

$res = $model->search(0, 10, 'pk_i_id', 'DESC', 'Rule');
pin('7 of the 8 rows contain "Rule" ("100% Match" does not)', 7, count($res['rules']));
pin('total_results reflects the filtered match count', 7, (int) $res['total_results']);
pin('rows still reflects the whole, unfiltered table', 8, (int) $res['rows']);

harness_section('BanRule::search — no match for the filter');

$res = $model->search(0, 10, 'pk_i_id', 'DESC', 'nosuchrule');
pin('an unmatched filter returns an empty rules list', array(), $res['rules']);
pin('total_results is int 0 for a filter with no matches', 0, $res['total_results']);
pin('rows is still the whole table', 8, (int) $res['rows']);

harness_section('BanRule::search — literal % and _ in the filter stay literal');

/* like()'s escapeStr($v, true) escapes % and _ in the payload before wrapping
 * it, so a user-typed wildcard character matches itself, not "any characters"
 * / "any one character". These two extra rows exist only to prove that: 'A_B'
 * must not also match 'AXB' the way an un-escaped LIKE '%A_B%' would. */
$idUnderscoreLiteral = $seedBanRule('A_B Wild');
$idUnderscoreAsWildcard = $seedBanRule('AXB Wild');

$res = $model->search(0, 10, 'pk_i_id', 'DESC', 'A_B');
pin(
    'the literal underscore matches only the literal "A_B", not "AXB"',
    array((string) $idUnderscoreLiteral),
    array_column($res['rules'], 'pk_i_id')
);

/* ----------------------------------------------------------------------------
 * search() — a malformed order_direction reaches the DB as a syntax error.
 * ------------------------------------------------------------------------- */
harness_section('BanRule::search — order_direction "0" breaks the ORDER BY and is absorbed');

/* DBCommandClass::orderBy() only rewrites $direction when trim($direction) is
 * truthy. trim('0') is the string "0", which PHP treats as falsy, so neither
 * branch fires and the raw, unprefixed "0" is concatenated straight onto the
 * column name: ORDER BY pk_i_id0. That is not a real column, the query fails
 * at the driver, and search()'s own `if ($rs == false)` guard absorbs the
 * failure into the same all-zero/empty shape as an empty table -- not an
 * exception, not a partial result. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$res       = $model->search(0, 10, 'pk_i_id', '0');
error_reporting($prevLevel);
pin('rows is int 0 when the query fails', 0, $res['rows']);
pin('total_results is int 0 when the query fails', 0, $res['total_results']);
pin('rules is empty when the query fails', array(), $res['rules']);

harness_section('BanRule::search — order_direction "random" is accepted (RAND())');

check('a "random" direction does not throw or error', is_array($model->search(0, 3, 'pk_i_id', 'random')));

/* ----------------------------------------------------------------------------
 * countRules() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('BanRule::countRules');

pin('countRules returns the row count as a STRING, not an int', (string) $rowCount(), $model->countRules());
check('countRules is really a string', is_string($model->countRules()), describe($model->countRules()));

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('BanRule: query cost');

pin('a normal search() costs exactly 3 statements (select, FOUND_ROWS, COUNT)', 3, harness_query_count(static function () use ($model) {
    $model->search(0, 3);
}));

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin(
    'a search() that fails at the driver costs exactly 1 statement -- FOUND_ROWS/COUNT are never reached',
    1,
    harness_query_count(static function () use ($model) {
        $model->search(0, 10, 'pk_i_id', '0');
    })
);
error_reporting($prevLevel);

pin('countRules costs exactly 1 statement', 1, harness_query_count(static function () use ($model) {
    $model->countRules();
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/banrule.php */
