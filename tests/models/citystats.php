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
 * Characterization pins for the CityStats model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * increaseNumItems()/decreaseNumItems()/setNumItems()/deleteByRegion()/
 * listCities()/calculateNumItems()/updateAllStats() move to the parameterized
 * query layer.
 *
 * CityStats and RegionStats are near-identical mirrors, and tests/models/
 * regionstats.php is the companion to this file; the sections below are kept in
 * the same order so the two can be diffed. Where they genuinely differ, the pin
 * says so:
 *
 *  - deleteByRegion() exists only here. It is a DELETE whose WHERE is an IN
 *    subquery over t_city, so it can never go through the query builder.
 *  - listCities() LEFT JOINs t_city, so a counter row with no city behind it is
 *    still listed with null name and slug. listRegions() joins through a
 *    comma/CROSS JOIN plus an equality WHERE — an inner join — and drops it.
 *  - listCities() always selects the same four aliased columns. listRegions()
 *    picks its projection from the ORDER BY and has a third branch that selects
 *    nothing at all, so the query falls through to SELECT *.
 *  - listCities()'s region filter is interpolated raw behind an is_numeric()
 *    guard, so it never went through escape(). listRegions()'s country filter
 *    did, which is why that file carries an escape()-coercion pin and this one
 *    does not.
 *
 * Four things shape the pins below, each measured rather than assumed.
 *
 * 1. Every method that reaches a counter row guards its argument with
 *    is_numeric() or casts it with (int), so no caller value can ever reach the
 *    database malformed. The reachable failure is the foreign key, and the
 *    failure branches of the reads are only reachable by taking a table away —
 *    which is what $withTableMissing() below does.
 *
 * 2. Legacy built increaseNumItems()'s SQL through sprintf('%d'), truncating a
 *    fractional id before MySQL ever saw it, and setNumItems()/deleteByRegion()/
 *    calculateNumItems()/updateAllStats() cast with (int). A bound parameter has
 *    to reproduce those casts explicitly, so each one is pinned with a value that
 *    would land somewhere else without it.
 *
 * 3. Strict SQL mode is stripped from every connection, so an out-of-range
 *    counter is clamped rather than rejected: setNumItems($id, -1) reports
 *    success and stores 0.
 *
 * 4. calculateAllStats() (private, reached through updateAllStats) builds its
 *    premium/expiry test WITHOUT parentheses, so `||` splits the whole WHERE in
 *    two and the city filter only constrains one half. Two consequences are
 *    pinned as latent product bugs, deliberately preserved by the conversion and
 *    flagged for separate triage: an inactive listing is counted as long as it
 *    has not expired, and a premium listing in a city that was NOT asked for gets
 *    a counter row written anyway. calculateNumItems() has the parentheses and
 *    disagrees with calculateAllStats() on the same fixture.
 *
 * findByCityId() is pure delegation to DAO::findByPrimaryKey(), a base method
 * this effort does not convert; it is pinned as a regression guard only.
 *
 * Usage:  php tests/models/citystats.php          (standalone, own scratch database)
 *         php tests/run-models.php citystats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_citystats');

/*
 * listCities() reaches osc_cache_get()/osc_cache_set() and osc_base_url(). The
 * cache helpers are real (hCache.php below) because the caching IS part of the
 * contract here; osc_base_url() and osc_plugins_path() are stood in for.
 *
 * They cannot be taken from hDefines.php: that file also declares
 * osc_uploads_path() with no function_exists guard, and the shared scratch-db
 * bootstrap has always defined a stand-in for it before any model test file is
 * loaded — so requiring hDefines.php from a model test is a redeclare fatal no
 * matter what this file does. Both stand-ins are guarded and return exactly what
 * the real helpers return in a test process (no filters registered, no plugins).
 */
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';   // osc_add_hook, used by hCache at load
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php'; // osc_language
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';     // osc_current_user_locale
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';

/*
 * osc_cache_get() suffixes its key with osc_current_user_locale(), which reads a
 * preference; the Preference singleton loads the whole table on construction.
 * Warm it here so that one-off query is never attributed to a query-count pin.
 */
Preference::newInstance();

$model     = CityStats::newInstance();
$table     = DB_TABLE_PREFIX . 't_city_stats';
$locations = DB_TABLE_PREFIX . 't_item_location';
$cache     = Object_Cache_Factory::newInstance();

seed_country($admin, 'US', 'United States');

$regionOne = seed_region($admin, 'US', 'Alpha');
$regionTwo = seed_region($admin, 'US', 'Bravo');

