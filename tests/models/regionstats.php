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
 * Characterization pins for the RegionStats model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * increaseNumItems()/decreaseNumItems()/setNumItems()/listRegions()/
 * calculateNumItems()/updateAllStats() move to the parameterized query layer.
 *
 * t_region_stats is a denormalised counter table: an INT UNSIGNED primary key
 * with a FOREIGN KEY onto t_region, and an INT UNSIGNED counter. Five things
 * shape the pins below, and each one was measured rather than assumed.
 *
 * 1. Every method that reaches a counter row guards its argument with
 *    is_numeric() or casts it with (int), so no caller value can ever reach the
 *    database malformed. The reachable failure is the foreign key, and the
 *    failure branch of the reads is only reachable by taking a table away —
 *    which is what $withTableMissing() below does.
 *
 * 2. Legacy built increaseNumItems()'s SQL through sprintf('%d'), truncating a
 *    fractional id before MySQL ever saw it, and calculateNumItems()/
 *    setNumItems()/updateAllStats() cast with (int). A bound parameter has to
 *    reproduce those casts explicitly, so each one is pinned with a value that
 *    would land somewhere else without it.
 *
 * 3. Strict SQL mode is stripped from every connection, so an out-of-range
 *    counter is clamped rather than rejected: setNumItems($id, -1) reports
 *    success and stores 0.
 *
 * 4. DBCommandClass::escape() passes an is_numeric() value through UNQUOTED, so
 *    a country code that looks numeric reached MySQL as a number and the CHAR(2)
 *    column was compared numerically — every alphabetic code collapsed to 0, so
 *    listRegions('0') returned the regions of 'US' and 'ES'. The listRegions
 *    section pins that, and records the conversion's deliberate decision not to
 *    reproduce it.
 *
 * 5. calculateAllStats() (private, reached through updateAllStats) builds its
 *    premium/expiry test WITHOUT parentheses, so `||` splits the whole WHERE in
 *    two and the region filter only constrains one half. Two consequences are
 *    pinned as latent product bugs, deliberately preserved by the conversion and
 *    flagged for separate triage: an inactive listing is counted as long as it
 *    has not expired, and a premium listing in a region that was NOT asked for
 *    gets a counter row written anyway. calculateNumItems() has the parentheses
 *    and disagrees with calculateAllStats() on the same fixture.
 *
 * findByRegionId() is pure delegation to DAO::findByPrimaryKey(), a base method
 * this effort does not convert; it is pinned as a regression guard only.
 *
 * Usage:  php tests/models/regionstats.php          (standalone, own scratch database)
 *         php tests/run-models.php regionstats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_regionstats');

/*
 * listRegions() reaches osc_cache_get()/osc_cache_set() and osc_base_url(). The
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

$model     = RegionStats::newInstance();
$table     = DB_TABLE_PREFIX . 't_region_stats';
$locations = DB_TABLE_PREFIX . 't_item_location';
$cache     = Object_Cache_Factory::newInstance();

seed_country($admin, 'US', 'United States');
seed_country($admin, 'ES', 'Spain');
/* A country whose code is numeric. Legal in the schema (CHAR(2)) and the only
 * way to observe what escape()'s unquoted-numeric passthrough did. */
seed_country($admin, '12', 'Numeric land');

$regionAlpha   = seed_region($admin, 'US', 'Alpha');
$regionBravo   = seed_region($admin, 'US', 'Bravo');
$regionCharlie = seed_region($admin, 'ES', 'Charlie');
$regionNum     = seed_region($admin, '12', 'Numland');

/**
 * The whole counter table as "id=count,id=count", ordered by id.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string rows the legacy path produced.
 */
