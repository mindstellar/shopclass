<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins for the CountryStats model.
 *
 * Each country keeps its live listing count in t_country.i_num_items (default 0),
 * so there is no separate counter row: every country always has a count. The pins
 * are written against the observable behaviour (return values, stored counts,
 * query counts), not the SQL.
 *
 * 1. Codes are CHAR(2). increaseNumItems() and decreaseNumItems() reject an empty
 *    or longer-than-two code before any query; both are one UPDATE with no lookup.
 *    increase returns true only when a row changed, so an unknown country is false
 *    and no row is ever created. decrease returns the affected-row count (0 for a
 *    count already at zero or an unknown country) and never goes below zero.
 *
 * 2. setNumItems() is one UPDATE and is true unless the database fails, so it is
 *    also true for an unknown country (0 rows). Whether an out-of-range count is
 *    clamped or rejected depends on the connection, so setNumItems($code, -1) is
 *    pinned both ways behind harness_strict_writes().
 *
 * 3. A country code is bound as a string, so a numeric code like '0' matches only
 *    its own row. A genuine int still compares numerically (every alphabetic code
 *    coerces to 0); the coercion pins in the decreaseNumItems and
 *    calculateNumItems sections record that boundary.
 *
 * Usage:  php tests/models/countrystats.php          (standalone, own scratch database)
 *         php tests/run-models.php countrystats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_countrystats');
$table = DB_TABLE_PREFIX . 't_country';

$model = CountryStats::getInstance();

seed_country($admin, 'US', 'United States');
seed_country($admin, 'ES', 'Spain');
/* A country whose code is numeric. Legal in the schema (CHAR(2)) and the only
 * way to observe what escape()'s unquoted-numeric passthrough did. */
seed_country($admin, '12', 'Numeric land');

/**
 * The three seeded countries' counts as "code=count,code=count", ordered by code.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string rows the model produces.
 */
$rows = static function () use ($admin, $table): string {
    $out = array();
    $res = $admin->query("SELECT pk_c_code, i_num_items FROM $table WHERE pk_c_code IN ('US', 'ES', '12') ORDER BY pk_c_code");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['pk_c_code'] . '=' . $r['i_num_items'];
    }
    $res->free();

    return implode(',', $out);
};

/** The expected $rows() string for the given code => count map; every other country is 0. */
$expect = static function (array $counts): string {
    $out = array();
    foreach (array('12', 'ES', 'US') as $code) {
        $out[] = $code . '=' . ($counts[$code] ?? 0);
    }

    return implode(',', $out);
};

/** Put every country back to a count of 0. */
$truncate = static function () use ($admin, $table): void {
    $admin->query("UPDATE $table SET i_num_items = 0");
};

/** Give one country a count with raw mysqli, never through the code under test. */
$seedStat = static function (string $code, int $num) use ($admin, $table): void {
    $admin->query("UPDATE $table SET i_num_items = $num WHERE pk_c_code = '$code'");
};

/** Run $fn with warnings silenced, the way the failing-query pins need. */
$quiet = static function (callable $fn) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $out  = $fn();
    error_reporting($prev);

    return $out;
};

/** Run $fn with one table renamed out of the way, so every query touching it fails. */
$withTableMissing = static function (string $name, callable $fn) use ($admin) {
    $admin->query("RENAME TABLE `$name` TO `{$name}_hidden`");
    $previous = error_reporting(E_ALL & ~E_WARNING);
    try {
        return $fn();
    } finally {
        error_reporting($previous);
        $admin->query("RENAME TABLE `{$name}_hidden` TO `$name`");
    }
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
check('newInstance() is a singleton', CountryStats::getInstance() === $model);
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('the DAO names the old stats table, so its inherited methods have no table to act on', DB_TABLE_PREFIX . 't_country_stats', $model->getTableName());
pin('primary key', 'fk_c_country_code', $model->getPrimaryKey());
pin('field allowlist', array('fk_c_country_code', 'i_num_items'), $model->getFields());
seed_country($admin, 'ZZ', 'Leaf land');
$quietDelete = @$model->deleteByPrimaryKey('ZZ');
pin('an inherited deleteByPrimaryKey() cannot delete the real row', array(false, 1), array($quietDelete, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE pk_c_code = '" . 'ZZ' . "'")->fetch_row()[0]));
$admin->query("DELETE FROM $table WHERE pk_c_code = 'ZZ'");
$truncate();
pin(
    'the store binds a numeric id as text, so 0 matches no alphabetic code',
    array(0, null, '0|0|0'),
    array(\mindstellar\listing\ListingCountStore::countries()->add(array(0), 1), \mindstellar\listing\ListingCountStore::countries()->row(0, 'c'), implode('|', array_map('intval', array_values(array_intersect_key(\mindstellar\listing\ListingCountStore::countries()->all(), array_flip(array('US', 'ES', '12')))))))
);
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
 * increaseNumItems() — one UPDATE ... + 1. True only when a row changed, so an
 * unknown country is false; it never creates a row.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — a country');

$truncate();
pin('a known country returns bool true', true, $model->increaseNumItems('US'));
pin('its count went from 0 to 1', $expect(array('US' => 1)), $rows());
pin('a repeat call also returns bool true', true, $model->increaseNumItems('US'));
pin('the count incremented', $expect(array('US' => 2)), $rows());
pin('a second country is independent', true, $model->increaseNumItems('ES'));
pin('both counts are right', $expect(array('ES' => 1, 'US' => 2)), $rows());

harness_section('increaseNumItems — query cost');

pin('an increment costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('12');
}));
pin('a second increment costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('12');
}));
pin('the two counted calls landed', $expect(array('12' => 2, 'ES' => 1, 'US' => 2)), $rows());