$cityAlpha   = seed_city($admin, $regionOne, 'Aville');
$cityBravo   = seed_city($admin, $regionOne, 'Bville');
$cityCharlie = seed_city($admin, $regionTwo, 'Cville');
$cityDelta   = seed_city($admin, $regionTwo, 'Dville');

/**
 * The whole counter table as "id=count,id=count", ordered by id.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string rows the legacy path produced.
 */
$rows = static function () use ($admin, $table): string {
    $out = array();
    $res = $admin->query("SELECT fk_i_city_id, i_num_items FROM $table ORDER BY fk_i_city_id");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['fk_i_city_id'] . '=' . $r['i_num_items'];
    }
    $res->free();

    return implode(',', $out);
};

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** Seed one counter row with raw mysqli, never through the code under test. */
$seedStat = static function (int $cityId, int $num) use ($admin, $table): void {
    $admin->query("INSERT INTO $table (fk_i_city_id, i_num_items) VALUES ($cityId, $num)");
};

/** Empty the request-lifetime object cache, which the runner shares across files. */
$flush = static function () use ($cache): void {
    $cache->flush();
};

/** Run $fn with warnings silenced, the way the failing-query pins need. */
$quiet = static function (callable $fn) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $out  = $fn();
    error_reporting($prev);

    return $out;
};

/**
 * Run $fn with one table renamed out of the way, so every query touching it
 * fails. Every argument this model accepts is guarded by is_numeric() or cast
 * with (int) before it reaches SQL, so a missing table is the only way to reach
 * the error-fallback branches — and those branches are the whole reason the
 * conversion needs a catch.
 *
 * @return mixed Whatever $fn returned
 */
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
 * Surface (C2). Public and protected methods only — a private helper the
 * conversion is allowed to add must not make this pin fail.
 * ------------------------------------------------------------------------- */
harness_section('CityStats: public surface');

pin(
    'increaseNumItems signature is unchanged',
    'public increaseNumItems($cityId)',
    harness_method_signature('CityStats', 'increaseNumItems')
);
pin(
    'decreaseNumItems signature is unchanged',
    'public decreaseNumItems($cityId)',
    harness_method_signature('CityStats', 'decreaseNumItems')
);
pin(
    'setNumItems signature is unchanged',
    'public setNumItems($cityID, $numItems)',
    harness_method_signature('CityStats', 'setNumItems')
);
pin(
    'findByCityId signature is unchanged',
    'public findByCityId($cityId)',
    harness_method_signature('CityStats', 'findByCityId')
);
pin(
    'deleteByRegion signature is unchanged',
    'public deleteByRegion($regionId)',
    harness_method_signature('CityStats', 'deleteByRegion')
);
pin(
    'listCities signature is unchanged, defaults included',
    "public listCities(\$region = NULL, \$zero = '>', \$order = 'city_name ASC')",
    harness_method_signature('CityStats', 'listCities')
);
pin(
    'calculateNumItems signature is unchanged',
    'public calculateNumItems($cityId)',
    harness_method_signature('CityStats', 'calculateNumItems')
);
pin(
    'updateAllStats signature is unchanged, array type hint included',
    'public updateAllStats(array $cities)',
    harness_method_signature('CityStats', 'updateAllStats')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('CityStats', 'newInstance')
);
check('CityStats still extends DAO', is_subclass_of('CityStats', 'DAO'));
check('newInstance() is a singleton', CityStats::newInstance() === $model);
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_i_city_id', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('fk_i_city_id', 'i_num_items'), $model->getFields());
pin(
    'the model declares exactly these nine public methods of its own',
    array(
        '__construct',
        'calculateNumItems',
        'decreaseNumItems',
        'deleteByRegion',
        'findByCityId',
        'increaseNumItems',
        'listCities',
        'newInstance',
        'setNumItems',
        'updateAllStats',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('CityStats')),
        array(
            '__construct',
            'calculateNumItems',
            'decreaseNumItems',
            'deleteByRegion',
            'findByCityId',
            'increaseNumItems',
            'listCities',
            'newInstance',
            'setNumItems',
            'updateAllStats',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * increaseNumItems() — the upsert counter. dao->query() on a write returns bool
 * true, so this method is bool-valued despite what its docblock claims.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — first call for a city');

$truncate();
pin('a fresh city returns bool true', true, $model->increaseNumItems($cityAlpha));
pin('the row was created at 1', $cityAlpha . '=1', $rows());

harness_section('increaseNumItems — subsequent calls');

pin('a repeat call also returns bool true', true, $model->increaseNumItems($cityAlpha));
pin('the counter incremented rather than duplicating the row', $cityAlpha . '=2', $rows());
pin('a second city is independent', true, $model->increaseNumItems($cityBravo));
pin('both rows now exist', $cityAlpha . '=2,' . $cityBravo . '=1', $rows());

harness_section('increaseNumItems — query cost');

pin('a fresh insert costs exactly one query', 1, harness_query_count(static function () use ($model, $cityCharlie) {
    $model->increaseNumItems($cityCharlie);
}));
pin('an increment costs exactly one query', 1, harness_query_count(static function () use ($model, $cityCharlie) {
    $model->increaseNumItems($cityCharlie);
}));
pin(
    'the two counted calls landed',
    $cityAlpha . '=2,' . $cityBravo . '=1,' . $cityCharlie . '=2',
    $rows()
);

harness_section('increaseNumItems — the is_numeric guard rejects before any query');

pin('a non-numeric id returns bool false', false, $model->increaseNumItems('abc'));
pin('a null id returns bool false', false, $model->increaseNumItems(null));
pin('bool true is not numeric and returns bool false', false, $model->increaseNumItems(true));
pin('an empty string returns bool false', false, $model->increaseNumItems(''));
pin('an array returns bool false', false, $model->increaseNumItems(array($cityAlpha)));
pin('a rejected id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('abc');
}));
pin(
    'none of them wrote anything',
    $cityAlpha . '=2,' . $cityBravo . '=1,' . $cityCharlie . '=2',
    $rows()
);

