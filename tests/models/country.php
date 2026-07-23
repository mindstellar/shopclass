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
 * Characterization pins for the Country model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * findByCode()/findByName()/listAll()/listNames()/ajax()/findBySlug()/
 * listByEmptySlug() move to the parameterized query layer.
 *
 * findByCode()/findByName()/findBySlug() never check numRows(): they hand the
 * raw recordset's row() straight back, and row() itself returns an empty
 * array() when the recordset holds zero rows (not false, not null) — so "no
 * match" and "SQL error" both collapse to the very same array() value that a
 * "found nothing" query would. A null lookup value reaches that same array()
 * by a different road: DBCommandClass::_where() appends the comparison
 * operator with no right-hand side at all when the value is null, producing a
 * genuine SQL syntax error that dao->get() reports as bool false.
 *
 * t_country's collation is utf8mb4_general_ci, so findByCode()/findByName()
 * match case-insensitively today; that is a property of the column collation,
 * not of any code in this model, and the conversion must neither fold case in
 * PHP nor force a binary comparison — a pin below locks in the current
 * case-insensitive behaviour so a conversion cannot silently change it either
 * way.
 *
 * deleteByPrimaryKey() has no query of its own to convert: it is pure
 * delegation to Region::deleteByPrimaryKey() (itself delegating further, into
 * City/CityArea/Item), Item::deleteByCountry(), CountryStats::delete(),
 * User::update() and the inherited DAO::delete(). Its signature is pinned for
 * C2; its cascade behaviour is not exercised here because that would require
 * bootstrapping Item's hook pipeline, well outside a model-layer
 * characterization test, and the method body is not touched by this
 * conversion regardless — every query it triggers belongs to a different
 * model's file.
 *
 * Usage:  php tests/models/country.php          (standalone, own scratch database)
 *         php tests/run-models.php country      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_country');
$table = DB_TABLE_PREFIX . 't_country';

$model = Country::newInstance();

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Country: public surface');

