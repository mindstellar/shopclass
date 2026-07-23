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
 * Characterization pins for the City model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * ajax()/findByRegion()/findByName()/listAll()/findBySlug()/listByEmptySlug()
 * move to the parameterized query layer.
 *
 * ajax() joins t_region (aliased `aux`) and aliases every selected column, both
 * of which the builder's identifier allowlist rejects, so its converted body is
 * hand-written SQL. Its LIKE goes through the legacy dao->like('after'), which
 * already escapes '%'/'_' in the payload before appending the wildcard (unlike
 * the raw-`escape()`-inside-a-LIKE-string shape elsewhere), so a literal '%'
 * typed by a caller stays literal both before and after conversion.
 *
 * findByName()/findByRegion()/findBySlug()/listByEmptySlug()/listAll() never
 * check numRows(): a 0-row match and a failed query both collapse to the same
 * empty-array/false-becomes-array() shape, the same quirk pinned for Cron and
 * CityArea.
 *
 * deleteByPrimaryKey() has no query of its own to convert: every line either
 * delegates to another model's method (CityArea::findByCity/deleteByPrimaryKey,
 * Item::deleteByCity, CityStats::delete, User::update) or to the inherited
 * DAO::delete(). Its signature and cascade behaviour are pinned; the method
 * body is not touched by this conversion (CityArea's own deleteByPrimaryKey is
 * the precedent for this shape).
 *
 * Usage:  php tests/models/city.php          (standalone, own scratch database)
 *         php tests/run-models.php city      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_city');

$model = City::newInstance();
$table = DB_TABLE_PREFIX . 't_city';

/**
 * t_city_area.pk_i_id is NOT AUTO_INCREMENT, so every insert supplies an id.
 */
$seedCityArea = static function (int $id, int $cityId, string $name) use ($admin): int {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (?, ?, ?)',
        'iis',
        array($id, $cityId, $name)
    );

    return $id;
};

$seedCityStats = static function (int $cityId, int $numItems = 3) use ($admin): void {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_city_stats (fk_i_city_id, i_num_items) VALUES (?, ?)',
        'ii',
        array($cityId, $numItems)
    );
};

$setUserCity = static function (int $userId, int $cityId, string $cityName) use ($admin): void {
    seed_exec(
        $admin,
        'UPDATE ' . DB_TABLE_PREFIX . 't_user SET fk_i_city_id = ?, s_city = ? WHERE pk_i_id = ?',
        'isi',
        array($cityId, $cityName, $userId)
    );
};

$cityRowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$country = seed_country($admin);
$regionOneId = seed_region($admin, $country, 'North');
$regionTwoId = seed_region($admin, $country, 'South');

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('City: public surface');

