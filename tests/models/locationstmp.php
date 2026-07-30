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
 * Characterization pins for the LocationsTmp model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * getLocations()/populateCities()/populateRegions()/populateCountries()/
 * delete()/batchInsert()/batchDelete() move to the parameterized query layer —
 * with ONE labelled exception at the bottom (the amendment-T coercion drop),
 * which is intentionally red against the pre-conversion code.
 *
 * t_locations_tmp is a staging table: a VARCHAR(10) id_location plus an
 * ENUM('COUNTRY','REGION','CITY') e_type, composite primary key over the pair.
 * populate*() copies primary keys out of t_country / t_region / t_city into it
 * (INSERT IGNORE ... SELECT), and the batch/delete methods drain it again as
 * each location's stats are rebuilt. Because the SELECT half of the populate
 * queries reads the LIVE location tables and the deletes drain the staging
 * table, every effect is read back with RAW UNPREPARED mysqli (amendment D:
 * a prepared read returns native ints and would disagree with the string rows
 * the legacy path produced), and a second country's whole tree is seeded as a
 * survivor set so a stray write is visible.
 *
 * Return ledgers established by READING the current bodies and OBSERVING:
 *  - getLocations($max): array() on empty / SQL error; a list of all-string
 *    rows otherwise. select() takes no column list, so rows carry the physical
 *    SELECT * column order (id_location, e_type). limit() keeps the legacy
 *    is_numeric() gate — a non-numeric $max drops the clause and returns ALL
 *    rows.
 *  - populate*(): bool — true when the INSERT IGNORE ran (even over zero source
 *    rows), false when the query failed. Never a recordset (writes return true).
 *  - delete($where): the affected-row COUNT (int, 0 for a valid zero-row match)
 *    on success, false for an empty where or a failed query.
 *  - batchInsert($ids,$type): false for empty $ids, otherwise the write's own
 *    bool — true on success, false on a failed query (e.g. a duplicate composite
 *    key). Ids are truncated with (int) before insertion.
 *  - batchDelete($ids,$type): false for empty $ids, otherwise true on success
 *    and false on a failed query. Ids run through array_map('intval').
 *
 * count() is inherited from DAO (out of scope) and is pinned as a regression
 * guard only — it is the one method external callers read (Utils, the admin
 * locations tool), as a string count.
 *
 * No caller reads $model->dao->affectedRows()/insertedId()/getErrorLevel()
 * after any of these methods (grep of Utils::updateLocationStats and the admin
 * locations tool is clean), so traps 2.2/2.3 do not apply and no write has to
 * stay on the legacy path.
 *
 * Usage:  php tests/models/locationstmp.php          (standalone, own scratch database)
 *         php tests/run-models.php locationstmp      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_locationstmp');
$table = DB_TABLE_PREFIX . 't_locations_tmp';

$model = LocationsTmp::newInstance();

/**
 * The whole staging table as "id:type" pairs, ordered, joined by commas.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns; id_location is VARCHAR so it stays a
 * string either way, but keeping every verification read on the same query()
 * path the legacy model uses removes any doubt (amendment D).
 */
$dump = static function () use ($admin, $table): string {
    $out = array();
    $res = $admin->query("SELECT id_location, e_type FROM $table ORDER BY e_type, id_location");
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['id_location'] . ':' . $r['e_type'];
    }
    $res->free();

    return implode(',', $out);
};

/** Count staging rows of one e_type with raw mysqli. */
$countType = static function (string $type) use ($admin, $table): int {
    $res = $admin->query("SELECT COUNT(*) AS c FROM $table WHERE e_type = '" . $admin->real_escape_string($type) . "'");
    $r   = $res->fetch_assoc();
    $res->free();

    return (int)$r['c'];
};

/** True when a staging pair (id_location,e_type) exists. Raw, unprepared. */
$pairExists = static function (string $id, string $type) use ($admin, $table): bool {
    $res = $admin->query(
        "SELECT 1 FROM $table WHERE id_location = '" . $admin->real_escape_string($id)
        . "' AND e_type = '" . $admin->real_escape_string($type) . "' LIMIT 1"
    );
    $ok = $res->num_rows > 0;
    $res->free();

    return $ok;
};

/** Empty the staging table with raw mysqli, never through the code under test. */
$clear = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** Seed one staging pair directly. */
$seedPair = static function (string $id, string $type) use ($admin, $table): void {
    $admin->query(
        "INSERT INTO $table (id_location, e_type) VALUES ('" . $admin->real_escape_string($id)
        . "', '" . $admin->real_escape_string($type) . "')"
    );
};