harness_section('increaseNumItems — a numeric id with no city behind it (foreign key)');

pin('an unknown city returns bool false', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(999);
}));
pin('the rejected insert still costs one query', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->increaseNumItems(999);
    });
}));
pin(
    'nothing was written for it',
    $cityAlpha . '=2,' . $cityBravo . '=1,' . $cityCharlie . '=2',
    $rows()
);

harness_section('increaseNumItems — sprintf("%d") truncated a fractional id before the query');

/* Legacy interpolated the id through sprintf('%d', ...), so "1.7" reached MySQL
 * as 1 and incremented that city. A bound parameter is checked as given, so the
 * conversion needs the same truncation spelled out as an (int) cast — without it
 * the foreign-key check rejects the value and this pin flips to false. */
pin(
    'a fractional id is truncated to its integer part and succeeds',
    true,
    $model->increaseNumItems($cityAlpha . '.7')
);
pin(
    'and it landed on the truncated city, not a new row',
    $cityAlpha . '=3,' . $cityBravo . '=1,' . $cityCharlie . '=2',
    $rows()
);
pin('a numeric string id works like the int form', true, $model->increaseNumItems((string)$cityBravo));
pin(
    'still one row for it',
    $cityAlpha . '=3,' . $cityBravo . '=2,' . $cityCharlie . '=2',
    $rows()
);

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — read then conditional update. Returns the affected-row
 * count, so 1 and 0 are both success values and false means "no row to work on".
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a city with a positive counter');

$truncate();
$seedStat($cityAlpha, 3);
pin('decrementing returns int 1, the affected-row count', 1, $model->decreaseNumItems($cityAlpha));
pin('the counter went down by one', $cityAlpha . '=2', $rows());

harness_section('decreaseNumItems — a counter already at zero');

$truncate();
$seedStat($cityAlpha, 0);
pin(
    'a zero counter returns int 0 — the row exists, the guarded update matched nothing',
    0,
    $model->decreaseNumItems($cityAlpha)
);
pin('the counter did not go negative', $cityAlpha . '=0', $rows());

harness_section('decreaseNumItems — no row for that city');

pin('a city with no counter row returns bool false', false, $model->decreaseNumItems($cityBravo));
pin('a missing row costs exactly one query (the lookup only)', 1, harness_query_count(
    static function () use ($model, $cityBravo) {
        $model->decreaseNumItems($cityBravo);
    }
));
pin('nothing was created for it', $cityAlpha . '=0', $rows());

harness_section('decreaseNumItems — query cost when the row exists');

$truncate();
$seedStat($cityAlpha, 5);
pin('a decrement costs two queries: lookup then update', 2, harness_query_count(
    static function () use ($model, $cityAlpha) {
        $model->decreaseNumItems($cityAlpha);
    }
));
pin('the decrement landed', $cityAlpha . '=4', $rows());

harness_section('decreaseNumItems — the is_numeric guard rejects before any query');

pin('a non-numeric id returns bool false', false, $model->decreaseNumItems('abc'));
pin('a null id returns bool false', false, $model->decreaseNumItems(null));
pin('an array returns bool false', false, $model->decreaseNumItems(array($cityAlpha)));
pin('bool true returns bool false', false, $model->decreaseNumItems(true));
pin('a rejected id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('abc');
}));
pin('nothing changed', $cityAlpha . '=4', $rows());

harness_section('decreaseNumItems — a fractional id is compared as given, not truncated');

