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
 * Pins for the CityStats model.
 *
 * Each city keeps its live listing count in t_city.i_num_items (default 0), so
 * there is no separate counter row: every city always has a count. The pins are
 * written against the observable behaviour (return values, stored counts, query
 * counts), not the SQL.
 *
 * tests/models/regionstats.php is the companion to this file; the sections are
 * kept in the same order so the two can be diffed. Where they differ, the pin
 * says so:
 *
 *  - deleteByRegion() exists only here. It sets the count of every city in the
 *    region to 0.
 *  - listCities() filters by region; listRegions() filters by country.
 *
 * What the pins fix:
 *
 * 1. increaseNumItems() and decreaseNumItems() are one UPDATE each, with no lookup
 *    first. increase returns true only when a row changed, so it is false for an
 *    unknown city; it never creates a row. decrease returns the affected-row
 *    count (0 for a count already at zero or an unknown city) and never goes
 *    below zero. A non-numeric id is rejected before any query.
 *
 * 2. setNumItems() is one UPDATE and is true unless the database fails, so it is
 *    also true for an unknown city (0 rows). Ids and counts are cast to int.
 *
 * 3. Whether an out-of-range count is clamped or rejected depends on the
 *    connection, so setNumItems($id, -1) is pinned both ways behind
 *    harness_strict_writes().
 *
 * 4. calculateAllStats() (private, reached through updateAllStats) agrees with
 *    calculateNumItems() on the same fixture, then writes every count with one
 *    UPDATE ... CASE.
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
Preference::getInstance();

$model     = CityStats::getInstance();
$table     = DB_TABLE_PREFIX . 't_city';
$locations = DB_TABLE_PREFIX . 't_item_location';
$cache     = \mindstellar\cache\CacheManager::getInstance();

seed_country($admin, 'US', 'United States');

$regionOne = seed_region($admin, 'US', 'Alpha');
$regionTwo = seed_region($admin, 'US', 'Bravo');

$cityAlpha   = seed_city($admin, $regionOne, 'Aville');
$cityBravo   = seed_city($admin, $regionOne, 'Bville');
$cityCharlie = seed_city($admin, $regionTwo, 'Cville');
$cityDelta   = seed_city($admin, $regionTwo, 'Dville');

/**
 * Every seeded city's count as "id=count,id=count", ordered by id.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string rows the model produces.
 */
$rows = static function () use ($admin, $table, $cityAlpha, $cityBravo, $cityCharlie, $cityDelta): string {
    $out = array();
    $ids = implode(',', array($cityAlpha, $cityBravo, $cityCharlie, $cityDelta));
    $res = $admin->query("SELECT pk_i_id, i_num_items FROM $table WHERE pk_i_id IN ($ids) ORDER BY pk_i_id");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['pk_i_id'] . '=' . $r['i_num_items'];
    }
    $res->free();

    return implode(',', $out);
};

/** The expected $rows() string for the given id => count map; every other city is 0. */
$expect = static function (array $counts) use ($cityAlpha, $cityBravo, $cityCharlie, $cityDelta): string {
    $out = array();
    foreach (array($cityAlpha, $cityBravo, $cityCharlie, $cityDelta) as $id) {
        $out[] = $id . '=' . ($counts[$id] ?? 0);
    }

    return implode(',', $out);
};

/** Put every city back to a count of 0. */
$truncate = static function () use ($admin, $table): void {
    $admin->query("UPDATE $table SET i_num_items = 0");
};