pin(
    'ajax signature is unchanged',
    'public ajax($query, $regionId = NULL)',
    harness_method_signature('City', 'ajax')
);
pin(
    'getByRegion signature is unchanged',
    'public getByRegion($regionId)',
    harness_method_signature('City', 'getByRegion')
);
pin(
    'findByRegion signature is unchanged',
    'public findByRegion($regionId)',
    harness_method_signature('City', 'findByRegion')
);
pin(
    'findByName signature is unchanged',
    'public findByName($cityName, $regionId = NULL)',
    harness_method_signature('City', 'findByName')
);
pin(
    'listAll signature is unchanged',
    'public listAll()',
    harness_method_signature('City', 'listAll')
);
pin(
    'deleteByPrimaryKey signature is unchanged',
    'public deleteByPrimaryKey($pk)',
    harness_method_signature('City', 'deleteByPrimaryKey')
);
pin(
    'findBySlug signature is unchanged',
    'public findBySlug($slug)',
    harness_method_signature('City', 'findBySlug')
);
pin(
    'listByEmptySlug signature is unchanged',
    'public listByEmptySlug()',
    harness_method_signature('City', 'listByEmptySlug')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('City', 'newInstance')
);
check('City still extends DAO', is_subclass_of('City', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 'fk_i_region_id', 's_name', 'fk_c_country_code', 'b_active', 's_slug'),
    $model->getFields()
);
pin(
    'the model adds exactly eight methods of its own',
    array(
        '__construct',
        'ajax',
        'deleteByPrimaryKey',
        'findByName',
        'findByRegion',
        'findBySlug',
        'getByRegion',
        'listAll',
        'listByEmptySlug',
        'newInstance',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('City')),
        array(
            '__construct',
            'newInstance',
            'ajax',
            'getByRegion',
            'findByRegion',
            'findByName',
            'listAll',
            'deleteByPrimaryKey',
            'findBySlug',
            'listByEmptySlug',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * ajax() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('City::ajax — empty table');

pin('no rows at all returns an empty array, not false', array(), $model->ajax('Spring'));

harness_section('City::ajax — single match, no region filter');

$springfieldId = seed_city($admin, $regionOneId, 'Springfield', $country);

$rows = $model->ajax('Spring');
check('a match returns an array', is_array($rows), describe($rows));
pin('exactly one row matches the prefix', 1, count($rows));
pin(
    'the row carries exactly the four aliased columns',
    array('id', 'label', 'value', 'region'),
    array_keys($rows[0])
);
pin('id round-trips', (string) $springfieldId, $rows[0]['id']);
pin('label carries the city name', 'Springfield', $rows[0]['label']);
pin('value carries the city name', 'Springfield', $rows[0]['value']);
pin('region carries the joined region name', 'North', $rows[0]['region']);
check('every value in the row is a string or null (C4)', all_values_string($rows[0]), describe($rows[0]));

harness_section('City::ajax — prefix match is anchored (LIKE "after")');

pin('a query that only matches mid-string does not match', array(), $model->ajax('ingfield'));

harness_section('City::ajax — filtered by numeric region id');

$shelbyvilleId = seed_city($admin, $regionTwoId, 'Shelbyville', $country);
seed_city($admin, $regionOneId, 'Shelbyville Heights', $country);

$rows = $model->ajax('Shelbyville', $regionOneId);
pin('the numeric-region filter finds only the North-region match', 1, count($rows));
pin('the North-region Shelbyville row comes back', 'Shelbyville Heights', $rows[0]['label']);

harness_section('City::ajax — filtered by region name (non-numeric)');

$rows = $model->ajax('Shelbyville', 'South');
pin('the region-name filter finds only the South-region match', 1, count($rows));
pin('the South-region Shelbyville row comes back', 'Shelbyville', $rows[0]['label']);

harness_section('City::ajax — a literal "%" in the query is not a wildcard');

$percentCityId = seed_city($admin, $regionOneId, '50% Town', $country);
$decoyCityId = seed_city($admin, $regionOneId, '5099Ville', $country);

pin(
    'the literal percent sign is escaped, so "50%" matches only the literal "50% " prefix',
    array((string) $percentCityId),
    array_column($model->ajax('50%'), 'id')
);
pin(
    'an unescaped "50%" would also match "5099Ville" via the SQL wildcard — it must not',
    array(),
    array_filter($model->ajax('50%'), static function ($r) use ($decoyCityId) {
        return $r['id'] === (string) $decoyCityId;
    })
);

harness_section('City::ajax — no match');

pin('an unknown prefix returns an empty array', array(), $model->ajax('Nowhereville'));

/* ----------------------------------------------------------------------------
 * getByRegion() / findByRegion() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('City::findByRegion — a region with two cities, ordered by name');

$rows = $model->findByRegion($regionOneId);
check('a match returns an array', is_array($rows), describe($rows));
$names = array_column($rows, 's_name');
pin(
    'all North-region cities come back, ordered by s_name ASC',
    array('50% Town', '5099Ville', 'Shelbyville Heights', 'Springfield'),
    $names
);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

harness_section('City::getByRegion — deprecated alias delegates to findByRegion');

pin('getByRegion returns the same rows as findByRegion', $model->findByRegion($regionOneId), $model->getByRegion($regionOneId));

harness_section('City::findByRegion — a region with no cities');

$regionEmptyId = seed_region($admin, $country, 'Empty');
pin('a region with no cities returns an empty array', array(), $model->findByRegion($regionEmptyId));

harness_section('City::findByRegion — malformed lookup (null region id)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null region id returns an empty array rather than raising', array(), $model->findByRegion(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByName() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('City::findByName — single match, no region filter');

$row = $model->findByName('Springfield');
check('a match returns an array', is_array($row), describe($row));
pin('the row carries every schema column', array('pk_i_id', 'fk_i_region_id', 's_name', 'fk_c_country_code', 'b_active', 's_slug'), array_keys($row));
pin('pk_i_id round-trips', (string) $springfieldId, $row['pk_i_id']);
pin('s_name round-trips', 'Springfield', $row['s_name']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('City::findByName — filtered by region');

pin(
    'the region filter reaches the North-region Shelbyville row',
    'Shelbyville Heights',
    $model->findByName('Shelbyville Heights', $regionOneId)['s_name']
);
pin(
    'a known name with a non-matching regionId filter returns an empty array (not false)',
    array(),
    $model->findByName('Shelbyville Heights', $regionTwoId)
);

harness_section('City::findByName — no match');

pin('an unknown name returns an empty array', array(), $model->findByName('Nowhereville'));

harness_section('City::findByName — malformed lookup (null name)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null name returns an empty array rather than raising', array(), $model->findByName(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listAll() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('City::listAll — every city, ordered by name');

$rows = $model->listAll();
$names = array_column($rows, 's_name');
pin(
    'every seeded city comes back, ordered by s_name ASC',
    array('50% Town', '5099Ville', 'Shelbyville', 'Shelbyville Heights', 'Springfield'),
    $names
);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

/* ----------------------------------------------------------------------------
 * findBySlug() / listByEmptySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('City::findBySlug — a match');

$row = $model->findBySlug('springfield');
check('a match returns an array', is_array($row), describe($row));
pin('s_name round-trips', 'Springfield', $row['s_name']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('City::findBySlug — no match');

pin('an unknown slug returns an empty array, not false', array(), $model->findBySlug('does-not-exist'));

harness_section('City::listByEmptySlug — every slug is non-empty today');

pin('no city has an empty slug in this fixture', array(), $model->listByEmptySlug());

$blankSlugId = seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_city (fk_i_region_id, s_name, s_slug, fk_c_country_code, b_active)
     VALUES (?, ?, ?, ?, 1)',
    'isss',
    array($regionOneId, 'Blankslugtown', '', $country)
);

$rows = $model->listByEmptySlug();
pin('exactly the blank-slug city comes back', 1, count($rows));
pin('the blank-slug city id matches', (string) $blankSlugId, $rows[0]['pk_i_id']);

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('City: query cost');

pin('one ajax() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->ajax('Spring');
}));
pin('one findByRegion() call costs one query', 1, harness_query_count(static function () use ($model, $regionOneId) {
    $model->findByRegion($regionOneId);
}));
pin('one findByName() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByName('Springfield');
}));
pin('one listAll() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listAll();
}));
pin('one findBySlug() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findBySlug('springfield');
}));
pin('one listByEmptySlug() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listByEmptySlug();
}));

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey() — cascade into city areas and dependents.
 *
 * Not touched by the conversion (see file header); pinned as a regression
 * guard against the delegated calls it makes.
 * ------------------------------------------------------------------------- */
harness_section('City::deleteByPrimaryKey — cascade');

$cascadeCityId = seed_city($admin, $regionOneId, 'Cascadia', $country);
$areaOneId = $seedCityArea(101, $cascadeCityId, 'Downtown');
$areaTwoId = $seedCityArea(102, $cascadeCityId, 'Uptown');
$seedCityStats($cascadeCityId, 7);
$cascadeUserId = seed_user($admin, 'cascadeuser', 'cascade@example.test');
$setUserCity($cascadeUserId, $cascadeCityId, 'Cascadia');

$deleteResult = $model->deleteByPrimaryKey($cascadeCityId);

pin('a clean cascade reports zero failed deletions', 0, $deleteResult);

$areaCount = (int) $admin->query(
    'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_city_area WHERE fk_i_city_id = ' . (int) $cascadeCityId
)->fetch_assoc()['c'];
pin('both city areas were deleted', 0, $areaCount);

$statsCount = (int) $admin->query(
    'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_city_stats WHERE fk_i_city_id = ' . (int) $cascadeCityId
)->fetch_assoc()['c'];
pin('the city stats row was deleted', 0, $statsCount);

$userRow = $admin->query(
    'SELECT fk_i_city_id, s_city FROM ' . DB_TABLE_PREFIX . 't_user WHERE pk_i_id = ' . (int) $cascadeUserId
)->fetch_assoc();
pin('the user\'s fk_i_city_id was cleared', null, $userRow['fk_i_city_id']);
pin('the user\'s s_city was blanked', '', $userRow['s_city']);

$cityCount = (int) $admin->query(
    'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_city WHERE pk_i_id = ' . (int) $cascadeCityId
)->fetch_assoc()['c'];
pin('the city row itself was deleted', 0, $cityCount);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/city.php */