/**
 * Run $fn with one table renamed out of the way, so every query touching it
 * fails — the only way to reach the error-fallback branches, since the staging
 * table has no argument-driven guard that a bad value could trip on its own.
 */
$withTableGone = static function (string $name, callable $fn) use ($admin) {
    $admin->query("RENAME TABLE `$name` TO `{$name}_hidden`");
    try {
        return $fn();
    } finally {
        $admin->query("RENAME TABLE `{$name}_hidden` TO `$name`");
    }
};

/** Silence the legacy warning a deliberately-malformed query emits. */
$quiet = static function (callable $fn) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $out  = $fn();
    error_reporting($prev);

    return $out;
};

/* -------------------------------------------------------------------------
 * The survivor world: two countries, each with one region and one city. US is
 * the set the destructive methods act on; CA is the survivor tree that must be
 * left untouched. Seeded raw so ids are known without re-querying.
 * ---------------------------------------------------------------------- */
seed_country($admin, 'US', 'United States');
seed_country($admin, 'CA', 'Canada');

$regUS = seed_region($admin, 'US', 'Region US');
$regCA = seed_region($admin, 'CA', 'Region CA');

$cityUS = seed_city($admin, $regUS, 'City US', 'US');
$cityCA = seed_city($admin, $regCA, 'City CA', 'CA');

/* =========================================================================
 * C2 — public/protected surface. Frozen so the conversion cannot add, remove,
 * rename or re-type anything a caller or a DAO-subclass plugin relies on.
 * ====================================================================== */
harness_section('LocationsTmp — public method surface (C2)');

$expectedSurface = array(
    '__construct'        => 'public __construct()',
    '__wakeup'           => 'public __wakeup()',
    'batchDelete'        => 'public batchDelete($ids, $type)',
    'batchInsert'        => 'public batchInsert($ids, $type)',
    'checkFieldKeys'     => 'public checkFieldKeys($aKey)',
    'count'              => 'public count()',
    'delete'             => 'public delete($where)',
    'deleteByPrimaryKey' => 'public deleteByPrimaryKey($value)',
    'exists'             => 'public exists(array $where): bool',
    'existsByPrimaryKey' => 'public existsByPrimaryKey($value)',
    'findByPrimaryKey'   => 'public findByPrimaryKey($value)',
    'getErrorDesc'       => 'public getErrorDesc()',
    'getErrorLevel'      => 'public getErrorLevel()',
    'getFields'          => 'public getFields()',
    'getLocations'       => 'public getLocations($max)',
    'getPrimaryKey'      => 'public getPrimaryKey()',
    'getTableName'       => 'public getTableName()',
    'getTablePrefix'     => 'public getTablePrefix()',
    'insert'             => 'public insert($values)',
    'listAll'            => 'public listAll()',
    'newInstance'        => 'public static newInstance()',
    'populateCities'     => 'public populateCities(): bool',
    'populateCountries'  => 'public populateCountries(): bool',
    'populateRegions'    => 'public populateRegions(): bool',
    'setFields'          => 'public setFields($fields)',
    'setPrimaryKey'      => 'public setPrimaryKey($key)',
    'setTableName'       => 'public setTableName($table)',
    'update'             => 'public update($values, $where)',
    'updateByPrimaryKey' => 'public updateByPrimaryKey($values, $key)',
);
pin('public method map is byte-identical', $expectedSurface, harness_public_method_map('LocationsTmp'));

pin('$model->dao is a live DBCommandClass (C5)', true, $model->dao instanceof DBCommandClass);
pin('getTableName() is the prefixed staging table', $table, $model->getTableName());
pin('getFields() is [id_location, e_type]', array('id_location', 'e_type'), $model->getFields());

/* =========================================================================
 * count() — inherited DAO method; regression guard only. Returns a STRING.
 * ====================================================================== */
harness_section('LocationsTmp::count — inherited, string count (regression guard)');

$clear();
pin('empty staging table counts "0"', '0', $model->count());
$seedPair('US', 'COUNTRY');
$seedPair('CA', 'COUNTRY');
pin('two rows count "2"', '2', $model->count());
$clear();

/* =========================================================================
 * getLocations($max)
 * ====================================================================== */
harness_section('LocationsTmp::getLocations — reads, typing and the limit gate');

$clear();
pin('getLocations over an empty table is array()', array(), $model->getLocations(10));

$seedPair('US', 'COUNTRY');
$seedPair('CA', 'COUNTRY');
$seedPair((string)$regUS, 'REGION');

$rows = $model->getLocations(10);
check('getLocations returns 3 rows', count($rows) === 3);
check('every getLocations row is all-string (C4)', all_rows_string($rows));