/** Give one city a count with raw mysqli, never through the code under test. */
$seedStat = static function (int $cityId, int $num) use ($admin, $table): void {
    $admin->query("UPDATE $table SET i_num_items = $num WHERE pk_i_id = $cityId");
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
check('newInstance() is a singleton', CityStats::getInstance() === $model);
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('the DAO names the old stats table, so its inherited methods have no table to act on', DB_TABLE_PREFIX . 't_city_stats', $model->getTableName());
pin('primary key', 'fk_i_city_id', $model->getPrimaryKey());
pin('field allowlist', array('fk_i_city_id', 'i_num_items'), $model->getFields());
$leafCity    = seed_city($admin, $regionOne, 'Delete leaf');
$quietDelete = @$model->deleteByPrimaryKey($leafCity);
pin('an inherited deleteByPrimaryKey() cannot delete the real row', array(false, 1), array($quietDelete, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE pk_i_id = '" . $leafCity . "'")->fetch_row()[0]));
$admin->query("DELETE FROM $table WHERE pk_i_id = $leafCity");
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
 * increaseNumItems() — one UPDATE ... + 1. True only when a row changed, so an
 * unknown city is false; it never creates a row.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — a city');

$truncate();
pin('a known city returns bool true', true, $model->increaseNumItems($cityAlpha));
pin('its count went from 0 to 1', $expect(array($cityAlpha => 1)), $rows());
pin('a repeat call also returns bool true', true, $model->increaseNumItems($cityAlpha));
pin('the count incremented', $expect(array($cityAlpha => 2)), $rows());
pin('a second city is independent', true, $model->increaseNumItems($cityBravo));
pin('both counts are right', $expect(array($cityAlpha => 2, $cityBravo => 1)), $rows());

harness_section('increaseNumItems — query cost');

pin('an increment costs exactly one query', 1, harness_query_count(static function () use ($model, $cityCharlie) {
    $model->increaseNumItems($cityCharlie);
}));
pin('a second increment costs exactly one query', 1, harness_query_count(static function () use ($model, $cityCharlie) {
    $model->increaseNumItems($cityCharlie);
}));
pin(
    'the two counted calls landed',
    $expect(array($cityAlpha => 2, $cityBravo => 1, $cityCharlie => 2)),
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
    $expect(array($cityAlpha => 2, $cityBravo => 1, $cityCharlie => 2)),
    $rows()
);

harness_section('increaseNumItems — a numeric id with no city behind it');

pin('an unknown city returns bool false', false, $model->increaseNumItems(999));
pin('and costs one query', 1, harness_query_count(static function () use ($model) {
    $model->increaseNumItems(999);
}));
pin('no row was created for it', '0', (function () use ($admin, $table) {
    $res = $admin->query("SELECT COUNT(*) AS c FROM $table WHERE pk_i_id = 999");
    $c   = $res->fetch_assoc()['c'];
    $res->free();

    return $c;
})());
pin(
    'no existing count changed',
    $expect(array($cityAlpha => 2, $cityBravo => 1, $cityCharlie => 2)),
    $rows()
);

harness_section('increaseNumItems — a fractional id is truncated to its integer part');

/* The id is cast to int before it is bound, so "1.7" lands on city 1. */
pin(
    'a fractional id is truncated to its integer part and succeeds',
    true,
    $model->increaseNumItems($cityAlpha . '.7')
);
pin(
    'and it landed on the truncated city',
    $expect(array($cityAlpha => 3, $cityBravo => 1, $cityCharlie => 2)),
    $rows()
);
pin('a numeric string id works like the int form', true, $model->increaseNumItems((string)$cityBravo));
pin(
    'and incremented that city',
    $expect(array($cityAlpha => 3, $cityBravo => 2, $cityCharlie => 2)),
    $rows()
);

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — one UPDATE, no lookup first, never below zero. Returns the
 * affected-row count, so 1 and 0 are both success values; false means a rejected
 * id or a database error.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a city with a positive count');

$truncate();
$seedStat($cityAlpha, 3);
pin('decrementing returns int 1, the affected-row count', 1, $model->decreaseNumItems($cityAlpha));
pin('the count went down by one', $expect(array($cityAlpha => 2)), $rows());

harness_section('decreaseNumItems — a count already at zero');

$truncate();
pin(
    'a zero count returns int 0 — the guarded update matched nothing',
    0,
    $model->decreaseNumItems($cityAlpha)
);
pin('the count did not go negative', $expect(array()), $rows());

harness_section('decreaseNumItems — no such city');

pin('an unknown city returns int 0', 0, $model->decreaseNumItems(999));
pin('and costs exactly one query', 1, harness_query_count(
    static function () use ($model) {
        $model->decreaseNumItems(999);
    }
));
pin('nothing changed', $expect(array()), $rows());

harness_section('decreaseNumItems — query cost');

$truncate();
$seedStat($cityAlpha, 5);
pin('a decrement costs one query, with no lookup first', 1, harness_query_count(
    static function () use ($model, $cityAlpha) {
        $model->decreaseNumItems($cityAlpha);
    }
));
pin('the decrement landed', $expect(array($cityAlpha => 4)), $rows());

harness_section('decreaseNumItems — the is_numeric guard rejects before any query');

pin('a non-numeric id returns bool false', false, $model->decreaseNumItems('abc'));
pin('a null id returns bool false', false, $model->decreaseNumItems(null));
pin('an array returns bool false', false, $model->decreaseNumItems(array($cityAlpha)));
pin('bool true returns bool false', false, $model->decreaseNumItems(true));
pin('a rejected id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('abc');
}));
pin('nothing changed', $expect(array($cityAlpha => 4)), $rows());

harness_section('decreaseNumItems — a fractional id is truncated to its integer part');

pin(
    'a fractional id decrements the truncated city',
    1,
    $model->decreaseNumItems($cityAlpha . '.7')
);
pin('and it landed there', $expect(array($cityAlpha => 3)), $rows());

harness_section('decreaseNumItems — a database error');

pin('a failed update returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model, $cityAlpha) {
        return $model->decreaseNumItems($cityAlpha);
    }
));

