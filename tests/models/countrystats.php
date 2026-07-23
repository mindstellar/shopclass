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
 * Characterization pins for the CountryStats model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * increaseNumItems()/decreaseNumItems()/setNumItems()/listCountries()/
 * calculateNumItems() move to the parameterized query layer.
 *
 * t_country_stats is a denormalised counter table: CHAR(2) primary key with a
 * FOREIGN KEY onto t_country, and an INT UNSIGNED counter. Three consequences
 * shape almost every pin below.
 *
 * 1. The foreign key, not the models's own guards, is what rejects most bad
 *    country codes. increaseNumItems()'s length guard reads
 *    `if ($lenght > 2 || $lenght == '')`, and `0 == ''` is FALSE on PHP 8 (the
 *    int is compared as the string '0'), so the empty string sails past the
 *    guard and is rejected by the database one query later. The observable
 *    return is `false` either way — the query COST is what tells the two paths
 *    apart, so both are pinned. decreaseNumItems() writes the same guard as
 *    `!$length`, which does reject 0, giving the two methods genuinely
 *    different bad-input costs.
 *
 * 2. Strict SQL mode is stripped from every connection, so an out-of-range
 *    counter is clamped rather than rejected: setNumItems($code, -1) reports
 *    success and stores 0. An explicit NULL into the NOT NULL primary key is
 *    still an error, and is the reachable failure path for the writes.
 *
 * 3. DBCommandClass::escape() passes a NUMERIC value through unquoted, so a
 *    country code that is_numeric() reached MySQL as a number and the CHAR
 *    column was compared numerically — every non-numeric code coerces to 0, so
 *    a code of '0' matches 'US', 'ES' and every other alphabetic row. The
 *    coercion pins in the decreaseNumItems and calculateNumItems sections nail
 *    that down; reproducing it after conversion needs the value bound as a PHP
 *    int, not as a string, because mysqli infers the comparison type from the
 *    parameter's PHP type.
 *
 * findByCountryCode() is pure delegation to DAO::findByPrimaryKey(), a base
 * method this effort does not convert; it is pinned as a regression guard only.
 *
 * Usage:  php tests/models/countrystats.php          (standalone, own scratch database)
 *         php tests/run-models.php countrystats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_countrystats');
$table = DB_TABLE_PREFIX . 't_country_stats';

$model = CountryStats::newInstance();

seed_country($admin, 'US', 'United States');
seed_country($admin, 'ES', 'Spain');
/* A country whose code is numeric. Legal in the schema (CHAR(2)) and the only
 * way to observe what escape()'s unquoted-numeric passthrough did. */
seed_country($admin, '12', 'Numeric land');

/**
 * The whole counter table as "code=count,code=count", ordered by code.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string rows the legacy path produced.
 */
$rows = static function () use ($admin, $table): string {
    $out = array();
    $res = $admin->query("SELECT fk_c_country_code, i_num_items FROM $table ORDER BY fk_c_country_code");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['fk_c_country_code'] . '=' . $r['i_num_items'];
    }
    $res->free();

    return implode(',', $out);
};

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** Seed one counter row with raw mysqli, never through the code under test. */
$seedStat = static function (string $code, int $num) use ($admin, $table): void {
    $admin->query("INSERT INTO $table (fk_c_country_code, i_num_items) VALUES ('$code', $num)");
};

/** Run $fn with warnings silenced, the way the failing-query pins need. */
$quiet = static function (callable $fn) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $out  = $fn();
    error_reporting($prev);

    return $out;
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('CountryStats: public surface');