$rows = static function () use ($admin, $table): string {
    $out = array();
    $res = $admin->query("SELECT fk_i_region_id, i_num_items FROM $table ORDER BY fk_i_region_id");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['fk_i_region_id'] . '=' . $r['i_num_items'];
    }
    $res->free();

    return implode(',', $out);
};

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** Seed one counter row with raw mysqli, never through the code under test. */
$seedStat = static function (int $regionId, int $num) use ($admin, $table): void {
    $admin->query("INSERT INTO $table (fk_i_region_id, i_num_items) VALUES ($regionId, $num)");
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
harness_section('RegionStats: public surface');

pin(
    'increaseNumItems signature is unchanged',
    'public increaseNumItems($regionId)',
    harness_method_signature('RegionStats', 'increaseNumItems')
);
pin(
    'decreaseNumItems signature is unchanged',
    'public decreaseNumItems($regionId)',
    harness_method_signature('RegionStats', 'decreaseNumItems')
);
pin(
    'setNumItems signature is unchanged',
    'public setNumItems($regionID, $numItems)',
    harness_method_signature('RegionStats', 'setNumItems')
);
pin(
    'findByRegionId signature is unchanged',
    'public findByRegionId($regionId)',
    harness_method_signature('RegionStats', 'findByRegionId')
);
pin(
    'listRegions signature is unchanged, defaults included',
    "public listRegions(\$country = '%%%%', \$zero = '>', \$order = 'region_name ASC')",
    harness_method_signature('RegionStats', 'listRegions')
);
pin(
    'calculateNumItems signature is unchanged',
    'public calculateNumItems($regionId)',
    harness_method_signature('RegionStats', 'calculateNumItems')
);
pin(
    'updateAllStats signature is unchanged, array type hint included',
    'public updateAllStats(array $regions)',
    harness_method_signature('RegionStats', 'updateAllStats')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('RegionStats', 'newInstance')
);
check('RegionStats still extends DAO', is_subclass_of('RegionStats', 'DAO'));
check('newInstance() is a singleton', RegionStats::newInstance() === $model);
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_i_region_id', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('fk_i_region_id', 'i_num_items'), $model->getFields());
pin(
    'the model declares exactly these eight public methods of its own',
    array(
        '__construct',
        'calculateNumItems',
        'decreaseNumItems',
        'findByRegionId',
        'increaseNumItems',
        'listRegions',
        'newInstance',
        'setNumItems',
        'updateAllStats',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('RegionStats')),
        array(
            '__construct',
            'calculateNumItems',
            'decreaseNumItems',
            'findByRegionId',
            'increaseNumItems',
            'listRegions',
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
harness_section('increaseNumItems — first call for a region');

$truncate();
pin('a fresh region returns bool true', true, $model->increaseNumItems($regionAlpha));
pin('the row was created at 1', $regionAlpha . '=1', $rows());

harness_section('increaseNumItems — subsequent calls');

pin('a repeat call also returns bool true', true, $model->increaseNumItems($regionAlpha));
pin('the counter incremented rather than duplicating the row', $regionAlpha . '=2', $rows());
pin('a second region is independent', true, $model->increaseNumItems($regionBravo));
pin('both rows now exist', $regionAlpha . '=2,' . $regionBravo . '=1', $rows());

harness_section('increaseNumItems — query cost');

pin('a fresh insert costs exactly one query', 1, harness_query_count(static function () use ($model, $regionCharlie) {
    $model->increaseNumItems($regionCharlie);
}));
pin('an increment costs exactly one query', 1, harness_query_count(static function () use ($model, $regionCharlie) {
    $model->increaseNumItems($regionCharlie);
}));
pin(
    'the two counted calls landed',
    $regionAlpha . '=2,' . $regionBravo . '=1,' . $regionCharlie . '=2',
    $rows()
);

harness_section('increaseNumItems — the is_numeric guard rejects before any query');

pin('a non-numeric id returns bool false', false, $model->increaseNumItems('abc'));
pin('a null id returns bool false', false, $model->increaseNumItems(null));
pin('bool true is not numeric and returns bool false', false, $model->increaseNumItems(true));
pin('an empty string returns bool false', false, $model->increaseNumItems(''));
pin('an array returns bool false', false, $model->increaseNumItems(array($regionAlpha)));
pin('a rejected id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('abc');
}));
pin(
    'none of them wrote anything',
    $regionAlpha . '=2,' . $regionBravo . '=1,' . $regionCharlie . '=2',
    $rows()
);

harness_section('increaseNumItems — a numeric id with no region behind it (foreign key)');

pin('an unknown region returns bool false', false, $quiet(static function () use ($model) {
    return $model->increaseNumItems(999);
}));
pin('the rejected insert still costs one query', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->increaseNumItems(999);
    });
}));
pin(
    'nothing was written for it',
    $regionAlpha . '=2,' . $regionBravo . '=1,' . $regionCharlie . '=2',
    $rows()
);