/* ----------------------------------------------------------------------------
 * setNumItems() — one UPDATE. True unless the database fails, so an unknown city
 * (0 rows) is true too. Both arguments are cast to int in the method itself.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — set and overwrite');

$truncate();
pin('setting a city returns bool true', true, $model->setNumItems($cityBravo, 7));
pin('the city has that count', $expect(array($cityBravo => 7)), $rows());
pin('setting it again returns bool true', true, $model->setNumItems($cityBravo, 3));
pin('the count was overwritten, not incremented', $expect(array($cityBravo => 3)), $rows());
pin('re-setting the same value still returns bool true', true, $model->setNumItems($cityBravo, 3));
pin('the count is unchanged', $expect(array($cityBravo => 3)), $rows());

harness_section('setNumItems — both arguments are cast to int');

pin('a numeric-prefixed count returns bool true', true, $model->setNumItems($cityBravo, '9abc'));
pin('and stores its integer prefix', $expect(array($cityBravo => 9)), $rows());
pin('a null count returns bool true', true, $model->setNumItems($cityBravo, null));
pin('and stores 0', $expect(array()), $rows());
pin('a fractional city id is truncated onto that city', true, $model->setNumItems($cityBravo . '.9', 4));
pin('and it landed on the truncated city', $expect(array($cityBravo => 4)), $rows());

harness_section('setNumItems — a negative count');

// i_num_items is UNSIGNED, so -1 cannot be stored. Loose mode clamps it to 0 and
// reports success; strict mode refuses the write and leaves the count as it was.
if (harness_strict_writes()) {
    pin('a negative count is rejected', false, $quiet(static function () use ($model, $cityBravo) {
        return $model->setNumItems($cityBravo, -1);
    }));
    pin('the count keeps its previous value', $expect(array($cityBravo => 4)), $rows());
} else {
    pin('a negative count still reports success', true, $model->setNumItems($cityBravo, -1));
    pin('the unsigned column clamped it to 0', $expect(array()), $rows());
}
$afterNegative = harness_strict_writes() ? $expect(array($cityBravo => 4)) : $expect(array());

harness_section('setNumItems — a city that does not exist');

pin('an unknown city returns bool true (0 rows changed)', true, $model->setNumItems(999, 3));
pin('a non-numeric id casts to 0, which has no city, and returns bool true', true, $model->setNumItems('abc', 3));
pin('a null id casts to 0 and returns bool true', true, $model->setNumItems(null, 3));
pin('none of them wrote anything', $afterNegative, $rows());
pin('a failed update returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model, $cityBravo) {
        return $model->setNumItems($cityBravo, 3);
    }
));

harness_section('setNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $cityBravo) {
    $model->setNumItems($cityBravo, 2);
}));
pin('the counted call landed', $expect(array($cityBravo => 2)), $rows());
pin('an unknown city costs one query too', 1, harness_query_count(static function () use ($model) {
    $model->setNumItems(999, 3);
}));

/* ----------------------------------------------------------------------------
 * findByCityId() — a city's count under the old stats-row keys, or false when
 * there is no such city.
 * ------------------------------------------------------------------------- */
harness_section('findByCityId');

$truncate();
$seedStat($cityAlpha, 8);
$found = $model->findByCityId($cityAlpha);
check('a known city returns an array', is_array($found));
pin(
    'with both keys as strings',
    array('fk_i_city_id' => (string)$cityAlpha, 'i_num_items' => '8'),
    $found
);
check('every value is a string (C4)', is_array($found) && all_values_string($found));
pin(
    'a city with no listings has a count of "0"',
    array('fk_i_city_id' => (string)$cityBravo, 'i_num_items' => '0'),
    $model->findByCityId($cityBravo)
);
pin('an unknown city returns bool false', false, $model->findByCityId(999));
pin('a null id returns bool false', false, $quiet(static function () use ($model) {
    return $model->findByCityId(null);
}));

