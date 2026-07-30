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
 * Characterization pins for the ItemLocation model.
 *
 * ItemLocation declares no query method of its own: __construct() only sets
 * the table/primary-key/field metadata, and newInstance() is the singleton
 * accessor. Every read/write a caller performs on it — insert() (item
 * publish), findByPrimaryKey() (item load), update() (item edit, and the
 * region/city rename cascades in the admin locations settings page) — comes
 * from the DAO base class untouched by this effort. There is therefore
 * nothing in this model for the conversion recipe to rewrite; this file pins
 * that the base-class behaviour still holds for this table's specific field
 * set (including the two DECIMAL coordinate columns) and that the surface is
 * exactly two methods, so a later change adding real query logic here is
 * forced to widen this test.
 *
 * Usage:  php tests/models/itemlocation.php      (standalone, own scratch database)
 *         php tests/run-models.php itemlocation  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_itemlocation');

/**
 * t_city_area has no AUTO_INCREMENT primary key, so the id is chosen by the
 * caller. scratchdb.php has no seed helper for it; this one is local to this
 * test per the no-shared-file rule.
 *
 * @return int The city-area id (equal to $id)
 */
$seedCityArea = static function (mysqli $admin, int $id, int $cityId, string $name = 'Downtown'): int {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (?, ?, ?)',
        'iis',
        array($id, $cityId, $name)
    );

    return $id;
};

/**
 * A bare t_item row with no t_item_location row attached — seed_item() in
 * scratchdb.php inserts one for every item it creates, which would collide
 * with a fresh 1:1 insert through the model under test.
 *
 * @return int The item id
 */
$seedBareItem = static function (mysqli $admin, int $categoryId): int {
    return seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_item
         (fk_i_category_id, dt_pub_date, s_contact_email) VALUES (?, NOW(), ?)',
        'is',
        array($categoryId, 'contact@example.test')
    );
};

$country    = seed_country($admin);
$categoryId = seed_category($admin);
$regionId   = seed_region($admin, $country);
$cityId     = seed_city($admin, $regionId, 'Springfield', $country);
$cityAreaId = $seedCityArea($admin, 1, $cityId, 'Downtown');

$model = ItemLocation::newInstance();
$table = DB_TABLE_PREFIX . 't_item_location';

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('ItemLocation: public surface');

pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('ItemLocation', 'newInstance')
);
check('ItemLocation still extends DAO', is_subclass_of('ItemLocation', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_i_item_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array(
        'fk_i_item_id',
        'fk_c_country_code',
        's_country',
        's_address',
        's_zip',
        'fk_i_region_id',
        's_region',
        'fk_i_city_id',
        's_city',
        'fk_i_city_area_id',
        's_city_area',
        'd_coord_lat',
        'd_coord_long',
    ),
    $model->getFields()
);

$ownMethods = array_values(array_map(
    static function (ReflectionMethod $m): string {
        return $m->getName();
    },
    array_filter(
        (new ReflectionClass('ItemLocation'))->getMethods(ReflectionMethod::IS_PUBLIC),
        static function (ReflectionMethod $m): bool {
            return $m->getDeclaringClass()->getName() === 'ItemLocation';
        }
    )
));
sort($ownMethods);
pin(
    'ItemLocation declares only construction and the singleton accessor — no query method of its own',
    array('__construct', 'newInstance'),
    $ownMethods
);

/* ----------------------------------------------------------------------------
 * Real-usage smoke test through the (untouched) DAO base. This is the exact
 * call shape ItemActions::addItem()/editItem() and the admin locations
 * settings page use: ->insert(), ->findByPrimaryKey(), ->update().
 * ------------------------------------------------------------------------- */
harness_section('ItemLocation: insert + findByPrimaryKey round-trip');

$itemId = $seedBareItem($admin, $categoryId);

$fullRow = array(
    'fk_i_item_id'      => $itemId,
    'fk_c_country_code' => $country,
    's_country'         => 'United States',
    's_address'         => '1 Main St',
    's_zip'             => '90210',
    'fk_i_region_id'    => $regionId,
    's_region'          => 'Alpha',
    'fk_i_city_id'      => $cityId,
    's_city'            => 'Springfield',
    'fk_i_city_area_id' => $cityAreaId,
    's_city_area'       => 'Downtown',
    'd_coord_lat'       => 40.712800,
    'd_coord_long'      => -74.006000,
);

check('insert() reports success', $model->insert($fullRow) !== false);

$row = $model->findByPrimaryKey($itemId);

check('findByPrimaryKey returns an array', is_array($row), describe($row));
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));
pin('fk_i_item_id round-trips as a string', (string)$itemId, $row['fk_i_item_id']);
pin('fk_c_country_code round-trips', $country, $row['fk_c_country_code']);
pin('s_address round-trips', '1 Main St', $row['s_address']);
pin('fk_i_region_id round-trips as a string', (string)$regionId, $row['fk_i_region_id']);
pin('fk_i_city_area_id round-trips as a string', (string)$cityAreaId, $row['fk_i_city_area_id']);
/* DECIMAL(10,6): the legacy read path (mysqli::query(), no placeholders) has
 * always returned this as a string, never a native float. */
pin('d_coord_lat round-trips as a fixed-precision decimal string', '40.712800', $row['d_coord_lat']);
pin('d_coord_long round-trips as a fixed-precision decimal string', '-74.006000', $row['d_coord_long']);

harness_section('ItemLocation: findByPrimaryKey — no match');

pin('an item id with no location row returns bool false', false, $model->findByPrimaryKey(999999));

harness_section('ItemLocation: update — single-field rename, the region/city-rename cascade shape');

$updateRet = $model->update(array('s_region' => 'Beta'), array('fk_i_region_id' => $regionId));

check('update() reports an affected-row count, not false', $updateRet !== false, describe($updateRet));
pin('exactly one row was touched', 1, (int)$updateRet);
pin('the rename landed', 'Beta', $model->findByPrimaryKey($itemId)['s_region']);

harness_section('ItemLocation: update — bad input (unknown field key)');

/* checkFieldKeys() runs before any SQL is built; this is DAO base behaviour,
 * unchanged and re-pinned here against this model's own field allowlist. */
pin(
    'a values array with an unknown key is rejected before any query runs',
    false,
    $model->update(array('not_a_field' => 'x'), array('fk_i_item_id' => $itemId))
);

harness_section('ItemLocation: updateByPrimaryKey + deleteByPrimaryKey');

$updateRet = $model->updateByPrimaryKey(array('s_city' => 'Shelbyville'), $itemId);
pin('updateByPrimaryKey affected exactly one row', 1, (int)$updateRet);
pin('the city change landed', 'Shelbyville', $model->findByPrimaryKey($itemId)['s_city']);

$deleteRet = $model->deleteByPrimaryKey($itemId);
pin('deleteByPrimaryKey affected exactly one row', 1, (int)$deleteRet);
pin('the row is gone', false, $model->findByPrimaryKey($itemId));

/* ----------------------------------------------------------------------------
 * Query cost — a single primary-key lookup is one statement.
 * ------------------------------------------------------------------------- */
harness_section('ItemLocation: query cost');

$secondItemId = $seedBareItem($admin, $categoryId);
$model->insert(array('fk_i_item_id' => $secondItemId, 's_country' => 'United States'));

pin('one lookup costs one query', 1, harness_query_count(static function () use ($model, $secondItemId) {
    $model->findByPrimaryKey($secondItemId);
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemlocation.php */