pin(
    'increaseNumItems signature is unchanged',
    'public increaseNumItems($countryCode)',
    harness_method_signature('CountryStats', 'increaseNumItems')
);
pin(
    'decreaseNumItems signature is unchanged',
    'public decreaseNumItems($countryCode)',
    harness_method_signature('CountryStats', 'decreaseNumItems')
);
pin(
    'setNumItems signature is unchanged',
    'public setNumItems($countryCode, $numItems)',
    harness_method_signature('CountryStats', 'setNumItems')
);
pin(
    'findByCountryCode signature is unchanged',
    'public findByCountryCode($countryCode)',
    harness_method_signature('CountryStats', 'findByCountryCode')
);
pin(
    'listCountries signature is unchanged, defaults included',
    "public listCountries(\$zero = '>', \$order = 'country_name ASC')",
    harness_method_signature('CountryStats', 'listCountries')
);
pin(
    'calculateNumItems signature is unchanged',
    'public calculateNumItems($countryCode)',
    harness_method_signature('CountryStats', 'calculateNumItems')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('CountryStats', 'newInstance')
);
check('CountryStats still extends DAO', is_subclass_of('CountryStats', 'DAO'));
check('newInstance() is a singleton', CountryStats::newInstance() === $model);
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_c_country_code', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('fk_c_country_code', 'i_num_items'), $model->getFields());
pin(
    'the model declares exactly these eight methods of its own',
    array(
        '__construct',
        'calculateNumItems',
        'decreaseNumItems',
        'findByCountryCode',
        'increaseNumItems',
        'listCountries',
        'newInstance',
        'setNumItems',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('CountryStats')),
        array(
            '__construct',
            'calculateNumItems',
            'decreaseNumItems',
            'findByCountryCode',
            'increaseNumItems',
            'listCountries',
            'newInstance',
            'setNumItems',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * increaseNumItems() — the upsert counter.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — first call for a country');

$truncate();
pin('a fresh country returns bool true', true, $model->increaseNumItems('US'));
pin('the row was created at 1', 'US=1', $rows());

harness_section('increaseNumItems — subsequent calls');

pin('a repeat call also returns bool true', true, $model->increaseNumItems('US'));
pin('the counter incremented rather than duplicating the row', 'US=2', $rows());
pin('a second country is independent', true, $model->increaseNumItems('ES'));
pin('both rows now exist', 'ES=1,US=2', $rows());

harness_section('increaseNumItems — query cost');

pin('a fresh insert costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('12');
}));
pin('an increment costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('12');
}));
pin('the two counted calls landed', '12=2,ES=1,US=2', $rows());

harness_section('increaseNumItems — the length guard rejects before any query');

pin('a three-character code returns bool false', false, $model->increaseNumItems('USA'));
pin('a rejected code costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('USA');
}));
pin('nothing was written by the rejected code', '12=2,ES=1,US=2', $rows());

harness_section('increaseNumItems — the guard does NOT reject a zero-length code (0 == "" is false)');

pin('the empty string returns bool false, from the database not the guard', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems('');
}));
pin('and it really did reach the database — one query', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->increaseNumItems('');
    });
}));
pin('a null code also reaches the database and returns bool false', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(null);
}));
pin('a null code costs one query too', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->increaseNumItems(null);
    });
}));
pin('neither wrote anything', '12=2,ES=1,US=2', $rows());

harness_section('increaseNumItems — a well-formed code with no matching country (foreign key)');

pin('an unknown country returns bool false', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems('XX');
}));
pin('the rejected insert still costs one query', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->increaseNumItems('XX');
    });
}));
pin('nothing was written for it', '12=2,ES=1,US=2', $rows());

harness_section('increaseNumItems — non-string codes');

pin('an int code that matches a country succeeds', true, $model->increaseNumItems(12));
pin('the int went to the same row as the string form', '12=3,ES=1,US=2', $rows());
pin('the string form of the same code still hits that row', true, $model->increaseNumItems('12'));
pin('still one row for it', '12=4,ES=1,US=2', $rows());
pin('int 0 has no country and is rejected', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(0);
}));
pin('bool true is escaped to 1, has no country, and is rejected', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(true);
}));
pin('neither wrote anything', '12=4,ES=1,US=2', $rows());

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — read then conditional update. Returns the affected-row
 * count, so 1 and 0 are both success values and false means "no row to work on".
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a country with a positive counter');

$truncate();
$seedStat('US', 3);
pin('decrementing returns int 1, the affected-row count', 1, $model->decreaseNumItems('US'));
pin('the counter went down by one', 'US=2', $rows());