harness_section('increaseNumItems — sprintf("%d") truncated a fractional id before the query');

/* Legacy interpolated the id through sprintf('%d', ...), so "1.7" reached MySQL
 * as 1 and incremented that region. A bound parameter is checked as given, so
 * the conversion needs the same truncation spelled out as an (int) cast — without
 * it the foreign-key check rejects the value and this pin flips to false. */
pin(
    'a fractional id is truncated to its integer part and succeeds',
    true,
    $model->increaseNumItems($regionAlpha . '.7')
);
pin(
    'and it landed on the truncated region, not a new row',
    $regionAlpha . '=3,' . $regionBravo . '=1,' . $regionCharlie . '=2',
    $rows()
);
pin('a numeric string id works like the int form', true, $model->increaseNumItems((string)$regionBravo));
pin(
    'still one row for it',
    $regionAlpha . '=3,' . $regionBravo . '=2,' . $regionCharlie . '=2',
    $rows()
);

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — read then conditional update. Returns the affected-row
 * count, so 1 and 0 are both success values and false means "no row to work on".
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a region with a positive counter');

$truncate();
$seedStat($regionAlpha, 3);
pin('decrementing returns int 1, the affected-row count', 1, $model->decreaseNumItems($regionAlpha));
pin('the counter went down by one', $regionAlpha . '=2', $rows());

harness_section('decreaseNumItems — a counter already at zero');

$truncate();
$seedStat($regionAlpha, 0);
pin(
    'a zero counter returns int 0 — the row exists, the guarded update matched nothing',
    0,
    $model->decreaseNumItems($regionAlpha)
);
pin('the counter did not go negative', $regionAlpha . '=0', $rows());

harness_section('decreaseNumItems — no row for that region');

pin('a region with no counter row returns bool false', false, $model->decreaseNumItems($regionBravo));
pin('a missing row costs exactly one query (the lookup only)', 1, harness_query_count(
    static function () use ($model, $regionBravo) {
        $model->decreaseNumItems($regionBravo);
    }
));
pin('nothing was created for it', $regionAlpha . '=0', $rows());

harness_section('decreaseNumItems — query cost when the row exists');

$truncate();
$seedStat($regionAlpha, 5);
pin('a decrement costs two queries: lookup then update', 2, harness_query_count(
    static function () use ($model, $regionAlpha) {
        $model->decreaseNumItems($regionAlpha);
    }
));
pin('the decrement landed', $regionAlpha . '=4', $rows());

harness_section('decreaseNumItems — the is_numeric guard rejects before any query');

pin('a non-numeric id returns bool false', false, $model->decreaseNumItems('abc'));
pin('a null id returns bool false', false, $model->decreaseNumItems(null));
pin('an array returns bool false', false, $model->decreaseNumItems(array($regionAlpha)));
pin('bool true returns bool false', false, $model->decreaseNumItems(true));
pin('a rejected id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('abc');
}));
pin('nothing changed', $regionAlpha . '=4', $rows());

harness_section('decreaseNumItems — a fractional id is compared as given, not truncated');

/* Unlike increaseNumItems, the lookup goes through where(), whose escape() hands
 * a numeric value straight through: MySQL compares the INT column against the
 * decimal, finds no row, and the method returns false before any write. */
pin(
    'a fractional id finds no counter row and returns bool false',
    false,
    $model->decreaseNumItems($regionAlpha . '.7')
);
pin('nothing changed', $regionAlpha . '=4', $rows());

harness_section('decreaseNumItems — the failed-lookup branch');

/* The only reachable failure: every argument shape is caught by is_numeric()
 * above, so the table has to go away. The lookup fails, the row is never seen,
 * and the method returns bool false after exactly one query — the same value the
 * "no row" case gives, which is why the conversion can absorb it identically. */
pin('a failed lookup returns bool false', false, $withTableMissing(
    $table,
    static function () use ($model, $regionAlpha) {
        return $model->decreaseNumItems($regionAlpha);
    }
));