/* Unlike increaseNumItems, the lookup goes through where(), whose escape() hands
 * a numeric value straight through: MySQL compares the INT column against the
 * decimal, finds no row, and the method returns false before any write. */
pin(
    'a fractional id finds no counter row and returns bool false',
    false,
    $model->decreaseNumItems($cityAlpha . '.7')
);
pin('nothing changed', $cityAlpha . '=4', $rows());

harness_section('decreaseNumItems — the failed-lookup branch');

/* The only reachable failure: every argument shape is caught by is_numeric()
 * above, so the table has to go away. The lookup fails, the row is never seen,
 * and the method returns bool false after exactly one query — the same value the
 * "no row" case gives, which is why the conversion can absorb it identically. */
pin('a failed lookup returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model, $cityAlpha) {
        return $model->decreaseNumItems($cityAlpha);
    }
));

/* ----------------------------------------------------------------------------
 * setNumItems() — absolute upsert. Both arguments are cast to int in the method
 * itself, so no guard is needed and no argument can reach SQL malformed.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — insert and overwrite');

$truncate();
pin('setting a city with no row returns bool true', true, $model->setNumItems($cityBravo, 7));
pin('the row was created with that count', $cityBravo . '=7', $rows());
pin('setting it again returns bool true', true, $model->setNumItems($cityBravo, 3));
pin('the count was overwritten, not incremented', $cityBravo . '=3', $rows());
pin('re-setting the same value still returns bool true', true, $model->setNumItems($cityBravo, 3));
pin('the row is unchanged', $cityBravo . '=3', $rows());

harness_section('setNumItems — both arguments are cast to int');

pin('a numeric-prefixed count returns bool true', true, $model->setNumItems($cityBravo, '9abc'));
pin('and stores its integer prefix', $cityBravo . '=9', $rows());
pin('a null count returns bool true', true, $model->setNumItems($cityBravo, null));
pin('and stores 0', $cityBravo . '=0', $rows());
pin('a fractional city id is truncated onto that city', true, $model->setNumItems($cityBravo . '.9', 4));
pin('and it landed on the truncated row', $cityBravo . '=4', $rows());

harness_section('setNumItems — a negative count is clamped, not rejected (strict mode is off)');

pin('a negative count still reports success', true, $model->setNumItems($cityBravo, -1));
pin('the unsigned column clamped it to 0', $cityBravo . '=0', $rows());

harness_section('setNumItems — rejected by the database');

pin('an unknown city returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems(999, 3);
}));
pin('a non-numeric id casts to 0, which has no city, and returns bool false', false, $quiet(
    static function () use ($model) {
        return $model->setNumItems('abc', 3);
    }
));
pin('a null id casts to 0 and returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems(null, 3);
}));
pin('none of them wrote anything', $cityBravo . '=0', $rows());

harness_section('setNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $cityBravo) {
    $model->setNumItems($cityBravo, 2);
}));
pin('the counted call landed', $cityBravo . '=2', $rows());
pin('a rejected call costs one query too', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->setNumItems(999, 3);
    });
}));

/* ----------------------------------------------------------------------------
 * findByCityId() — regression guard only; it delegates straight to
 * DAO::findByPrimaryKey(), a base method out of scope for this effort.
 * ------------------------------------------------------------------------- */
harness_section('findByCityId — regression guard (not converted)');

$truncate();
$seedStat($cityAlpha, 8);
$found = $model->findByCityId($cityAlpha);
check('a known city returns an array', is_array($found));
pin(
    'with both columns as strings',
    array('fk_i_city_id' => (string)$cityAlpha, 'i_num_items' => '8'),
    $found
);
check('every value is a string (C4)', is_array($found) && all_values_string($found));
pin('an unknown city returns bool false', false, $model->findByCityId(999));
pin('a null id returns bool false', false, $quiet(static function () use ($model) {
    return $model->findByCityId(null);
}));

/* ----------------------------------------------------------------------------
 * deleteByRegion() — CityStats only. A DELETE whose WHERE is an IN subquery over
 * t_city; there is no RegionStats counterpart. dao->query() on a write returns
 * bool true, and the region id is cast to int, so every argument shape lands on
 * true and the false is reachable only through a failed query.
 * ------------------------------------------------------------------------- */
harness_section('deleteByRegion — the cascade');

$truncate();
$seedStat($cityAlpha, 5);
$seedStat($cityBravo, 4);
$seedStat($cityCharlie, 3);
$seedStat($cityDelta, 2);
pin('deleting a region returns bool true', true, $model->deleteByRegion($regionOne));
pin(
    'only the counter rows of that region\'s cities are gone',
    $cityCharlie . '=3,' . $cityDelta . '=2',
    $rows()
);
pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $regionTwo) {
    $model->deleteByRegion($regionTwo);
}));
pin('and that emptied the table', '', $rows());