harness_section('decreaseNumItems — a counter already at zero');

$truncate();
$seedStat('US', 0);
pin('a zero counter returns int 0 — the row exists, the guarded update matched nothing', 0, $model->decreaseNumItems('US'));
pin('the counter did not go negative', 'US=0', $rows());

harness_section('decreaseNumItems — no row for that country');

pin('a country with no counter row returns bool false', false, $model->decreaseNumItems('ES'));
pin('a missing row costs exactly one query (the lookup only)', 1, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('ES');
}));
pin('nothing was created for it', 'US=0', $rows());

harness_section('decreaseNumItems — query cost when the row exists');

$truncate();
$seedStat('US', 5);
pin('a decrement costs two queries: lookup then update', 2, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('US');
}));
pin('the decrement landed', 'US=4', $rows());

harness_section('decreaseNumItems — the guard rejects both over-long and zero-length codes');

pin('a three-character code returns bool false', false, $model->decreaseNumItems('USA'));
pin('the empty string returns bool false — !$length catches what increase() misses', false, $model->decreaseNumItems(''));
pin('a null code returns bool false', false, $model->decreaseNumItems(null));
pin('a rejected code costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('USA');
}));
pin('the empty string costs no queries either', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('');
}));
pin('nothing changed', 'US=4', $rows());

harness_section('decreaseNumItems — a numeric code matches only its own row');

/* DELIBERATE BEHAVIOUR CHANGE, recorded rather than hidden.
 *
 * The legacy escape() returned an is_numeric() value unquoted, so a numeric
 * country code reached MySQL as a number and the CHAR column was compared
 * numerically: every alphabetic code collapsed to 0, and a code of '0'
 * therefore matched — and decremented — rows like 'US'. Binding the value
 * compares it as the string it is, so a code now matches only its own row.
 *
 * The behaviour that disappears is type confusion, not a feature: no caller
 * passes a numeric country code deliberately, and the same comparison shape
 * exists on the credential lookups, where preserving it would be actively
 * unsafe. Every other converted model binds its values and lost this quirk
 * silently; this one is written down. */
$truncate();
$seedStat('US', 4);
$seedStat('12', 2);
pin('the string "0" matches nothing at all', false, $model->decreaseNumItems('0'));
pin('no counter moved', '12=2,US=4', $rows());

/* The change only reaches values that arrive as strings. A genuine int binds as
 * an int, which is still a numeric comparison, so the coercion survives there
 * exactly as before. Callers pass codes read from request params or the
 * database, i.e. strings, so this residue is not reachable in practice — but it
 * is the honest boundary of the change and is pinned as such. */
pin('an int 0 still coerces and decrements an alphabetic row', 1, $model->decreaseNumItems(0));
pin('US moved, because the comparison was numeric', '12=2,US=3', $rows());

pin('a numeric code that matches its own row decrements only that row', 1, $model->decreaseNumItems('12'));
pin('only the numeric-coded row moved', '12=1,US=3', $rows());

/* ----------------------------------------------------------------------------
 * setNumItems() — absolute upsert. No length guard at all.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — insert and overwrite');

$truncate();
pin('setting a country with no row returns bool true', true, $model->setNumItems('US', 7));
pin('the row was created with that count', 'US=7', $rows());
pin('setting it again returns bool true', true, $model->setNumItems('US', 3));
pin('the count was overwritten, not incremented', 'US=3', $rows());
pin('re-setting the same value still returns bool true', true, $model->setNumItems('US', 3));
pin('the row is unchanged', 'US=3', $rows());

harness_section('setNumItems — the count is cast to int');

pin('a numeric-prefixed string returns bool true', true, $model->setNumItems('US', '9abc'));
pin('and stores its integer prefix', 'US=9', $rows());
pin('a null count returns bool true', true, $model->setNumItems('US', null));
pin('and stores 0', 'US=0', $rows());

harness_section('setNumItems — a negative count is clamped, not rejected (strict mode is off)');

pin('a negative count still reports success', true, $model->setNumItems('US', -1));
pin('the unsigned column clamped it to 0', 'US=0', $rows());

harness_section('setNumItems — rejected by the database');

pin('a null country code returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems(null, 3);
}));
pin('an unknown country returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems('XX', 3);
}));
pin('an over-long code returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems('TOOLONG', 3);
}));
pin('none of them wrote anything', 'US=0', $rows());

harness_section('setNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->setNumItems('US', 2);
}));
pin('the counted call landed', 'US=2', $rows());

harness_section('setNumItems — numeric codes');

pin('an int code that matches a country succeeds', true, $model->setNumItems(12, 5));
pin('the string form addresses the same row', true, $model->setNumItems('12', 6));
pin('one row for it, holding the last value', '12=6,US=2', $rows());

/* ----------------------------------------------------------------------------
 * findByCountryCode() — regression guard only; it delegates straight to
 * DAO::findByPrimaryKey(), a base method out of scope for this effort.
 * ------------------------------------------------------------------------- */