/* ----------------------------------------------------------------------------
 * setNumItems() — absolute upsert. Both arguments are cast to int in the method
 * itself, so no guard is needed and no argument can reach SQL malformed.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — insert and overwrite');

$truncate();
pin('setting a region with no row returns bool true', true, $model->setNumItems($regionBravo, 7));
pin('the row was created with that count', $regionBravo . '=7', $rows());
pin('setting it again returns bool true', true, $model->setNumItems($regionBravo, 3));
pin('the count was overwritten, not incremented', $regionBravo . '=3', $rows());
pin('re-setting the same value still returns bool true', true, $model->setNumItems($regionBravo, 3));
pin('the row is unchanged', $regionBravo . '=3', $rows());

harness_section('setNumItems — both arguments are cast to int');

pin('a numeric-prefixed count returns bool true', true, $model->setNumItems($regionBravo, '9abc'));
pin('and stores its integer prefix', $regionBravo . '=9', $rows());
pin('a null count returns bool true', true, $model->setNumItems($regionBravo, null));
pin('and stores 0', $regionBravo . '=0', $rows());
pin('a fractional region id is truncated onto that region', true, $model->setNumItems($regionBravo . '.9', 4));
pin('and it landed on the truncated row', $regionBravo . '=4', $rows());

harness_section('setNumItems — a negative count is clamped, not rejected (strict mode is off)');

pin('a negative count still reports success', true, $model->setNumItems($regionBravo, -1));
pin('the unsigned column clamped it to 0', $regionBravo . '=0', $rows());

harness_section('setNumItems — rejected by the database');

pin('an unknown region returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems(999, 3);
}));
pin('a non-numeric id casts to 0, which has no region, and returns bool false', false, $quiet(
    static function () use ($model) {
        return $model->setNumItems('abc', 3);
    }
));
pin('a null id casts to 0 and returns bool false', false, $quiet(static function () use ($model) {
    return $model->setNumItems(null, 3);
}));
pin('none of them wrote anything', $regionBravo . '=0', $rows());

harness_section('setNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $regionBravo) {
    $model->setNumItems($regionBravo, 2);
}));
pin('the counted call landed', $regionBravo . '=2', $rows());
pin('a rejected call costs one query too', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->setNumItems(999, 3);
    });
}));

/* ----------------------------------------------------------------------------
 * findByRegionId() — regression guard only; it delegates straight to
 * DAO::findByPrimaryKey(), a base method out of scope for this effort.
 * ------------------------------------------------------------------------- */
harness_section('findByRegionId — regression guard (not converted)');

$truncate();
$seedStat($regionAlpha, 8);
$found = $model->findByRegionId($regionAlpha);
check('a known region returns an array', is_array($found));
pin(
    'with both columns as strings',
    array('fk_i_region_id' => (string)$regionAlpha, 'i_num_items' => '8'),
    $found
);
check('every value is a string (C4)', is_array($found) && all_values_string($found));
pin('an unknown region returns bool false', false, $model->findByRegionId(999));
pin('a null id returns bool false', false, $quiet(static function () use ($model) {
    return $model->findByRegionId(null);
}));

/* ----------------------------------------------------------------------------
 * listRegions() — the joined listing, cached. Both the comparison operator and
 * the ORDER BY are validated in the method itself, one against a hardcoded
 * operator list and one against a hardcoded pattern, and both fall back to their
 * defaults when they fail.
 *
 * The projection depends on the ORDER BY's first token, and there is a third,
 * undocumented branch: an order over any other column selects NOTHING
 * explicitly, so the query falls through to SELECT *.
 * ------------------------------------------------------------------------- */
harness_section('listRegions — shape and default ordering');

$truncate();
$flush();
$seedStat($regionAlpha, 5);
$seedStat($regionBravo, 0);
$seedStat($regionCharlie, 3);
$seedStat($regionNum, 2);

$list = $model->listRegions();
pin('the default lists only regions with a positive counter', 3, count($list));
pin(
    'the row shape is the four aliased columns',
    array('region_id', 'items', 'region_name', 'region_slug'),
    array_keys($list[0])
);
pin(
    'the first row is the full aliased projection',
    array(
        'region_id'   => (string)$regionAlpha,
        'items'       => '5',
        'region_name' => 'Alpha',
        'region_slug' => 'alpha',
    ),
    $list[0]
);
check('every value in every row is a string (C4)', all_rows_string($list));
pin(
    'the default order is region_name ASC',
    'Alpha,Charlie,Numland',
    implode(',', array_column($list, 'region_name'))
);