harness_section('deleteByRegion — a region with nothing to delete still reports success');

$seedStat($cityAlpha, 1);
pin('an unknown region returns bool true', true, $model->deleteByRegion(999));
pin('a region with no cities left returns bool true', true, $model->deleteByRegion($regionTwo));
pin('nothing was deleted', $cityAlpha . '=1', $rows());

harness_section('deleteByRegion — the argument is cast to int, so nothing reaches SQL malformed');

pin('a non-numeric region casts to 0 and returns bool true', true, $model->deleteByRegion('abc'));
pin('a null region casts to 0 and returns bool true', true, $quiet(static function () use ($model) {
    return $model->deleteByRegion(null);
}));
pin('a fractional region is truncated', true, $model->deleteByRegion($regionOne . '.9'));
pin('and the truncated form really did delete', '', $rows());

harness_section('deleteByRegion — the failed-query branch returns bool false');

$seedStat($cityAlpha, 1);
pin('a failed delete returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model, $regionOne) {
        return $model->deleteByRegion($regionOne);
    }
));
pin('and the row survived', $cityAlpha . '=1', $rows());

/* ----------------------------------------------------------------------------
 * listCities() — the joined listing, cached. Both the comparison operator and
 * the ORDER BY are validated in the method itself, one against a hardcoded
 * operator list and one against a hardcoded pattern, and both fall back to their
 * defaults when they fail. Unlike listRegions() the projection is fixed.
 * ------------------------------------------------------------------------- */
harness_section('listCities — shape and default ordering');

$truncate();
$flush();
$seedStat($cityAlpha, 5);
$seedStat($cityBravo, 0);
$seedStat($cityCharlie, 3);

$list = $model->listCities();
pin('the default lists only cities with a positive counter', 2, count($list));
pin(
    'the row shape is the four aliased columns',
    array('city_id', 'items', 'city_name', 'city_slug'),
    array_keys($list[0])
);
pin(
    'the first row is the full aliased projection',
    array(
        'city_id'   => (string)$cityAlpha,
        'items'     => '5',
        'city_name' => 'Aville',
        'city_slug' => 'aville',
    ),
    $list[0]
);
check('every value in every row is a string (C4)', all_rows_string($list));
pin(
    'the default order is city_name ASC',
    'Aville,Cville',
    implode(',', array_column($list, 'city_name'))
);

harness_section('listCities — the projection does not change with the order');

$flush();
$list = $model->listCities(null, '>', 'items DESC');
pin(
    'ordering by items keeps all four columns, unlike listRegions',
    array('city_id', 'items', 'city_name', 'city_slug'),
    array_keys($list[0])
);
pin('and orders by the counter alias', 'Aville,Cville', implode(',', array_column($list, 'city_name')));
$flush();
$list = $model->listCities(null, '>', 's_name ASC');
pin(
    'an unrelated but valid order column keeps the same four columns too',
    array('city_id', 'items', 'city_name', 'city_slug'),
    array_keys($list[0])
);

harness_section('listCities — the LEFT JOIN keeps a counter row with no city behind it');

/* Foreign-key checks are off on the ADMIN session only, so an orphan counter row
 * can be seeded here even though the model could never create one. This is the
 * clearest structural difference from RegionStats, whose comma/CROSS JOIN plus
 * equality WHERE is an inner join and drops the same row. */
$admin->query("INSERT INTO $table (fk_i_city_id, i_num_items) VALUES (999, 7)");
$flush();
$list = $model->listCities();
pin('the orphan counter row is listed', 3, count($list));
pin(
    'with its name and slug as SQL NULL, sorted first',
    array('city_id' => '999', 'items' => '7', 'city_name' => null, 'city_slug' => null),
    $list[0]
);
check('nulls still satisfy the all-strings-or-null row contract (C4)', all_rows_string($list));
$admin->query("DELETE FROM $table WHERE fk_i_city_id = 999");

harness_section('listCities — the comparison argument');

$flush();
pin('">=" includes the zero-counter city', 3, count($model->listCities(null, '>=')));
$flush();
pin('"=" selects only the zero-counter city', 1, count($model->listCities(null, '=')));
$flush();
pin('"<" matches nothing and returns an empty array', array(), $model->listCities(null, '<'));
$flush();
pin('an unlisted operator falls back to ">"', 2, count($model->listCities(null, 'bogus')));
$flush();
pin('an empty operator falls back to ">"', 2, count($model->listCities(null, '')));
$flush();
pin('a null operator falls back to ">"', 2, count($quiet(static function () use ($model) {
    return $model->listCities(null, null);
})));