harness_section('findByCountryCode — regression guard (not converted)');

$truncate();
$seedStat('US', 8);
$found = $model->findByCountryCode('US');
check('a known country returns an array', is_array($found));
pin('with both columns as strings', array('fk_c_country_code' => 'US', 'i_num_items' => '8'), $found);
check('every value is a string (C4)', is_array($found) && all_values_string($found));
pin('an unknown country returns bool false', false, $model->findByCountryCode('XX'));
pin('a null code returns bool false', false, $quiet(static function () use ($model) {
    return $model->findByCountryCode(null);
}));

/* ----------------------------------------------------------------------------
 * listCountries() — the joined listing. Both arguments are validated in the
 * method itself, one against a hardcoded operator list and one against a
 * hardcoded pattern, and both fall back to their defaults when they fail.
 * ------------------------------------------------------------------------- */
harness_section('listCountries — shape and default ordering');

$truncate();
$seedStat('US', 3);
$seedStat('ES', 0);
$seedStat('12', 2);

$list = $model->listCountries();
pin('the default lists only countries with a positive counter', 2, count($list));
pin(
    'the row shape is the four aliased columns',
    array('country_code', 'items', 'country_name', 'country_slug'),
    array_keys($list[0])
);
pin(
    'the first row is the full aliased projection',
    array(
        'country_code' => '12',
        'items'        => '2',
        'country_name' => 'Numeric land',
        'country_slug' => '12',
    ),
    $list[0]
);
check('every value in every row is a string (C4)', all_rows_string($list));
pin('the default order is country_name ASC', '12,US', implode(',', array_column($list, 'country_code')));

harness_section('listCountries — the comparison argument');

pin('">=" includes the zero-counter country', 3, count($model->listCountries('>=')));
pin('"=" selects only the zero-counter country', 1, count($model->listCountries('=')));
pin('"<" matches nothing and returns an empty array', array(), $model->listCountries('<'));
pin('an unlisted operator falls back to ">"', 2, count($model->listCountries('bogus')));
pin('an empty operator falls back to ">"', 2, count($model->listCountries('')));
pin('a null operator falls back to ">"', 2, count($model->listCountries(null)));

harness_section('listCountries — the order argument');

pin(
    'country_name DESC reverses the default',
    'US,ES,12',
    implode(',', array_column($model->listCountries('>=', 'country_name DESC'), 'country_code'))
);
pin(
    'items DESC orders by the counter alias',
    'US,12',
    implode(',', array_column($model->listCountries('>', 'items DESC'), 'country_code'))
);
pin(
    'a malformed order falls back to country_name ASC',
    '12,ES,US',
    implode(',', array_column($model->listCountries('>=', 'bad order'), 'country_code'))
);
pin(
    'a null order falls back to country_name ASC',
    '12,ES,US',
    implode(',', array_column($model->listCountries('>=', null), 'country_code'))
);

harness_section('listCountries — the error fallback is reachable through the order argument');

/* The pattern admits any identifier-shaped column, so a well-formed order over
 * a column that does not exist is a failed query, and the method's own
 * false-branch turns it into an empty array — NOT an exception. */