harness_section('listRegions — the "items" order selects a narrower projection');

$flush();
$list = $model->listRegions('%%%%', '>', 'items DESC');
pin(
    'ordering by items drops the slug column entirely',
    array('region_id', 'items', 'region_name'),
    array_keys($list[0])
);
pin(
    'and orders by the counter alias',
    'Alpha,Charlie,Numland',
    implode(',', array_column($list, 'region_name'))
);

harness_section('listRegions — any other order column selects nothing, so the query is SELECT *');

$flush();
$list = $model->listRegions('%%%%', '>', 's_name ASC');
pin('the projection is every column of both joined tables', array(
    'pk_i_id',
    'fk_c_country_code',
    's_name',
    's_slug',
    'b_active',
    'fk_i_region_id',
    'i_num_items',
), array_keys($list[0]));
pin('with the same three rows', 3, count($list));
check('every value is still a string (C4)', all_rows_string($list));

harness_section('listRegions — the join is an inner join, so an orphan counter row is dropped');

/* Foreign-key checks are off on the ADMIN session only, so a counter row with no
 * region behind it can be seeded here even though the model could never create
 * one. RegionStats joins through a comma/CROSS JOIN plus an equality WHERE — an
 * inner join — where its CityStats mirror uses a LEFT JOIN and keeps the orphan. */
$admin->query("INSERT INTO $table (fk_i_region_id, i_num_items) VALUES (999, 7)");
$flush();
pin('the orphan counter row does not appear', 3, count($model->listRegions()));
$admin->query("DELETE FROM $table WHERE fk_i_region_id = 999");

harness_section('listRegions — the comparison argument');

$flush();
pin('">=" includes the zero-counter region', 4, count($model->listRegions('%%%%', '>=')));
$flush();
pin('"=" selects only the zero-counter region', 1, count($model->listRegions('%%%%', '=')));
$flush();
pin('"<" matches nothing and returns an empty array', array(), $model->listRegions('%%%%', '<'));
$flush();
pin('an unlisted operator falls back to ">"', 3, count($model->listRegions('%%%%', 'bogus')));
$flush();
pin('an empty operator falls back to ">"', 3, count($model->listRegions('%%%%', '')));
$flush();
pin('a null operator falls back to ">"', 3, count($quiet(static function () use ($model) {
    return $model->listRegions('%%%%', null);
})));

harness_section('listRegions — the order argument');

$flush();
pin(
    'region_name DESC reverses the default',
    'Numland,Charlie,Alpha',
    implode(',', array_column($model->listRegions('%%%%', '>', 'region_name DESC'), 'region_name'))
);
$flush();
pin(
    'the direction is matched case-insensitively',
    'Numland,Charlie,Alpha',
    implode(',', array_column($model->listRegions('%%%%', '>', 'region_name desc'), 'region_name'))
);
$flush();
pin(
    'a malformed order falls back to region_name ASC',
    'Alpha,Charlie,Numland',
    implode(',', array_column($model->listRegions('%%%%', '>', 'bad order'), 'region_name'))
);
$flush();
pin(
    'a null order falls back to region_name ASC',
    'Alpha,Charlie,Numland',
    implode(',', array_column($quiet(static function () use ($model) {
        return $model->listRegions('%%%%', '>', null);
    }), 'region_name'))
);

harness_section('listRegions — the country filter');

$flush();
pin(
    'the default sentinel applies no country filter at all',
    3,
    count($model->listRegions())
);
$flush();
pin(
    'a country code restricts the listing to its regions',
    'Alpha,Bravo',
    implode(',', array_column($model->listRegions('US', '>='), 'region_name'))
);
$flush();
pin(
    'a country with one region',
    'Charlie',
    implode(',', array_column($model->listRegions('ES', '>='), 'region_name'))
);

/* The country filter is the only clause that carries a value, so it is the only
 * form of this query that compiles to a PREPARED statement — and a prepared read
 * hands back native ints for the counter column where the unfiltered form does
 * not. Both shapes have to look identical to a caller, so the filtered one gets
 * its own C4 pin rather than relying on the unfiltered pins above. */