harness_section('listCities — the order argument');

$flush();
pin(
    'city_name DESC reverses the default',
    'Cville,Aville',
    implode(',', array_column($model->listCities(null, '>', 'city_name DESC'), 'city_name'))
);
$flush();
pin(
    'the direction is matched case-insensitively',
    'Cville,Aville',
    implode(',', array_column($model->listCities(null, '>', 'city_name desc'), 'city_name'))
);
$flush();
pin(
    'a malformed order falls back to city_name ASC',
    'Aville,Cville',
    implode(',', array_column($model->listCities(null, '>', 'bad order'), 'city_name'))
);
$flush();
pin(
    'a null order falls back to city_name ASC',
    'Aville,Cville',
    implode(',', array_column($quiet(static function () use ($model) {
        return $model->listCities(null, '>', null);
    }), 'city_name'))
);

harness_section('listCities — the region filter is applied only when it is numeric');

$flush();
pin(
    'a region id restricts the listing to its cities',
    'Aville,Bville',
    implode(',', array_column($model->listCities($regionOne, '>='), 'city_name'))
);
$flush();
pin(
    'the numeric-string form behaves identically',
    'Aville,Bville',
    implode(',', array_column($model->listCities((string)$regionOne, '>='), 'city_name'))
);
$flush();
pin(
    'the other region has its own city',
    'Cville',
    implode(',', array_column($model->listCities($regionTwo, '>='), 'city_name'))
);

/* The region filter is the only clause that carries a value, so it is the only
 * form of this query that compiles to a PREPARED statement — and a prepared read
 * hands back native ints for the counter column where the unfiltered form does
 * not. Both shapes have to look identical to a caller, so the filtered one gets
 * its own C4 pin rather than relying on the unfiltered pins above. */
$flush();
$filtered = $model->listCities($regionOne, '>=');
check('a filtered listing is all strings too (C4)', all_rows_string($filtered));
pin(
    'including the counter column, which is an INT in the schema',
    array(
        'city_id'   => (string)$cityAlpha,
        'items'     => '5',
        'city_name' => 'Aville',
        'city_slug' => 'aville',
    ),
    $filtered[0]
);
$flush();
pin('an unknown region returns an empty array', array(), $model->listCities(999, '>='));
$flush();
pin(
    'a NON-numeric region is silently ignored and every city is listed',
    3,
    count($model->listCities('abc', '>='))
);
$flush();
pin(
    'the null default is non-numeric too, so it filters nothing',
    3,
    count($model->listCities(null, '>='))
);
$flush();
pin(
    'an exponent form is numeric and is applied as the number it denotes',
    'Aville,Bville',
    implode(',', array_column($model->listCities('1e0', '>='), 'city_name'))
);

harness_section('listCities — the error fallback is reachable through the order argument');

/* The pattern admits any identifier-shaped column, so a well-formed order over a
 * column that does not exist is a failed query, and the method's own
 * false-branch turns it into an empty array — NOT an exception. */
$flush();
pin('an unknown but well-formed order column returns an empty array', array(), $quiet(
    static function () use ($model) {
        return $model->listCities(null, '>', 'nope ASC');
    }
));

harness_section('listCities — the cache');

$flush();
pin('a cold call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->listCities();
}));
pin('the second call is served from the cache and costs none', 0, harness_query_count(
    static function () use ($model) {
        $model->listCities();
    }
));
pin(
    'and returns the identical rows',
    $model->listCities(),
    $model->listCities()
);
pin('a different argument set is a different cache key', 1, harness_query_count(static function () use ($model) {
    $model->listCities(null, '>=');
}));

harness_section('listCities — a failed query is never cached');

$flush();
pin('the failing form costs one query', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->listCities(null, '>', 'nope ASC');
    });
}));
pin('and costs one query again, because the empty result was not stored', 1, harness_query_count(
    static function () use ($model, $quiet) {
        $quiet(static function () use ($model) {
            $model->listCities(null, '>', 'nope ASC');
        });
    }
));

harness_section('listCities — a stale cache entry survives a write, by design');

/* Nothing in this model invalidates the listing cache, so a counter written
 * after a cached read is not visible until the entry expires. That staleness is
 * the existing contract and the conversion does not change it. */
$flush();
$before = $model->listCities();
$model->setNumItems($cityBravo, 11);
pin('the listing still shows the pre-write result', $before, $model->listCities());
$flush();
pin('and picks the write up once the cache is cleared', 3, count($model->listCities()));
$model->setNumItems($cityBravo, 0);