$firstKeys = array_keys($rows[0]);
pin('rows carry SELECT * column order (id_location, e_type)', array('id_location', 'e_type'), $firstKeys);

$limited = $model->getLocations(2);
pin('a numeric limit caps the row count', 2, count($limited));

// is_numeric() gate: a non-numeric limit drops the LIMIT clause entirely and
// returns ALL rows, rather than clamping to zero.
$all = $model->getLocations('not-a-number');
pin('a non-numeric limit returns ALL rows (legacy is_numeric gate)', 3, count($all));

// A negative numeric limit: legacy emits `LIMIT -3`, a syntax error the false
// branch absorbs into array(); the builder clamps to LIMIT 0 and returns [].
// Both observable results are the same empty array.
pin('a negative limit yields array() (error/clamp converge)', array(), $quiet(static function () use ($model) {
    return $model->getLocations(-3);
}));

$emptyOnError = $quiet(static function () use ($model, $withTableGone, $table) {
    return $withTableGone($table, static function () use ($model) {
        return $model->getLocations(10);
    });
});
pin('getLocations returns array() when the query fails', array(), $emptyOnError);

$clear();

/* =========================================================================
 * populateCountries / populateRegions / populateCities
 * ====================================================================== */
harness_section('LocationsTmp::populate* — INSERT IGNORE ... SELECT from the live tables');

$clear();

pin('populateCountries returns bool true', true, $model->populateCountries());
pin('...and both country codes are staged', 'CA:COUNTRY,US:COUNTRY', $dump());

pin('populateRegions returns bool true', true, $model->populateRegions());
pin('populateCities returns bool true', true, $model->populateCities());

pin('both region pks are staged', 2, $countType('REGION'));
pin('both city pks are staged', 2, $countType('CITY'));
check('the US country entry survives', $pairExists('US', 'COUNTRY'));
check('the CA (survivor-tree) country entry is present', $pairExists('CA', 'COUNTRY'));
check('the US region pk is staged', $pairExists((string)$regUS, 'REGION'));
check('the CA (survivor) region pk is staged', $pairExists((string)$regCA, 'REGION'));
check('the US city pk is staged', $pairExists((string)$cityUS, 'CITY'));
check('the CA (survivor) city pk is staged', $pairExists((string)$cityCA, 'CITY'));

// INSERT IGNORE: a second populate over the same source rows is a no-op on the
// count (duplicate composite keys are ignored) and still returns true.
$before = $dump();
pin('re-running populateCountries is idempotent and still true', true, $model->populateCountries());
pin('...the staging contents are unchanged', $before, $dump());

$populateFailsFalse = $quiet(static function () use ($model, $withTableGone, $table) {
    return $withTableGone($table, static function () use ($model) {
        return $model->populateCountries();
    });
});
pin('populate* returns false when the query fails', false, $populateFailsFalse);

/* =========================================================================
 * delete($where) — the affected-row count, survivors preserved
 * ====================================================================== */
harness_section('LocationsTmp::delete — affected-row count and survivor preservation');

// Staging currently holds the full two-country tree from the populate section.
pin('deleting one COUNTRY entry reports int 1', 1, $model->delete(array(
    'e_type'      => 'COUNTRY',
    'id_location' => 'US',
)));
check('the US country entry is gone', !$pairExists('US', 'COUNTRY'));
check('the CA (survivor) country entry is untouched', $pairExists('CA', 'COUNTRY'));
check('the US region entry is untouched by a COUNTRY delete', $pairExists((string)$regUS, 'REGION'));

pin('deleting a REGION entry by numeric id reports int 1', 1, $model->delete(array(
    'e_type'      => 'REGION',
    'id_location' => (string)$regUS,
)));
check('the US region entry is gone', !$pairExists((string)$regUS, 'REGION'));
check('the CA (survivor) region entry is untouched', $pairExists((string)$regCA, 'REGION'));

pin('a valid where matching nothing reports int 0', 0, $model->delete(array(
    'e_type'      => 'COUNTRY',
    'id_location' => 'ZZ',
)));

pin('an empty where returns false (no WHERE to run)', false, $model->delete(array()));

$deleteFailsFalse = $quiet(static function () use ($model, $withTableGone, $table) {
    return $withTableGone($table, static function () use ($model) {
        return $model->delete(array('e_type' => 'COUNTRY', 'id_location' => 'CA'));
    });
});
pin('delete returns false when the query fails', false, $deleteFailsFalse);

$clear();

/* =========================================================================
 * batchInsert($ids, $type)
 * ====================================================================== */
harness_section('LocationsTmp::batchInsert — empty guard, (int) cast, duplicate failure');