pin(
    'findByCode signature is unchanged',
    'public findByCode($code)',
    harness_method_signature('Country', 'findByCode')
);
pin(
    'findByName signature is unchanged',
    'public findByName($name)',
    harness_method_signature('Country', 'findByName')
);
pin(
    'listAll signature is unchanged',
    'public listAll()',
    harness_method_signature('Country', 'listAll')
);
pin(
    'deleteByPrimaryKey signature is unchanged',
    'public deleteByPrimaryKey($pk)',
    harness_method_signature('Country', 'deleteByPrimaryKey')
);
pin(
    'listNames signature is unchanged',
    'public listNames()',
    harness_method_signature('Country', 'listNames')
);
pin(
    'ajax signature is unchanged',
    'public ajax($query)',
    harness_method_signature('Country', 'ajax')
);
pin(
    'findBySlug signature is unchanged',
    'public findBySlug($slug)',
    harness_method_signature('Country', 'findBySlug')
);
pin(
    'listByEmptySlug signature is unchanged',
    'public listByEmptySlug()',
    harness_method_signature('Country', 'listByEmptySlug')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Country', 'newInstance'));
check('Country still extends DAO', is_subclass_of('Country', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_c_code', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('pk_c_code', 's_name', 's_slug'), $model->getFields());
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct', 'ajax', 'deleteByPrimaryKey', 'findByCode', 'findByName',
        'findBySlug', 'listAll', 'listByEmptySlug', 'listNames', 'newInstance',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Country')),
        array(
            '__construct', 'newInstance', 'findByCode', 'findByName', 'listAll',
            'deleteByPrimaryKey', 'listNames', 'ajax', 'findBySlug', 'listByEmptySlug',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * findByCode() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Country::findByCode — empty table');

pin('no rows at all returns an empty array, not false', array(), $model->findByCode('US'));

harness_section('Country::findByCode — a match');

seed_country($admin, 'US', 'United States');
seed_country($admin, 'FR', 'France');

$row = $model->findByCode('US');
check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the three schema columns', array('pk_c_code', 's_name', 's_slug'), array_keys($row));
pin('pk_c_code round-trips', 'US', $row['pk_c_code']);
pin('s_name round-trips', 'United States', $row['s_name']);
pin('s_slug round-trips', 'us', $row['s_slug']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Country::findByCode — a second code is matched independently');

pin('FR resolves to its own row', 'France', $model->findByCode('FR')['s_name']);

harness_section('Country::findByCode — no match');

pin('an unknown code returns an empty array', array(), $model->findByCode('ZZ'));

harness_section('Country::findByCode — collation makes the match case-insensitive');

/* t_country is utf8mb4_general_ci. This is a property of the column, not of
 * this model's code, and the conversion must reproduce it by binding the
 * value as-is — neither folding case in PHP nor forcing a binary compare. */
pin('a lowercase code still matches, per collation', 'United States', $model->findByCode('us')['s_name']);

harness_section('Country::findByCode — malformed lookup (null code)');

/* Passing null builds a comparison with no right-hand side, so the query
 * fails at the driver and the failure surfaces as "not found" rather than as
 * an error — the same quirk pinned for Cron::getCronByType(null) and
 * CityArea::findByName(null). */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null code returns an empty array rather than raising', array(), $model->findByCode(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByName() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Country::findByName — a match');

pin('a known name returns its row', 'US', $model->findByName('United States')['pk_c_code']);

harness_section('Country::findByName — duplicate names, first inserted wins');

scratchdb_truncate_all($admin);
seed_country($admin, 'A1', 'Duplicated');
seed_country($admin, 'A2', 'Duplicated');

pin(
    'without any other filter, only the first-inserted duplicate comes back',
    'A1',
    $model->findByName('Duplicated')['pk_c_code']
);

harness_section('Country::findByName — no match');

pin('an unknown name returns an empty array', array(), $model->findByName('Nowhere'));

harness_section('Country::findByName — malformed lookup (null name)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null name returns an empty array rather than raising', array(), $model->findByName(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listAll() — the return ledger and the ORDER BY.
 * ------------------------------------------------------------------------- */
harness_section('Country::listAll — empty table');

scratchdb_truncate_all($admin);
pin('no rows at all returns an empty array', array(), $model->listAll());

harness_section('Country::listAll — ordering');

// Insertion order (US, FR, DE) and primary-key order (DE, FR, US) both
// disagree with s_name order (France, Germany, United States), so a missing
// or wrong ORDER BY fails loudly rather than passing by coincidence.
seed_country($admin, 'US', 'United States');
seed_country($admin, 'FR', 'France');
seed_country($admin, 'DE', 'Germany');

$rows = $model->listAll();
check('a match returns an array', is_array($rows), describe($rows));
pin('exactly three rows', 3, count($rows));
pin(
    'rows come back ordered by s_name ASC, not insertion/pk order',
    array('FR', 'DE', 'US'),
    array_column($rows, 'pk_c_code')
);
pin('each row carries exactly the three schema columns', array('pk_c_code', 's_name', 's_slug'), array_keys($rows[0]));
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

/* ----------------------------------------------------------------------------
 * listNames() — the return ledger and the ORDER BY.
 * ------------------------------------------------------------------------- */
harness_section('Country::listNames — empty table');

scratchdb_truncate_all($admin);
pin('no rows at all returns an empty array', array(), $model->listNames());

harness_section('Country::listNames — ordering, one column only');

seed_country($admin, 'US', 'United States');
seed_country($admin, 'FR', 'France');
seed_country($admin, 'DE', 'Germany');

$names = $model->listNames();
pin('exactly three names, ordered by s_name ASC', array('France', 'Germany', 'United States'), $names);
check('every value is a string (C4)', all_values_string(array_combine(array_keys($names), $names)), describe($names));

/* ----------------------------------------------------------------------------
 * ajax() — the return ledger, the aliasing and the LIMIT.
 * ------------------------------------------------------------------------- */
harness_section('Country::ajax — matches, aliased columns, LIMIT 5');

scratchdb_truncate_all($admin);
seed_country($admin, 'US', 'United States');
seed_country($admin, 'UK', 'United Kingdom');
seed_country($admin, 'FR', 'France');

$rows = $model->ajax('United');
check('a match returns an array', is_array($rows), describe($rows));
pin('exactly two rows match the "United" prefix', 2, count($rows));
pin('each row is aliased to id/label/value, not the schema column names', array('id', 'label', 'value'), array_keys($rows[0]));
$ids = array_column($rows, 'id');
sort($ids);
pin('both matching codes are present', array('UK', 'US'), $ids);
check('every value in every row is a string (C4)', all_rows_string($rows), describe($rows));

harness_section('Country::ajax — LIMIT 5 is enforced');

for ($i = 0; $i < 6; $i++) {
    seed_country($admin, 'Z' . $i, 'Zeta' . $i);
}
pin('no more than 5 rows come back even with 6 matches available', 5, count($model->ajax('Zeta')));

harness_section('Country::ajax — no match');

pin('an unmatched prefix returns an empty array', array(), $model->ajax('Nowhereland'));

harness_section('Country::ajax — malformed lookup (null query)');

/* dao->like() routes a null payload through escapeStr(null, true), which
 * coerces to the empty string rather than raising, so the LIKE payload
 * degrades to '%' — everything matches, capped by the LIMIT 5 — instead of
 * failing like the plain where() lookups above. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null query matches everything, capped at 5', 5, count($model->ajax(null)));
error_reporting($prevLevel);

harness_section('Country::ajax — a literal wildcard character is not treated as a wildcard');

/* dao->like() routes through escapeStr($v, true), which escapes the LIKE
 * metacharacters in the payload before the surrounding wildcards are added —
 * so a literal '%' typed by a caller searches for a literal '%', not "any
 * suffix". Per the LIKE-preservation rule this maps straight onto the new
 * builder's like(), which escapes the same way. */
scratchdb_truncate_all($admin);
seed_country($admin, 'PC', '100%');
seed_country($admin, 'PD', '100 percent complete');
pin('a literal percent sign matches only the literal string, not everything starting with "100"', array('100%'), array_column($model->ajax('100%'), 'label'));

/* ----------------------------------------------------------------------------
 * findBySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Country::findBySlug — a match');

scratchdb_truncate_all($admin);
seed_country($admin, 'US', 'United States');

$row = $model->findBySlug('us');
pin('the slug resolves to the expected row', 'US', $row['pk_c_code']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Country::findBySlug — no match');

pin('an unknown slug returns an empty array', array(), $model->findBySlug('nowhere'));

harness_section('Country::findBySlug — malformed lookup (null slug)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null slug returns an empty array rather than raising', array(), $model->findBySlug(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listByEmptySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Country::listByEmptySlug — a mix of empty and non-empty slugs');

scratchdb_truncate_all($admin);
seed_country($admin, 'US', 'United States'); // seed_country always fills s_slug
seed_exec(
    $admin,
    'INSERT INTO ' . $table . ' (pk_c_code, s_name, s_slug) VALUES (?, ?, ?)',
    'sss',
    array('NS', 'No Slug', '')
);
seed_exec(
    $admin,
    'INSERT INTO ' . $table . ' (pk_c_code, s_name, s_slug) VALUES (?, ?, ?)',
    'sss',
    array('N2', 'Also No Slug', '')
);

$rows = $model->listByEmptySlug();
check('a match returns an array', is_array($rows), describe($rows));
pin('exactly the two empty-slug rows come back', 2, count($rows));
$codes = array_column($rows, 'pk_c_code');
sort($codes);
pin('both empty-slug codes are present, the populated one is not', array('N2', 'NS'), $codes);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

harness_section('Country::listByEmptySlug — no match');

scratchdb_truncate_all($admin);
seed_country($admin, 'US', 'United States');
pin('when nothing has an empty slug, an empty array comes back', array(), $model->listByEmptySlug());

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey() — signature only (C2). See the file-level note: every
 * query it triggers belongs to a different, unconverted model.
 * ------------------------------------------------------------------------- */
harness_section('Country: query cost');

scratchdb_truncate_all($admin);
seed_country($admin, 'US', 'United States');
seed_country($admin, 'FR', 'France');
seed_country($admin, 'DE', 'Germany');

pin('one findByCode() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByCode('US');
}));
pin('one findByName() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByName('France');
}));
pin('one listAll() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listAll();
}));
pin('one listNames() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listNames();
}));
pin('one ajax() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->ajax('Fra');
}));
pin('one findBySlug() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findBySlug('us');
}));
pin('one listByEmptySlug() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listByEmptySlug();
}));

check('nothing was written by any of the read-only pins above', $rowCount() === 3, (string) $rowCount());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/country.php */