harness_section('listCities — an empty counter table');

$truncate();
$flush();
pin('no counter rows at all gives an empty array', array(), $model->listCities());

/* ----------------------------------------------------------------------------
 * calculateNumItems() — the recount aggregate over t_item_location/t_item/
 * t_category. COUNT(*) with no GROUP BY always returns one row, so there is no
 * zero-row branch: a city with nothing to count returns the STRING '0', while a
 * failed query returns INT 0.
 * ------------------------------------------------------------------------- */
harness_section('calculateNumItems — setup');

$catId  = seed_category($admin, 'Motors');
$userId = seed_user($admin);
$itemA1 = seed_item($admin, $catId, $userId, 'Aville one');
$itemA2 = seed_item($admin, $catId, $userId, 'Aville two');
$itemA3 = seed_item($admin, $catId, $userId, 'Aville inactive', 10.0, 0, 1);
$itemB1 = seed_item($admin, $catId, $userId, 'Bville one');

$place = static function (int $itemId, int $cityId) use ($admin, $locations): void {
    $admin->query("UPDATE $locations SET fk_i_city_id = $cityId WHERE fk_i_item_id = $itemId");
};
$place($itemA1, $cityAlpha);
$place($itemA2, $cityAlpha);
$place($itemA3, $cityAlpha);
$place($itemB1, $cityBravo);

pin('two active items are counted, the inactive one is not', '2', $model->calculateNumItems($cityAlpha));
check('the count is a string, not an int (C4)', is_string($model->calculateNumItems($cityAlpha)));
pin('the other city counts its own item', '1', $model->calculateNumItems($cityBravo));
pin('a city with no items returns the string "0"', '0', $model->calculateNumItems(999));

harness_section('calculateNumItems — the argument is cast to int, so nothing reaches SQL malformed');

pin('a non-numeric id casts to 0 and returns the string "0"', '0', $model->calculateNumItems('abc'));
pin('a null id casts to 0 and returns the string "0"', '0', $quiet(static function () use ($model) {
    return $model->calculateNumItems(null);
}));
pin('a fractional id is truncated onto that city', '2', $model->calculateNumItems($cityAlpha . '.9'));
/* (int) on a non-empty array is 1, so this counts city 1 rather than failing. */
pin('an array casts to int 1 rather than failing the query', '2', $quiet(static function () use ($model) {
    return $model->calculateNumItems(array($cityAlpha));
}));

harness_section('calculateNumItems — the eligibility rules');