$clear();
pin('batchInsert with no ids returns false', false, $model->batchInsert(array(), 'REGION'));

pin('batchInsert returns bool true', true, $model->batchInsert(array(10, 20, 30), 'REGION'));
pin('...all three ids are staged under the given type', '10:REGION,20:REGION,30:REGION', $dump());

// Legacy cast each id with (int); a float or numeric-string id is truncated to
// its integer part before insertion.
$clear();
pin('batchInsert truncates ids with (int)', true, $model->batchInsert(array(7.9, '8abc'), 'CITY'));
pin('...ids landed as 7 and 8', '7:CITY,8:CITY', $dump());

// A repeated (id_location,e_type) pair collides on the composite primary key.
// batchInsert does NOT use INSERT IGNORE, so the write fails and returns false.
$clear();
$seedPair('99', 'REGION');
$dupFalse = $quiet(static function () use ($model) {
    return $model->batchInsert(array(99), 'REGION');
});
pin('batchInsert returns false on a duplicate-key failure', false, $dupFalse);

$batchInsertFailsFalse = $quiet(static function () use ($model, $withTableGone, $table) {
    return $withTableGone($table, static function () use ($model) {
        return $model->batchInsert(array(1, 2), 'CITY');
    });
});
pin('batchInsert returns false when the query fails', false, $batchInsertFailsFalse);

$clear();

/* =========================================================================
 * batchDelete($ids, $type)
 * ====================================================================== */
harness_section('LocationsTmp::batchDelete — empty guard, targeted drain, survivors');

$clear();
// Stage a mixed set: two US-tree regions/cities plus the CA survivor entries.
$seedPair((string)$regUS, 'REGION');
$seedPair((string)$regCA, 'REGION');
$seedPair((string)$cityUS, 'CITY');
$seedPair((string)$cityCA, 'CITY');

pin('batchDelete with no ids returns false', false, $model->batchDelete(array(), 'REGION'));

pin('batchDelete returns bool true', true, $model->batchDelete(array($regUS), 'REGION'));
check('the US region entry is drained', !$pairExists((string)$regUS, 'REGION'));
check('the CA (survivor) region entry remains', $pairExists((string)$regCA, 'REGION'));
check('a CITY row of the same numeric id is untouched (e_type scopes the delete)',
    $pairExists((string)$cityUS, 'CITY') || (string)$cityUS !== (string)$regUS);

// A type mismatch matches nothing but is still a successful query -> true.
pin('batchDelete of the right ids under the wrong type still returns true', true,
    $model->batchDelete(array($cityUS), 'REGION'));
check('...and nothing was actually removed (the CITY row stays)', $pairExists((string)$cityUS, 'CITY'));

$batchDeleteFailsFalse = $quiet(static function () use ($model, $withTableGone, $table, $regCA) {
    return $withTableGone($table, static function () use ($model, $regCA) {
        return $model->batchDelete(array($regCA), 'REGION');
    });
});
pin('batchDelete returns false when the query fails', false, $batchDeleteFailsFalse);

$clear();

/* =========================================================================
 * AMENDMENT-T SECTION — DELIBERATE BEHAVIOUR CHANGE.
 *
 * These pins record the POST-conversion behaviour and are therefore RED against
 * the pre-conversion model (amendment X): they are NOT characterization.
 *
 * Legacy DBCommandClass::escape() returns an is_numeric() value UNQUOTED, so
 * delete(['id_location' => '0']) compiled to `id_location = 0` — a NUMERIC
 * comparison against the VARCHAR column, coercing every non-numeric id to 0 and
 * matching (and DELETING) every country entry. That is the same type-confusion
 * that let CountryStats::decreaseNumItems('0') decrement US, and here it is
 * actively destructive to the live-location staging set. The policy (amendment
 * T) is to NOT reproduce the coercion: the converted code binds the value, so a
 * string '0' compares as a string and matches only a genuine '0' id.
 * ====================================================================== */
harness_section('LocationsTmp::delete — amendment-T coercion drop (RED vs unconverted, expected)');

$clear();
$seedPair('US', 'COUNTRY');
$seedPair('CA', 'COUNTRY');
// Pre-conversion: `id_location = 0` coerces 'US' and 'CA' to 0 and deletes BOTH
// (returns int 2). Post-conversion: string '0' matches neither, returns int 0.
pin('delete by numeric-coercing "0" now matches nothing (was 2, coercion dropped)', 0,
    $model->delete(array('e_type' => 'COUNTRY', 'id_location' => '0')));
check('...both real country entries survive (were wrongly deleted before)',
    $pairExists('US', 'COUNTRY') && $pairExists('CA', 'COUNTRY'));

$clear();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/locationstmp.php */
