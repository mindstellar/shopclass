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
 * Characterization pins for the Widget model.
 *
 * Widget is a hybrid model: distinctLocations(), reorder() and getNextOrder()
 * are already converted (their current behaviour is pinned here too, as a
 * regression floor); findByLocation() and findByDescription() are still legacy
 * and are the ones this test guards ahead of their own conversion.
 *
 * findByLocation() and findByDescription() never check numRows(): they hand
 * the raw recordset's result() straight back, so "no rows at all" and "SQL
 * error" both collapse to the same empty array() rather than false. A null
 * lookup value reaches that same array() by a different road:
 * DBCommandClass::_where() appends the comparison operator with no
 * right-hand side at all when the value is null, producing a genuine SQL
 * syntax error that dao->get() reports as bool false — the same quirk pinned
 * for CityArea/PluginCategory.
 *
 * Usage:  php tests/models/widget.php          (standalone, own scratch database)
 *         php tests/run-models.php widget      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_widget');
$table = DB_TABLE_PREFIX . 't_widget';

$model = Widget::newInstance();

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Widget: public surface');

pin(
    'findByLocation signature is unchanged',
    'public findByLocation($location)',
    harness_method_signature('Widget', 'findByLocation')
);
pin(
    'findByDescription signature is unchanged',
    'public findByDescription($description)',
    harness_method_signature('Widget', 'findByDescription')
);
pin(
    'distinctLocations signature is unchanged',
    'public distinctLocations()',
    harness_method_signature('Widget', 'distinctLocations')
);
pin(
    'reorder signature is unchanged',
    'public reorder(array $orderedIds)',
    harness_method_signature('Widget', 'reorder')
);
pin(
    'getNextOrder signature is unchanged',
    'public getNextOrder($location)',
    harness_method_signature('Widget', 'getNextOrder')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Widget', 'newInstance'));
check('Widget still extends DAO', is_subclass_of('Widget', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 's_description', 's_location', 'e_kind', 's_content', 'i_order', 's_type', 's_config'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct',
        'distinctLocations',
        'findByDescription',
        'findByLocation',
        'getNextOrder',
        'newInstance',
        'reorder',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Widget')),
        array(
            '__construct',
            'distinctLocations',
            'findByDescription',
            'findByLocation',
            'getNextOrder',
            'newInstance',
            'reorder',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * findByLocation() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Widget::findByLocation — empty table');

pin('no rows at all returns an empty array, not false', array(), $model->findByLocation('header'));

harness_section('Widget::findByLocation — ordered by i_order, then pk as a tiebreak');

$headerC = seed_widget($admin, 'header', 'Header widget C', '<p>c</p>', 2);
$headerA = seed_widget($admin, 'header', 'Header widget A', '<p>a</p>', 0);
$headerB = seed_widget($admin, 'header', 'Header widget B', '<p>b</p>', 0);

$rows = $model->findByLocation('header');
check('a match returns an array', is_array($rows), describe($rows));
pin('all three widgets in this location come back', 3, count($rows));
pin(
    'each row carries exactly the eight schema columns',
    array('pk_i_id', 's_description', 's_location', 'e_kind', 's_content', 'i_order', 's_type', 's_config'),
    array_keys($rows[0])
);
pin(
    'ordered by i_order ascending, tying rows broken by pk_i_id ascending (A before B, both before C)',
    array((string) $headerA, (string) $headerB, (string) $headerC),
    array_column($rows, 'pk_i_id')
);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

harness_section('Widget::findByLocation — filters by location');

$sidebarOne = seed_widget($admin, 'sidebar', 'Sidebar widget', '<p>s</p>', 0);

pin('the other location still has exactly one widget', 1, count($model->findByLocation('sidebar')));
pin('the header location is unaffected by the other location\'s widget', 3, count($model->findByLocation('header')));

harness_section('Widget::findByLocation — no match');

pin('an unused location returns an empty array', array(), $model->findByLocation('footer'));

harness_section('Widget::findByLocation — malformed lookup (null location)');

/* Passing null builds "s_location =" with no right-hand side, so the query
 * fails at the driver and the failure surfaces as "not found" rather than as
 * an error. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null location returns an empty array rather than raising', array(), $model->findByLocation(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByDescription() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Widget::findByDescription — empty table for this description');

$freshTable = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};
$freshTable();

pin('no rows at all returns an empty array, not false', array(), $model->findByDescription('A widget'));

harness_section('Widget::findByDescription — single match');

$descOne = seed_widget($admin, 'header', 'My widget', '<p>hi</p>', 0);

$rows = $model->findByDescription('My widget');
check('a match returns an array', is_array($rows), describe($rows));
pin('exactly one row comes back', 1, count($rows));
pin(
    'the row carries exactly the eight schema columns',
    array('pk_i_id', 's_description', 's_location', 'e_kind', 's_content', 'i_order', 's_type', 's_config'),
    array_keys($rows[0])
);
pin('pk_i_id round-trips as a string, not an int (C4)', (string) $descOne, $rows[0]['pk_i_id']);
pin('i_order round-trips as a string, not an int (C4)', '0', $rows[0]['i_order']);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

harness_section('Widget::findByDescription — duplicate descriptions all come back');

$descTwo = seed_widget($admin, 'sidebar', 'My widget', '<p>bye</p>', 0);

$rows = $model->findByDescription('My widget');
pin('both widgets sharing this description come back', 2, count($rows));
pin(
    'in insertion order (no ORDER BY on this method)',
    array((string) $descOne, (string) $descTwo),
    array_column($rows, 'pk_i_id')
);

harness_section('Widget::findByDescription — no match');

pin('an unused description returns an empty array', array(), $model->findByDescription('Nobody home'));

harness_section('Widget::findByDescription — malformed lookup (null description)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null description returns an empty array rather than raising', array(), $model->findByDescription(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * Regression floor: the ALREADY-converted methods, pinned so this unit does
 * not let them drift.
 * ------------------------------------------------------------------------- */
harness_section('Widget::distinctLocations — regression floor');

$freshTable();
pin('an empty table returns an empty array', array(), $model->distinctLocations());

$w1 = seed_widget($admin, 'header', 'H1');
$w2 = seed_widget($admin, 'sidebar', 'S1');
$w3 = seed_widget($admin, 'header', 'H2');

$locations = $model->distinctLocations();
sort($locations);
pin('every distinct location comes back exactly once', array('header', 'sidebar'), $locations);
check('every value is a string (C4)', all_values_string(array_combine($locations, $locations)), describe($locations));

harness_section('Widget::reorder — regression floor');

$order = $model->reorder(array($w2, $w3, $w1));
pin('a successful reorder returns true', true, $order);

$reordered = array();
$res       = $admin->query("SELECT pk_i_id, i_order FROM $table ORDER BY pk_i_id ASC");
while ($row = $res->fetch_assoc()) {
    $reordered[(int) $row['pk_i_id']] = (int) $row['i_order'];
}
$res->free();
pin('w2 is now first (i_order 0)', 0, $reordered[$w2]);
pin('w3 is now second (i_order 1)', 1, $reordered[$w3]);
pin('w1 is now third (i_order 2)', 2, $reordered[$w1]);

harness_section('Widget::getNextOrder — regression floor');

pin('the next order for a location with widgets is max + 1', 1, $model->getNextOrder('sidebar'));
pin('the next order for a location with no widgets yet is 0', 0, $model->getNextOrder('footer'));

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('Widget: query cost');

pin('one findByLocation() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByLocation('header');
}));
pin('one findByDescription() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByDescription('My widget');
}));

check('nothing extra was written by the read-only pins above', $rowCount() === 3, (string) $rowCount());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/widget.php */
