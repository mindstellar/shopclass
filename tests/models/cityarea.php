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
 * Characterization pins for the CityArea model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * findByName()/findByCity() move to the parameterized query layer.
 *
 * findByName() and findByCity() never check numRows(): they hand the raw
 * recordset's row()/result() straight back, so "no match" and "SQL error"
 * both collapse to the same empty array() rather than false. A null lookup
 * value reaches that same array() by a different road: DBCommandClass::_where()
 * appends the comparison operator with no right-hand side at all when the
 * value is null (not even the literal NULL), producing a genuine SQL syntax
 * error that dao->get() reports as bool false.
 *
 * deleteByPrimaryKey() has no query of its own to convert: it is pure
 * delegation to Item::deleteByCityArea(), User::update() and the inherited
 * DAO::delete(). Its signature is pinned for C2; its cascade behaviour is not
 * exercised here because that would require bootstrapping Item's hook
 * pipeline, well outside a model-layer characterization test, and the method
 * body is not touched by this conversion regardless.
 *
 * Usage:  php tests/models/cityarea.php          (standalone, own scratch database)
 *         php tests/run-models.php cityarea      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_cityarea');

$model = CityArea::newInstance();
$table = DB_TABLE_PREFIX . 't_city_area';

/**
 * t_city_area.pk_i_id is NOT AUTO_INCREMENT (struct.sql declares no
 * AUTO_INCREMENT on it), so every insert has to supply an id explicitly.
 *
 * @return int The id just inserted, for convenience
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

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$country = seed_country($admin);
$regionId = seed_region($admin, $country);
$cityOneId = seed_city($admin, $regionId, 'Springfield', $country);
$cityTwoId = seed_city($admin, $regionId, 'Shelbyville', $country);

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('CityArea: public surface');

pin(
    'findByName signature is unchanged',
    'public findByName($cityAreaName, $cityId = NULL)',
    harness_method_signature('CityArea', 'findByName')
);
pin(
    'findByCity signature is unchanged',
    'public findByCity($cityId)',
    harness_method_signature('CityArea', 'findByCity')
);
pin(
    'deleteByPrimaryKey signature is unchanged',
    'public deleteByPrimaryKey($pk)',
    harness_method_signature('CityArea', 'deleteByPrimaryKey')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('CityArea', 'newInstance')
);
check('CityArea still extends DAO', is_subclass_of('CityArea', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('pk_i_id', 'fk_i_city_id', 's_name'), $model->getFields());
pin(
    'the model adds exactly three methods of its own',
    array('__construct', 'deleteByPrimaryKey', 'findByCity', 'findByName', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('CityArea')),
        array('__construct', 'newInstance', 'findByName', 'findByCity', 'deleteByPrimaryKey')
    ))
);

/* ----------------------------------------------------------------------------
 * findByName() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('CityArea::findByName — empty table');

pin('no rows at all returns an empty array, not false', array(), $model->findByName('Downtown'));

harness_section('CityArea::findByName — single match, no city filter');

$downtownOneId = $seedCityArea(1, $cityOneId, 'Downtown');

$row = $model->findByName('Downtown');
check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the three schema columns', array('pk_i_id', 'fk_i_city_id', 's_name'), array_keys($row));
pin('pk_i_id round-trips', (string) $downtownOneId, $row['pk_i_id']);
pin('fk_i_city_id round-trips', (string) $cityOneId, $row['fk_i_city_id']);
pin('s_name round-trips', 'Downtown', $row['s_name']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('CityArea::findByName — filtered by city');

$downtownTwoId = $seedCityArea(2, $cityTwoId, 'Downtown');

pin(
    'the same name in a different city is reached with the cityId filter',
    (string) $downtownTwoId,
    $model->findByName('Downtown', $cityTwoId)['pk_i_id']
);
pin(
    'the cityId filter still finds the first city\'s row',
    (string) $downtownOneId,
    $model->findByName('Downtown', $cityOneId)['pk_i_id']
);
pin(
    'without a cityId filter, only one of the two duplicate names comes back — the first inserted',
    (string) $downtownOneId,
    $model->findByName('Downtown')['pk_i_id']
);

harness_section('CityArea::findByName — no match');

pin('an unknown name returns an empty array', array(), $model->findByName('Nowhere'));
pin(
    'a known name with a non-matching cityId filter returns an empty array',
    array(),
    $model->findByName('Downtown', $cityTwoId + 1000)
);

harness_section('CityArea::findByName — malformed lookup (null name)');

/* Passing null builds a comparison with no right-hand side, so the query
 * fails at the driver and the failure surfaces as "not found" rather than as
 * an error — the same quirk pinned for Cron::getCronByType(null). */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null name returns an empty array rather than raising', array(), $model->findByName(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByCity() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('CityArea::findByCity — a city with two areas');

$rows = $model->findByCity($cityOneId);
check('a match returns an array', is_array($rows), describe($rows));
pin('only the requested city\'s row comes back', 1, count($rows));
pin('s_name round-trips', 'Downtown', $rows[0]['s_name']);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

$seedCityArea(3, $cityOneId, 'Uptown');

$rows = $model->findByCity($cityOneId);
pin('a second area for the same city brings the count to two', 2, count($rows));
$names = array_column($rows, 's_name');
sort($names);
pin('both area names are present', array('Downtown', 'Uptown'), $names);

harness_section('CityArea::findByCity — a city with no areas');

$cityThreeId = seed_city($admin, $regionId, 'Capital City', $country);
pin('a city with no areas returns an empty array', array(), $model->findByCity($cityThreeId));

harness_section('CityArea::findByCity — malformed lookup (null city id)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null city id returns an empty array rather than raising', array(), $model->findByCity(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('CityArea: query cost');

pin('one findByName() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByName('Downtown');
}));
pin('one findByCity() call costs one query', 1, harness_query_count(static function () use ($model, $cityOneId) {
    $model->findByCity($cityOneId);
}));

check('nothing was written by any of the above (read-only pins)', $rowCount() === 3, (string) $rowCount());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/cityarea.php */