/* ----------------------------------------------------------------------------
 * deleteByRegion() — CityStats only. Sets the count of every city in the region to
 * 0 with one UPDATE. The region id is cast to int, so every argument shape lands
 * on true and false is reachable only through a failed query.
 * ------------------------------------------------------------------------- */
harness_section('deleteByRegion — the cascade');

$truncate();
$seedStat($cityAlpha, 5);
$seedStat($cityBravo, 4);
$seedStat($cityCharlie, 3);
$seedStat($cityDelta, 2);
pin('zeroing a region returns bool true', true, $model->deleteByRegion($regionOne));
pin(
    'only the counts of that region\'s cities are zero',
    $expect(array($cityCharlie => 3, $cityDelta => 2)),
    $rows()
);
pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $regionTwo) {
    $model->deleteByRegion($regionTwo);
}));
pin('and that zeroed the other region too', $expect(array()), $rows());

harness_section('deleteByRegion — a region with nothing to zero still reports success');

$seedStat($cityAlpha, 1);
pin('an unknown region returns bool true', true, $model->deleteByRegion(999));
pin('a region whose cities are already at zero returns bool true', true, $model->deleteByRegion($regionTwo));
pin('nothing else changed', $expect(array($cityAlpha => 1)), $rows());

harness_section('deleteByRegion — the argument is cast to int, so nothing reaches SQL malformed');

pin('a non-numeric region casts to 0 and returns bool true', true, $model->deleteByRegion('abc'));
pin('a null region casts to 0 and returns bool true', true, $quiet(static function () use ($model) {
    return $model->deleteByRegion(null);
}));
pin('neither changed anything', $expect(array($cityAlpha => 1)), $rows());
pin('a fractional region is truncated', true, $model->deleteByRegion($regionOne . '.9'));
pin('and the truncated form really did zero the count', $expect(array()), $rows());

harness_section('deleteByRegion — the failed-query branch returns bool false');

$seedStat($cityAlpha, 1);
pin('a failed update returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model, $regionOne) {
        return $model->deleteByRegion($regionOne);
    }
));
pin('and the count survived', $expect(array($cityAlpha => 1)), $rows());

/* ----------------------------------------------------------------------------
 * listCities() — the listing, cached, read from t_city alone. Every city has a
 * count (default 0), so '>=' lists every city and '>' those with listings. Both the comparison operator and
 * the ORDER BY are validated in the method itself, one against a hardcoded
 * operator list and one against a hardcoded pattern, and both fall back to their
 * defaults when they fail. Unlike listRegions() the projection is fixed.
 * ------------------------------------------------------------------------- */
harness_section('listCities — shape and default ordering');

$truncate();
$flush();
$seedStat($cityAlpha, 5);
$seedStat($cityCharlie, 3);

$list = $model->listCities();
pin('the default lists only cities with a positive count', 2, count($list));
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

harness_section('listCities — a city with no listings is still a place');

$flush();
$list = $model->listCities(null, '=');
pin(
    'a zero-count city is listed under "=" with its name and slug',
    array(
        array('city_id' => (string)$cityBravo, 'items' => '0', 'city_name' => 'Bville', 'city_slug' => 'bville'),
        array('city_id' => (string)$cityDelta, 'items' => '0', 'city_name' => 'Dville', 'city_slug' => 'dville'),
    ),
    $list
);
check('every value is a string (C4)', all_rows_string($list));

harness_section('listCities — the comparison argument');

$flush();
pin('">=" includes the zero-count cities', 4, count($model->listCities(null, '>=')));
$flush();
pin('"=" selects only the zero-count cities', 2, count($model->listCities(null, '=')));
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
    'the other region has its own cities',
    'Cville,Dville',
    implode(',', array_column($model->listCities($regionTwo, '>='), 'city_name'))
);

/* The region filter is the only clause that carries a value, so it is the only
 * form of this query that compiles to a PREPARED statement — and a prepared read
 * hands back native ints for the count column where the unfiltered form does
 * not. Both shapes have to look identical to a caller, so the filtered one gets
 * its own C4 pin rather than relying on the unfiltered pins above. */