harness_section('increaseNumItems — the length guard rejects before any query');

pin('a three-character code returns bool false', false, $model->increaseNumItems('USA'));
pin('a rejected code costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('USA');
}));
pin('nothing was written by the rejected code', $expect(array('12' => 2, 'ES' => 1, 'US' => 2)), $rows());

harness_section('increaseNumItems — a zero-length code is refused before the database');

pin('the empty string returns bool false', false, $model->increaseNumItems(''));
pin('and costs no query', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('');
}));
pin('a null code returns bool false', false, $model->increaseNumItems(null));
pin('and costs no query either', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems(null);
}));
pin('neither wrote anything', $expect(array('12' => 2, 'ES' => 1, 'US' => 2)), $rows());

harness_section('increaseNumItems — a well-formed code with no matching country');

pin('an unknown country returns bool false', false, $model->increaseNumItems('XX'));
pin('and costs one query', 1, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('XX');
}));
pin('no row was created for it', '0', (function () use ($admin, $table) {
    $res = $admin->query("SELECT COUNT(*) AS c FROM $table WHERE pk_c_code = 'XX'");
    $c   = $res->fetch_assoc()['c'];
    $res->free();

    return $c;
})());
pin('no existing count changed', $expect(array('12' => 2, 'ES' => 1, 'US' => 2)), $rows());

harness_section('increaseNumItems — non-string codes');

pin('an int code that matches a country succeeds', true, $model->increaseNumItems(12));
pin('the int went to the same country as the string form', $expect(array('12' => 3, 'ES' => 1, 'US' => 2)), $rows());
pin('the string form of the same code still hits it', true, $model->increaseNumItems('12'));
pin('and incremented it again', $expect(array('12' => 4, 'ES' => 1, 'US' => 2)), $rows());
pin('int 0 is a one-character code with no country of its own and is rejected', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(0);
}));
pin('bool true is the code "1", has no country, and is rejected', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(true);
}));
pin('neither wrote anything', $expect(array('12' => 4, 'ES' => 1, 'US' => 2)), $rows());

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — one UPDATE, no lookup first, never below zero. Returns the
 * affected-row count, so 1 and 0 are both success values; false means a rejected
 * code or a database error.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a country with a positive count');

$truncate();
$seedStat('US', 3);
pin('decrementing returns int 1, the affected-row count', 1, $model->decreaseNumItems('US'));
pin('the count went down by one', $expect(array('US' => 2)), $rows());

harness_section('decreaseNumItems — a count already at zero');

$truncate();
pin('a zero count returns int 0 — the guarded update matched nothing', 0, $model->decreaseNumItems('US'));
pin('the count did not go negative', $expect(array()), $rows());

harness_section('decreaseNumItems — no such country');

pin('an unknown country returns int 0', 0, $model->decreaseNumItems('XX'));
pin('and costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('XX');
}));
pin('nothing changed', $expect(array()), $rows());

harness_section('decreaseNumItems — query cost');

$truncate();
$seedStat('US', 5);
pin('a decrement costs one query, with no lookup first', 1, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('US');
}));
pin('the decrement landed', $expect(array('US' => 4)), $rows());

harness_section('decreaseNumItems — the guard rejects both over-long and zero-length codes');

pin('a three-character code returns bool false', false, $model->decreaseNumItems('USA'));
pin('the empty string returns bool false', false, $model->decreaseNumItems(''));
pin('a null code returns bool false', false, $model->decreaseNumItems(null));
pin('a rejected code costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('USA');
}));
pin('the empty string costs no queries either', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('');
}));
pin('nothing changed', $expect(array('US' => 4)), $rows());

harness_section('decreaseNumItems — a database error');

pin('a failed update returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model) {
        return $model->decreaseNumItems('US');
    }
));

harness_section('decreaseNumItems — a numeric code matches only its own row');

/* DELIBERATE BEHAVIOUR CHANGE, recorded rather than hidden.
 *
 * The legacy escape() returned an is_numeric() value unquoted, so a numeric
 * country code reached MySQL as a number and the CHAR column was compared
 * numerically: every alphabetic code collapsed to 0, and a code of '0'
 * therefore matched — and decremented — rows like 'US'. The code is now bound as
 * the string it is, whatever PHP type it arrived as, so it matches only its own
 * row.
 *
 * The behaviour that disappears is type confusion, not a feature: no caller
 * passes a numeric country code deliberately, and the same comparison shape
 * exists on the credential lookups, where preserving it would be actively
 * unsafe. */
