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
 * Characterization pins for the PluginCategory model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer.
 *
 * All three methods share one quirk: a malformed argument (null, in particular)
 * builds a WHERE clause with no right-hand side, the query fails at the driver,
 * and the failure is swallowed into the method's own "nothing found" value
 * rather than surfacing as an error — array() for findByCategoryId()/
 * listSelected(), bool false for isThisCategory(). That is observably identical
 * to a valid lookup that simply matches zero rows, so the ledger pins both
 * paths landing on the same value rather than assuming a distinct error branch
 * exists.
 *
 * t_plugin_category declares no primary key (s_plugin_name + fk_i_category_id
 * is not even a composite key in struct.sql), so more than one plugin can be
 * associated with the same category and the same plugin can appear against
 * more than one category — both are exercised below.
 *
 * Usage:  php tests/models/plugincategory.php      (standalone, own scratch database)
 *         php tests/run-models.php plugincategory  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_plugincategory');
$table = DB_TABLE_PREFIX . 't_plugin_category';

/**
 * Insert one association row with raw mysqli, never through the code under
 * test. scratchdb.php has no seed helper for this table, so it lives here.
 */
$seedPluginCategory = static function (int $categoryId, string $plugin) use ($admin, $table): void {
    $stmt = $admin->prepare("INSERT INTO $table (s_plugin_name, fk_i_category_id) VALUES (?, ?)");
    $stmt->bind_param('si', $plugin, $categoryId);
    $stmt->execute();
    $stmt->close();
};

$model = PluginCategory::newInstance();

$catMotors = seed_category($admin, 'Motors');
$catJobs   = seed_category($admin, 'Jobs');

$seedPluginCategory($catMotors, 'pluginA');
$seedPluginCategory($catJobs, 'pluginA');
$seedPluginCategory($catMotors, 'pluginB');

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('PluginCategory: public surface');

pin(
    'findByCategoryId signature is unchanged',
    'public findByCategoryId($categoryId)',
    harness_method_signature('PluginCategory', 'findByCategoryId')
);
pin(
    'listSelected signature is unchanged',
    'public listSelected($plugin)',
    harness_method_signature('PluginCategory', 'listSelected')
);
pin(
    'isThisCategory signature is unchanged',
    'public isThisCategory($pluginName, $categoryId)',
    harness_method_signature('PluginCategory', 'isThisCategory')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('PluginCategory', 'newInstance')
);
check('PluginCategory still extends DAO', is_subclass_of('PluginCategory', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('no primary key was ever set', null, $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('s_plugin_name', 'fk_i_category_id'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array('__construct', 'findByCategoryId', 'isThisCategory', 'listSelected', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('PluginCategory')),
        array('__construct', 'findByCategoryId', 'isThisCategory', 'listSelected', 'newInstance')
    ))
);

/* ----------------------------------------------------------------------------
 * findByCategoryId — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('PluginCategory::findByCategoryId — a category with two plugins');

$rows = $model->findByCategoryId($catMotors);
check('two rows are returned', is_array($rows) && count($rows) === 2, describe($rows));
pin(
    'each row carries exactly the two schema columns',
    array('s_plugin_name', 'fk_i_category_id'),
    array_keys($rows[0])
);
pin('the first inserted association comes first', 'pluginA', $rows[0]['s_plugin_name']);
pin('the second inserted association comes second', 'pluginB', $rows[1]['s_plugin_name']);
pin('fk_i_category_id round-trips as a string, not an int (C4)', (string) $catMotors, $rows[0]['fk_i_category_id']);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

harness_section('PluginCategory::findByCategoryId — a category with one plugin');

$rows = $model->findByCategoryId($catJobs);
pin('exactly one row', 1, count($rows));
pin('it is the association for that category', 'pluginA', $rows[0]['s_plugin_name']);

harness_section('PluginCategory::findByCategoryId — no match');

pin('an unused category id returns an empty array', array(), $model->findByCategoryId(999999));

harness_section('PluginCategory::findByCategoryId — malformed lookup');

/* Passing null builds "fk_i_category_id =" with no right-hand side, so the
 * query fails at the driver. The model has no false-branch of its own here —
 * get() returning false is caught by the SAME check that handles a query
 * returning zero rows, so a driver failure and a clean "not found" are
 * indistinguishable from the caller's side. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('null returns an empty array rather than raising', array(), $model->findByCategoryId(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listSelected — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('PluginCategory::listSelected — a plugin with two categories');

$list = $model->listSelected('pluginA');
pin('both category ids are returned, in insertion order', array((string) $catMotors, (string) $catJobs), $list);
check('every value in the list is a string (C4)', all_values_string(array_combine(array_keys($list), $list)), describe($list));

harness_section('PluginCategory::listSelected — a plugin with one category');

pin('a single-category plugin returns a one-element list', array((string) $catMotors), $model->listSelected('pluginB'));

harness_section('PluginCategory::listSelected — no match');

pin('an unknown plugin name returns an empty array', array(), $model->listSelected('nosuchplugin'));

harness_section('PluginCategory::listSelected — malformed lookup');

/* Same shape as findByCategoryId(): a null plugin name builds a WHERE clause
 * with no right-hand side, the query fails, and the failure lands on the same
 * empty-array branch a clean no-match would. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('null returns an empty array rather than raising', array(), $model->listSelected(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * isThisCategory — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('PluginCategory::isThisCategory — match');

pin('an existing association returns true', true, $model->isThisCategory('pluginA', $catMotors));
pin('a different existing association also returns true', true, $model->isThisCategory('pluginA', $catJobs));

harness_section('PluginCategory::isThisCategory — no match');

pin(
    'a plugin/category pair with no row returns false',
    false,
    $model->isThisCategory('pluginB', $catJobs)
);
pin(
    'an unknown category id returns false',
    false,
    $model->isThisCategory('pluginA', 999999)
);
pin(
    'an unknown plugin name returns false',
    false,
    $model->isThisCategory('nosuchplugin', $catMotors)
);

harness_section('PluginCategory::isThisCategory — malformed lookup');

/* COUNT(*) always returns exactly one row, so the method's own
 * numRows() == 0 branch is unreachable in practice; a bad argument is instead
 * absorbed one step earlier, at the same get() === false check every other
 * method in this class uses. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null plugin name returns false rather than raising', false, $model->isThisCategory(null, $catMotors));
pin('a null category id returns false rather than raising', false, $model->isThisCategory('pluginA', null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * Query cost — each method is a single statement, and stays one.
 * ------------------------------------------------------------------------- */
harness_section('PluginCategory: query cost');

pin('findByCategoryId costs one query', 1, harness_query_count(static function () use ($model, $catMotors) {
    $model->findByCategoryId($catMotors);
}));
pin('listSelected costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listSelected('pluginA');
}));
pin('isThisCategory costs one query', 1, harness_query_count(static function () use ($model, $catMotors) {
    $model->isThisCategory('pluginA', $catMotors);
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/plugincategory.php */