$flush();
$filtered = $model->listCities($regionOne, '>=');
check('a filtered listing is all strings too (C4)', all_rows_string($filtered));
pin(
    'including the count column, which is an INT in the schema',
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
    4,
    count($model->listCities('abc', '>='))
);
$flush();
pin(
    'the null default is non-numeric too, so it filters nothing',
    4,
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

harness_section('listCities — no city has listings');

$truncate();
$flush();
pin('"> 0" gives an empty array', array(), $model->listCities());
$flush();
pin('but ">=" still lists every city', 4, count($model->listCities(null, '>=')));

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
 * aggregate and writes every result back in ONE UPDATE ... CASE, returning false
 * only when there is nothing to write or the write fails.
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
pin('nothing was written', $expect(array()), $rows());

harness_section('updateAllStats — the happy path');

/* Aville holds three listings, one of them inactive, so the batch aggregate
 * counts the two active ones — the same as the single-city recount, now that the
 * premium/expiry test is correctly parenthesised. */
pin('a list of known cities returns bool true', true, $model->updateAllStats(array($cityAlpha, $cityBravo)));
pin(
    'both counters were written',
    $expect(array($cityAlpha => 2, $cityBravo => 1)),
    $rows()
);
pin('one call costs two queries: the aggregate then the update', 2, harness_query_count(
    static function () use ($model, $cityAlpha, $cityBravo) {
        $model->updateAllStats(array($cityAlpha, $cityBravo));
    }
));

harness_section('updateAllStats — an existing counter is overwritten, not incremented');

$seedStat($cityAlpha, 99);
pin('rewriting returns bool true', true, $model->updateAllStats(array($cityAlpha)));
pin('and the stale value was replaced', $expect(array($cityAlpha => 2, $cityBravo => 1)), $rows());

harness_section('updateAllStats — a city with nothing to count is written as zero');

$seedStat($cityCharlie, 5);
pin('a city with no items returns bool true', true, $model->updateAllStats(array($cityCharlie)));
pin(
    'and its stale count is reset to zero',
    $expect(array($cityAlpha => 2, $cityBravo => 1)),
    $rows()
);

harness_section('updateAllStats — ids are cast to int on the way into the update');

pin('a numeric string id works', true, $model->updateAllStats(array((string)$cityAlpha)));
pin('a duplicated id collapses to one entry', true, $model->updateAllStats(array($cityAlpha, $cityAlpha)));
pin(
    'the counts are unchanged by either',
    $expect(array($cityAlpha => 2, $cityBravo => 1)),
    $rows()
);

harness_section('updateAllStats — cities that do not exist');

pin('an unknown city id returns bool true and changes nothing', true, $quiet(static function () use ($model) {
    return $model->updateAllStats(array(999));
}));
pin('a non-numeric member matches no city and returns bool true', true, $quiet(
    static function () use ($model) {
        return $model->updateAllStats(array('abc'));
    }
));
pin(
    'neither wrote anything',
    $expect(array($cityAlpha => 2, $cityBravo => 1)),
    $rows()
);

harness_section('updateAllStats — the batch recount agrees with the single-city recount');

/* calculateAllStats() now parenthesises its premium/expiry test
 *     ... AND b_spam = 0 AND (b_premium = 1 || dt_expiration >= ?) AND ...
 * so the b_active/b_enabled/b_spam filters apply to every listing. It agrees with
 * calculateNumItems() on the same fixture — an inactive listing is excluded. */
$truncate();
pin('the single-city recount excludes the inactive listing', '2', $model->calculateNumItems($cityAlpha));
pin('the batch recount runs', true, $model->updateAllStats(array($cityAlpha)));
pin('and writes 2 — the inactive listing is excluded, matching the single recount', $expect(array($cityAlpha => 2)), $rows());

$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemA3");
pin('expiring the already-excluded listing leaves the count unchanged', true, $model->updateAllStats(array($cityAlpha)));
pin('still 2', $expect(array($cityAlpha => 2)), $rows());

harness_section('updateAllStats — a premium listing outside the requested set stays out');

/* Same parentheses fix: the city filter now applies to the whole WHERE, so a
 * premium listing in another city no longer produces a count for a city the
 * caller never named. */
$truncate();
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 1, dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemB1");
pin('asking for one city returns bool true', true, $model->updateAllStats(array($cityAlpha)));
pin(
    'and only that city gets a count',
    $expect(array($cityAlpha => 2)),
    $rows()
);
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 0, dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $itemB1");
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $itemA3");

harness_section('updateAllStats — the failed-aggregate branch');

/* When the aggregate query fails, calculateAllStats returns an empty array
 * BEFORE its fill-with-zero loop, so updateAllStats sees "nothing calculated"
 * and returns false after exactly one query — the update never runs. */
$truncate();
$seedStat($cityAlpha, 6);
pin('a failed aggregate returns bool false', false, $withTableMissing(
    $locations,
    static function () use ($model, $cityAlpha) {
        return $model->updateAllStats(array($cityAlpha));
    }
));
pin('and nothing was written', $expect(array($cityAlpha => 6)), $rows());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/citystats.php */