pin('an unknown but well-formed order column returns an empty array', array(), $quiet(static function () use ($model) {
    return $model->listCountries('>', 'nope ASC');
}));

harness_section('listCountries — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->listCountries();
}));
pin('the failing form costs one query too', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->listCountries('>', 'nope ASC');
    });
}));

harness_section('listCountries — an empty counter table');

$truncate();
pin('no counter rows at all gives an empty array', array(), $model->listCountries());

/* ----------------------------------------------------------------------------
 * calculateNumItems() — the recount aggregate over t_item_location/t_item/
 * t_category. COUNT(*) with no GROUP BY always returns one row, so there is no
 * zero-row branch: a country with nothing to count returns the STRING '0',
 * while a failed query returns INT 0.
 * ------------------------------------------------------------------------- */
harness_section('calculateNumItems — setup');

$catId    = seed_category($admin, 'Motors');
$userId   = seed_user($admin);
$itemUs1  = seed_item($admin, $catId, $userId, 'US one', 10.0, 1, 1, 'en_US', 'US');
$itemUs2  = seed_item($admin, $catId, $userId, 'US two', 10.0, 1, 1, 'en_US', 'US');
$itemUs3  = seed_item($admin, $catId, $userId, 'US inactive', 10.0, 0, 1, 'en_US', 'US');
$itemEs1  = seed_item($admin, $catId, $userId, 'ES one', 10.0, 1, 1, 'en_US', 'ES');

pin('two active US items are counted, the inactive one is not', '2', $model->calculateNumItems('US'));
check('the count is a string, not an int (C4)', is_string($model->calculateNumItems('US')));
pin('the other country counts its own item', '1', $model->calculateNumItems('ES'));
pin('a country with no items returns the string "0"', '0', $model->calculateNumItems('XX'));
pin('an over-long code returns the string "0"', '0', $model->calculateNumItems('US-TOO-LONG'));
pin('a quote in the code is neutralised, not an error', '0', $model->calculateNumItems("o'brien"));

harness_section('calculateNumItems — a null code is valid SQL that matches nothing');

/* `= NULL` is never true, so this is a genuine zero match returning the STRING
 * '0' — not the int 0 the failed-query branch produces. */
pin('a null code returns the string "0"', '0', $quiet(static function () use ($model) {
    return $model->calculateNumItems(null);
}));

harness_section('calculateNumItems — a numeric code matches only its own row');

/* The same deliberate change as decreaseNumItems above: the code is compared as
 * a string, so a code of '0' no longer sweeps up every alphabetically-coded
 * country's items. */
pin('the string "0" counts nothing', '0', $model->calculateNumItems('0'));
/* As above, a genuine int still binds as a number and still coerces. */
pin('an int 0 still sweeps up the alphabetically-coded countries', '3', $model->calculateNumItems(0));

harness_section('calculateNumItems — the eligibility rules');

$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemUs1");
pin('an expired item drops out', '1', $model->calculateNumItems('US'));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_premium = 1 WHERE pk_i_id = $itemUs1");
pin('unless it is premium, which is counted however old it is', '2', $model->calculateNumItems('US'));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_spam = 1 WHERE pk_i_id = $itemUs2");
pin('a spam item drops out', '1', $model->calculateNumItems('US'));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 0');
pin('a disabled category takes all of its items with it', '0', $model->calculateNumItems('US'));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 1');
pin('and re-enabling brings them back', '1', $model->calculateNumItems('US'));

harness_section('calculateNumItems — the failed-query branch returns int 0');

/* An array argument is the one input that reaches the query layer malformed:
 * escape() hands it straight back, it string-casts to `Array` in the SQL, and
 * the method's own false-branch converts the failure to int 0 — a different
 * value and a different type from the string '0' a legitimate zero match gives. */
pin('an array code returns int 0, not the string "0"', 0, $quiet(static function () use ($model) {
    return $model->calculateNumItems(array('US'));
}));

harness_section('calculateNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->calculateNumItems('US');
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/countrystats.php */