$flush();
$filtered = $model->listRegions('US', '>=');
check('a filtered listing is all strings too (C4)', all_rows_string($filtered));
pin(
    'including the counter column, which is an INT in the schema',
    array(
        'region_id'   => (string)$regionAlpha,
        'items'       => '5',
        'region_name' => 'Alpha',
        'region_slug' => 'alpha',
    ),
    $filtered[0]
);
$flush();
pin('an unknown country returns an empty array', array(), $model->listRegions('XX', '>='));

harness_section('listRegions — a null country is a comparison against NULL, matching nothing');

/* Legacy escape(null) produced the literal NULL, so the clause was
 * `fk_c_country_code = NULL` — valid SQL that is never true. A bound null
 * compiles to `= ?` with a null value, which is also never true, so the two
 * converge on the same empty array. */
$flush();
pin('a null country returns an empty array', array(), $quiet(static function () use ($model) {
    return $model->listRegions(null, '>=');
}));

harness_section('listRegions — a quoted country code is neutralised, not an error');

$flush();
pin('a country code containing a quote returns an empty array', array(), $model->listRegions("o'brien", '>='));

harness_section('listRegions — a numeric country code matches only its own row');

/* DELIBERATE BEHAVIOUR CHANGE, recorded rather than hidden.
 *
 * The legacy escape() returned an is_numeric() value unquoted, so a numeric
 * country code reached MySQL as a number and the CHAR(2) column was compared
 * numerically: every alphabetic code collapsed to 0, so a country of '0' listed
 * the regions of 'US' and 'ES'. Binding the value compares it as the string it
 * is, so a code now matches only its own country.
 *
 * The behaviour that disappears is type confusion, not a feature: no caller
 * passes a numeric country code deliberately, and the same comparison shape
 * exists on the credential lookups, where preserving it would be actively
 * unsafe. CountryStats is where this was first found and written down; the two
 * location listings inherit the same decision. */
$flush();
pin(
    'a genuinely numeric country code still finds its own regions',
    'Numland',
    implode(',', array_column($model->listRegions('12', '>='), 'region_name'))
);
$flush();
pin('the string "0" matches no country at all', array(), $model->listRegions('0', '>='));

/* The change only reaches values that arrive as strings. A genuine int binds as
 * an int, which is still a numeric comparison, so the coercion survives there
 * exactly as before. Callers pass codes read from request params or the
 * database, i.e. strings, so this residue is not reachable in practice — but it
 * is the honest boundary of the change and is pinned as such. */
$flush();
pin(
    'an int 0 still coerces and sweeps up every alphabetically-coded country',
    'Alpha,Bravo,Charlie',
    implode(',', array_column($model->listRegions(0, '>='), 'region_name'))
);

harness_section('listRegions — the error fallback is reachable through the order argument');

/* The pattern admits any identifier-shaped column, so a well-formed order over a
 * column that does not exist is a failed query, and the method's own
 * false-branch turns it into an empty array — NOT an exception. */
$flush();
pin('an unknown but well-formed order column returns an empty array', array(), $quiet(
    static function () use ($model) {
        return $model->listRegions('%%%%', '>', 'nope ASC');
    }
));

harness_section('listRegions — an array country reaches the query layer malformed');

/* escape() hands an array straight back, it string-casts to `Array` in the SQL,
 * and the unknown column fails the query into the same empty array. The new
 * layer refuses to bind an array and throws, which the catch turns into the same
 * value. */
$flush();
pin('an array country returns an empty array', array(), $quiet(static function () use ($model) {
    return $model->listRegions(array('US'), '>=');
}));

harness_section('listRegions — the cache');

$flush();
pin('a cold call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->listRegions();
}));
pin('the second call is served from the cache and costs none', 0, harness_query_count(
    static function () use ($model) {
        $model->listRegions();
    }
));
pin(
    'and returns the identical rows',
    $model->listRegions(),
    $model->listRegions()
);
pin('a different argument set is a different cache key', 1, harness_query_count(static function () use ($model) {
    $model->listRegions('%%%%', '>=');
}));

harness_section('listRegions — a failed query is never cached');