$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemA1");
pin('an expired item drops out', '1', $model->calculateNumItems($cityAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_premium = 1 WHERE pk_i_id = $itemA1");
pin('unless it is premium, which is counted however old it is', '2', $model->calculateNumItems($cityAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_spam = 1 WHERE pk_i_id = $itemA2");
pin('a spam item drops out', '1', $model->calculateNumItems($cityAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 0');
pin('a disabled category takes all of its items with it', '0', $model->calculateNumItems($cityAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 1');
pin('and re-enabling brings them back', '1', $model->calculateNumItems($cityAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_spam = 0 WHERE pk_i_id = $itemA2");
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 0, dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $itemA1");
pin('the fixture is back where it started', '2', $model->calculateNumItems($cityAlpha));

harness_section('calculateNumItems — the failed-query branch returns int 0');

/* Every argument is cast, so the only way to reach the false-branch is to take a
 * table away. It returns INT 0 — a different value and a different type from the
 * string '0' a legitimate zero match gives. */
pin('a failed query returns int 0, not the string "0"', 0, $withTableMissing(
    $locations,
    static function () use ($model, $cityAlpha) {
        return $model->calculateNumItems($cityAlpha);
    }
));

harness_section('calculateNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $cityAlpha) {
    $model->calculateNumItems($cityAlpha);
}));

/* ----------------------------------------------------------------------------
 * updateAllStats() — the batch recount. It runs the private calculateAllStats()
 * aggregate and writes every result back in ONE multi-row upsert, returning the
 * bool that dao->query() gives a write.
 *
 * calculateAllStats() is private, so it is characterized here only through what
 * updateAllStats() writes — which is also where its two latent bugs show up.
 * ------------------------------------------------------------------------- */
harness_section('updateAllStats — an empty list short-circuits');

$truncate();
pin('an empty array returns bool false', false, $model->updateAllStats(array()));
pin('and costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->updateAllStats(array());
}));
pin('nothing was written', '', $rows());

harness_section('updateAllStats — the happy path');

/* Aville holds three listings, one of them inactive, and the batch aggregate
 * counts all three — see the LATENT BUG sections below for why. The numbers here
 * are what the code produces, not what the single-city recount would say. */
pin('a list of known cities returns bool true', true, $model->updateAllStats(array($cityAlpha, $cityBravo)));
pin(
    'both counters were written',
    $cityAlpha . '=3,' . $cityBravo . '=1',
    $rows()
);
pin('one call costs two queries: the aggregate then the upsert', 2, harness_query_count(
    static function () use ($model, $cityAlpha, $cityBravo) {
        $model->updateAllStats(array($cityAlpha, $cityBravo));
    }
));

harness_section('updateAllStats — an existing counter is overwritten, not incremented');

$admin->query("UPDATE $table SET i_num_items = 99 WHERE fk_i_city_id = $cityAlpha");
pin('rewriting returns bool true', true, $model->updateAllStats(array($cityAlpha)));
pin('and the stale value was replaced', $cityAlpha . '=3,' . $cityBravo . '=1', $rows());

harness_section('updateAllStats — a city with nothing to count is written as zero');

pin('a city with no items returns bool true', true, $model->updateAllStats(array($cityCharlie)));
pin(
    'and gets an explicit zero row',
    $cityAlpha . '=3,' . $cityBravo . '=1,' . $cityCharlie . '=0',
    $rows()
);

harness_section('updateAllStats — ids are cast to int on the way into the upsert');

pin('a numeric string id works', true, $model->updateAllStats(array((string)$cityAlpha)));
pin('a duplicated id collapses to one row', true, $model->updateAllStats(array($cityAlpha, $cityAlpha)));
pin(
    'the table is unchanged by either',
    $cityAlpha . '=3,' . $cityBravo . '=1,' . $cityCharlie . '=0',
    $rows()
);

harness_section('updateAllStats — rejected by the foreign key');

pin('an unknown city id returns bool false', false, $quiet(static function () use ($model) {
    return $model->updateAllStats(array(999));
}));
pin('a non-numeric member casts to 0, which has no city, and returns bool false', false, $quiet(
    static function () use ($model) {
        return $model->updateAllStats(array('abc'));
    }
));
pin(
    'neither wrote anything',
    $cityAlpha . '=3,' . $cityBravo . '=1,' . $cityCharlie . '=0',
    $rows()
);

harness_section('updateAllStats — LATENT BUG: an inactive listing is counted');

/* calculateAllStats() writes its premium/expiry test as
 *     ... AND b_spam = 0 AND b_premium = 1 || dt_expiration >= '<now>' AND ...
 * with no parentheses. `||` binds looser than AND, so the whole WHERE splits in
 * two: the b_active/b_enabled/b_spam half applies only to premium listings, and
 * an ordinary unexpired listing is counted whatever its b_active flag says.
 * calculateNumItems() has the parentheses and therefore disagrees with
 * calculateAllStats() on the same fixture.
 *
 * Preserved deliberately — a conversion reproduces behaviour; changing it is a
 * separate decision with its own commit. Logged for triage. */
$truncate();
pin('the single-city recount excludes the inactive listing', '2', $model->calculateNumItems($cityAlpha));
pin('the batch recount runs', true, $model->updateAllStats(array($cityAlpha)));
pin('but writes 3 — the inactive listing is counted', $cityAlpha . '=3', $rows());

$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemA3");
pin('expiring the inactive listing removes it from the batch count', true, $model->updateAllStats(array($cityAlpha)));
pin('and the counter agrees with the single-city recount again', $cityAlpha . '=2', $rows());

harness_section('updateAllStats — LATENT BUG: a premium listing outside the requested set leaks in');

/* Same missing parentheses: the city filter sits in the right-hand half of the
 * `||`, so the left-hand (premium) half has no city restriction at all. A premium
 * listing anywhere in the database produces a GROUP BY row for ITS city, and
 * updateAllStats writes a counter for a city the caller never named. Preserved
 * deliberately and logged for triage. */
$truncate();
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 1, dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemB1");
pin('asking for one city returns bool true', true, $model->updateAllStats(array($cityAlpha)));
pin(
    'but a counter row was written for the other city too',
    $cityAlpha . '=2,' . $cityBravo . '=1',
    $rows()
);
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 0, dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $itemB1");
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $itemA3");

harness_section('updateAllStats — the failed-aggregate branch');

/* When the aggregate query fails, calculateAllStats returns an empty array
 * BEFORE its fill-with-zero loop, so updateAllStats sees "nothing calculated"
 * and returns false after exactly one query — the upsert never runs. */
$truncate();
pin('a failed aggregate returns bool false', false, $withTableMissing(
    $locations,
    static function () use ($model, $cityAlpha) {
        return $model->updateAllStats(array($cityAlpha));
    }
));
pin('and nothing was written', '', $rows());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/citystats.php */