$truncate();
$seedStat('US', 4);
$seedStat('12', 2);
pin('the string "0" matches nothing at all', 0, $model->decreaseNumItems('0'));
pin('an int 0 is bound as a string too and matches nothing', 0, $model->decreaseNumItems(0));
pin('no count moved', $expect(array('12' => 2, 'US' => 4)), $rows());
pin('a numeric code that matches its own country decrements only that country', 1, $model->decreaseNumItems('12'));
pin('only the numeric-coded country moved', $expect(array('12' => 1, 'US' => 4)), $rows());

/* ----------------------------------------------------------------------------
 * setNumItems() — one UPDATE. True unless the database fails, so an unknown
 * country (0 rows) is true too. No length guard at all.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — set and overwrite');

$truncate();
pin('setting a country returns bool true', true, $model->setNumItems('US', 7));
pin('the country has that count', $expect(array('US' => 7)), $rows());
pin('setting it again returns bool true', true, $model->setNumItems('US', 3));
pin('the count was overwritten, not incremented', $expect(array('US' => 3)), $rows());
pin('re-setting the same value still returns bool true', true, $model->setNumItems('US', 3));
pin('the count is unchanged', $expect(array('US' => 3)), $rows());

harness_section('setNumItems — the count is cast to int');

pin('a numeric-prefixed string returns bool true', true, $model->setNumItems('US', '9abc'));
pin('and stores its integer prefix', $expect(array('US' => 9)), $rows());
pin('a null count returns bool true', true, $model->setNumItems('US', null));
pin('and stores 0', $expect(array()), $rows());

harness_section('setNumItems — a negative count');

// i_num_items is UNSIGNED, so -1 cannot be stored. Loose mode clamps it to 0 and
// reports success; strict mode refuses the write. The count already reads 0 either way.
if (harness_strict_writes()) {
    pin('a negative count is rejected', false, $quiet(static function () use ($model) {
        return $model->setNumItems('US', -1);
    }));
} else {
    pin('a negative count still reports success', true, $model->setNumItems('US', -1));
}
pin('the count reads 0', $expect(array()), $rows());

harness_section('setNumItems — a country that does not exist');

$seedStat('US', 5);
pin('an unknown country returns bool true (0 rows changed)', true, $model->setNumItems('XX', 3));
pin('an over-long code returns bool true', true, $model->setNumItems('TOOLONG', 3));
pin('a null code returns bool true', true, $model->setNumItems(null, 3));
pin('none of them wrote anything', $expect(array('US' => 5)), $rows());
pin('a failed update returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model) {
        return $model->setNumItems('US', 3);
    }
));

harness_section('setNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->setNumItems('US', 2);
}));
pin('the counted call landed', $expect(array('US' => 2)), $rows());

harness_section('setNumItems — numeric codes');

pin('an int code that matches a country succeeds', true, $model->setNumItems(12, 5));
pin('the string form addresses the same country', true, $model->setNumItems('12', 6));
pin('one count for it, holding the last value', $expect(array('12' => 6, 'US' => 2)), $rows());
pin('the string "0" matches no country and changes nothing', true, $model->setNumItems('0', 9));
pin('so no alphabetic country was touched', $expect(array('12' => 6, 'US' => 2)), $rows());

/* ----------------------------------------------------------------------------
 * findByCountryCode() — a country's count under the old stats-row keys, or false
 * when there is no such country.
 * ------------------------------------------------------------------------- */
harness_section('findByCountryCode');

$truncate();
$seedStat('US', 8);
$found = $model->findByCountryCode('US');
check('a known country returns an array', is_array($found));
pin('with both keys as strings', array('fk_c_country_code' => 'US', 'i_num_items' => '8'), $found);
check('every value is a string (C4)', is_array($found) && all_values_string($found));
pin(
    'a country with no listings has a count of "0"',
    array('fk_c_country_code' => 'ES', 'i_num_items' => '0'),
    $model->findByCountryCode('ES')
);
pin('an unknown country returns bool false', false, $model->findByCountryCode('XX'));
pin('a null code returns bool false', false, $quiet(static function () use ($model) {
    return $model->findByCountryCode(null);
}));

/* ----------------------------------------------------------------------------
 * listCountries() — the listing, read from t_country alone. Every country has a
 * count (default 0), so '>=' lists every country and '>' those with listings. Both arguments are validated in the
 * method itself, one against a hardcoded operator list and one against a
 * hardcoded pattern, and both fall back to their defaults when they fail.
 * ------------------------------------------------------------------------- */
harness_section('listCountries — shape and default ordering');

$truncate();
$seedStat('US', 3);
$seedStat('ES', 0);
$seedStat('12', 2);

$list = $model->listCountries();
pin('the default lists only countries with a positive count', 2, count($list));
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

$countAll = count($model->listCountries('>='));
check('">=" lists every country, including the zero-count one', $countAll >= 3);
pin('"=" selects only the zero-count countries', $countAll - 2, count($model->listCountries('=')));
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

harness_section('listCountries — no country has listings');

$truncate();
pin('"> 0" gives an empty array', array(), $model->listCountries());
pin('but ">=" still lists every country', $countAll, count($model->listCountries('>=')));

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