$flush();
pin('the failing form costs one query', 1, harness_query_count(static function () use ($model, $quiet) {
    $quiet(static function () use ($model) {
        $model->listRegions('%%%%', '>', 'nope ASC');
    });
}));
pin('and costs one query again, because the empty result was not stored', 1, harness_query_count(
    static function () use ($model, $quiet) {
        $quiet(static function () use ($model) {
            $model->listRegions('%%%%', '>', 'nope ASC');
        });
    }
));

harness_section('listRegions — a stale cache entry survives a write, by design');

/* Nothing in this model invalidates the listing cache, so a counter written
 * after a cached read is not visible until the entry expires. That staleness is
 * the existing contract and the conversion does not change it. */
$flush();
$before = $model->listRegions();
$model->setNumItems($regionBravo, 11);
pin('the listing still shows the pre-write result', $before, $model->listRegions());
$flush();
pin('and picks the write up once the cache is cleared', 4, count($model->listRegions()));
$model->setNumItems($regionBravo, 0);

harness_section('listRegions — an empty counter table');

$truncate();
$flush();
pin('no counter rows at all gives an empty array', array(), $model->listRegions());

/* ----------------------------------------------------------------------------
 * calculateNumItems() — the recount aggregate over t_item_location/t_item/
 * t_category. COUNT(*) with no GROUP BY always returns one row, so there is no
 * zero-row branch: a region with nothing to count returns the STRING '0', while
 * a failed query returns INT 0.
 * ------------------------------------------------------------------------- */
harness_section('calculateNumItems — setup');

$catId  = seed_category($admin, 'Motors');
$userId = seed_user($admin);
$itemA1 = seed_item($admin, $catId, $userId, 'Alpha one');
$itemA2 = seed_item($admin, $catId, $userId, 'Alpha two');
$itemA3 = seed_item($admin, $catId, $userId, 'Alpha inactive', 10.0, 0, 1);
$itemB1 = seed_item($admin, $catId, $userId, 'Bravo one');

$place = static function (int $itemId, int $regionId) use ($admin, $locations): void {
    $admin->query("UPDATE $locations SET fk_i_region_id = $regionId WHERE fk_i_item_id = $itemId");
};
$place($itemA1, $regionAlpha);
$place($itemA2, $regionAlpha);
$place($itemA3, $regionAlpha);
$place($itemB1, $regionBravo);

pin('two active items are counted, the inactive one is not', '2', $model->calculateNumItems($regionAlpha));
check('the count is a string, not an int (C4)', is_string($model->calculateNumItems($regionAlpha)));
pin('the other region counts its own item', '1', $model->calculateNumItems($regionBravo));
pin('a region with no items returns the string "0"', '0', $model->calculateNumItems(999));

harness_section('calculateNumItems — the argument is cast to int, so nothing reaches SQL malformed');

pin('a non-numeric id casts to 0 and returns the string "0"', '0', $model->calculateNumItems('abc'));
pin('a null id casts to 0 and returns the string "0"', '0', $quiet(static function () use ($model) {
    return $model->calculateNumItems(null);
}));
pin('a fractional id is truncated onto that region', '2', $model->calculateNumItems($regionAlpha . '.9'));
/* (int) on a non-empty array is 1, so this counts region 1 rather than failing. */
pin('an array casts to int 1 rather than failing the query', '2', $quiet(static function () use ($model) {
    return $model->calculateNumItems(array($regionAlpha));
}));

harness_section('calculateNumItems — the eligibility rules');

$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemA1");
pin('an expired item drops out', '1', $model->calculateNumItems($regionAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_premium = 1 WHERE pk_i_id = $itemA1");
pin('unless it is premium, which is counted however old it is', '2', $model->calculateNumItems($regionAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_spam = 1 WHERE pk_i_id = $itemA2");
pin('a spam item drops out', '1', $model->calculateNumItems($regionAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 0');
pin('a disabled category takes all of its items with it', '0', $model->calculateNumItems($regionAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 1');
pin('and re-enabling brings them back', '1', $model->calculateNumItems($regionAlpha));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET b_spam = 0 WHERE pk_i_id = $itemA2");
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 0, dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $itemA1");
pin('the fixture is back where it started', '2', $model->calculateNumItems($regionAlpha));

harness_section('calculateNumItems — the failed-query branch returns int 0');

/* Every argument is cast, so the only way to reach the false-branch is to take a
 * table away. It returns INT 0 — a different value and a different type from the
 * string '0' a legitimate zero match gives. */
pin('a failed query returns int 0, not the string "0"', 0, $withTableMissing(
    $locations,
    static function () use ($model, $regionAlpha) {
        return $model->calculateNumItems($regionAlpha);
    }
));

harness_section('calculateNumItems — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model, $regionAlpha) {
    $model->calculateNumItems($regionAlpha);
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

/* Alpha holds three listings, one of them inactive, and the batch aggregate
 * counts all three — see the LATENT BUG sections below for why. The numbers here
 * are what the code produces, not what the single-region recount would say. */
pin('a list of known regions returns bool true', true, $model->updateAllStats(array($regionAlpha, $regionBravo)));
pin(
    'both counters were written',
    $regionAlpha . '=3,' . $regionBravo . '=1',
    $rows()
);
pin('one call costs two queries: the aggregate then the upsert', 2, harness_query_count(
    static function () use ($model, $regionAlpha, $regionBravo) {
        $model->updateAllStats(array($regionAlpha, $regionBravo));
    }
));

harness_section('updateAllStats — an existing counter is overwritten, not incremented');

$admin->query("UPDATE $table SET i_num_items = 99 WHERE fk_i_region_id = $regionAlpha");
pin('rewriting returns bool true', true, $model->updateAllStats(array($regionAlpha)));
pin('and the stale value was replaced', $regionAlpha . '=3,' . $regionBravo . '=1', $rows());

harness_section('updateAllStats — a region with nothing to count is written as zero');

pin('a region with no items returns bool true', true, $model->updateAllStats(array($regionCharlie)));
pin(
    'and gets an explicit zero row',
    $regionAlpha . '=3,' . $regionBravo . '=1,' . $regionCharlie . '=0',
    $rows()
);

harness_section('updateAllStats — ids are cast to int on the way into the upsert');

pin('a numeric string id works', true, $model->updateAllStats(array((string)$regionAlpha)));
pin('a duplicated id collapses to one row', true, $model->updateAllStats(array($regionAlpha, $regionAlpha)));
pin(
    'the table is unchanged by either',
    $regionAlpha . '=3,' . $regionBravo . '=1,' . $regionCharlie . '=0',
    $rows()
);

harness_section('updateAllStats — rejected by the foreign key');

pin('an unknown region id returns bool false', false, $quiet(static function () use ($model) {
    return $model->updateAllStats(array(999));
}));
pin('a non-numeric member casts to 0, which has no region, and returns bool false', false, $quiet(
    static function () use ($model) {
        return $model->updateAllStats(array('abc'));
    }
));
pin(
    'neither wrote anything',
    $regionAlpha . '=3,' . $regionBravo . '=1,' . $regionCharlie . '=0',
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
pin('the single-region recount excludes the inactive listing', '2', $model->calculateNumItems($regionAlpha));
pin('the batch recount runs', true, $model->updateAllStats(array($regionAlpha)));
pin('but writes 3 — the inactive listing is counted', $regionAlpha . '=3', $rows());

$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_item SET dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemA3");
pin('expiring the inactive listing removes it from the batch count', true, $model->updateAllStats(array($regionAlpha)));
pin('and the counter agrees with the single-region recount again', $regionAlpha . '=2', $rows());

harness_section('updateAllStats — LATENT BUG: a premium listing outside the requested set leaks in');

/* Same missing parentheses: the region filter sits in the right-hand half of the
 * `||`, so the left-hand (premium) half has no region restriction at all. A
 * premium listing anywhere in the database produces a GROUP BY row for ITS
 * region, and updateAllStats writes a counter for a region the caller never
 * named. Preserved deliberately and logged for triage. */
$truncate();
$admin->query('UPDATE ' . DB_TABLE_PREFIX
    . "t_item SET b_premium = 1, dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $itemB1");
pin('asking for one region returns bool true', true, $model->updateAllStats(array($regionAlpha)));
pin(
    'but a counter row was written for the other region too',
    $regionAlpha . '=2,' . $regionBravo . '=1',
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
    static function () use ($model, $regionAlpha) {
        return $model->updateAllStats(array($regionAlpha));
    }
));
pin('and nothing was written', '', $rows());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/regionstats.php */
