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
 * Characterization pins for the ItemModerationLog model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * findByItem()'s body moves to the parameterized query layer.
 *
 * add() has no query text of its own: it builds a values array with every field
 * already coerced ((int) the item id, (string)/substr() every text field), and
 * hands it to DAO::insert() — the two-argument base method, out of scope for
 * this effort. Nothing add() does can put a null into the query it runs, so the
 * null-where correction does not apply to it; it is pinned here as a regression
 * guard only, not converted.
 *
 * findByItem() casts its argument with (int) before it ever reaches the WHERE
 * clause, so a null/bad argument becomes 0, never SQL NULL — the null-where
 * correction (a bare "col =" with no right-hand side) does not apply here
 * either. Its failure branch and its zero-row branch already return the same
 * value (empty array), so the conversion is safe regardless.
 *
 * latestForItem() has no query of its own: it calls findByItem() and indexes
 * into the result, so its ledger rides on findByItem()'s.
 *
 * Usage:  php tests/models/itemmoderationlog.php      (standalone, own scratch database)
 *         php tests/run-models.php itemmoderationlog  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_itemmoderationlog');
$table = DB_TABLE_PREFIX . 't_item_moderation_log';

$model = ItemModerationLog::newInstance();

/**
 * t_item_moderation_log declares no FK constraint on fk_i_item_id (an index
 * only), so a fixture item id needs no real t_item row behind it — but real
 * items are seeded anyway to keep the fixture realistic.
 */
$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/**
 * Read every row back with raw mysqli, never through the code under test.
 *
 * @return array
 */
$logRows = static function () use ($admin, $table): array {
    $res  = $admin->query("SELECT * FROM $table ORDER BY pk_i_id ASC");
    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
};

$catId    = seed_category($admin, 'Motors');
$itemOne  = seed_item($admin, $catId, null, 'Listing one');
$itemTwo  = seed_item($admin, $catId, null, 'Listing two');

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('ItemModerationLog: public surface');

pin(
    'add signature is unchanged',
    "public add(\$itemId, \$source, \$reason, \$field = '', \$action = '')",
    harness_method_signature('ItemModerationLog', 'add')
);
pin(
    'findByItem signature is unchanged',
    'public findByItem($itemId)',
    harness_method_signature('ItemModerationLog', 'findByItem')
);
pin(
    'latestForItem signature is unchanged',
    'public latestForItem($itemId)',
    harness_method_signature('ItemModerationLog', 'latestForItem')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('ItemModerationLog', 'newInstance'));
check('ItemModerationLog still extends DAO', is_subclass_of('ItemModerationLog', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 'fk_i_item_id', 's_source', 's_reason', 's_field', 's_action', 'dt_date'),
    $model->getFields()
);
pin(
    'the model adds exactly four methods of its own',
    array('__construct', 'add', 'findByItem', 'latestForItem', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('ItemModerationLog')),
        array('__construct', 'add', 'findByItem', 'latestForItem', 'newInstance')
    ))
);

/* ----------------------------------------------------------------------------
 * add() — the return ledger and the row it writes. Pinned as a regression
 * guard; not converted (pure delegation to DAO::insert(), out of scope).
 * ------------------------------------------------------------------------- */
harness_section('ItemModerationLog::add — success');

$ret = $model->add($itemOne, 'keyword', 'viagra', 'title', 'spam');
pin('a successful write returns bool true', true, $ret);

$rows = $logRows();
check('exactly one row was written', count($rows) === 1, (string) count($rows));
$row = $rows[0] ?? array();
pin(
    'the row carries exactly the seven schema columns',
    array('pk_i_id', 'fk_i_item_id', 's_source', 's_reason', 's_field', 's_action', 'dt_date'),
    array_keys($row)
);
pin('fk_i_item_id round-trips', (string) $itemOne, $row['fk_i_item_id']);
pin('s_source round-trips', 'keyword', $row['s_source']);
pin('s_reason round-trips', 'viagra', $row['s_reason']);
pin('s_field round-trips', 'title', $row['s_field']);
pin('s_action round-trips', 'spam', $row['s_action']);
check(
    'dt_date is populated with a real timestamp',
    isset($row['dt_date']) && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['dt_date']),
    describe($row['dt_date'] ?? null)
);

harness_section('ItemModerationLog::add — default field/action are empty strings');

$model->add($itemOne, 'report_threshold', '5 reports');
$rows = $logRows();
$row  = $rows[count($rows) - 1];
pin('s_field defaults to an empty string when omitted', '', $row['s_field']);
pin('s_action defaults to an empty string when omitted', '', $row['s_action']);

harness_section('ItemModerationLog::add — oversized values are truncated, not rejected');

$model->add($itemOne, str_repeat('x', 30), str_repeat('y', 200), str_repeat('z', 30), str_repeat('w', 30));
$rows = $logRows();
$row  = $rows[count($rows) - 1];
pin('s_source is truncated to 20 characters', str_repeat('x', 20), $row['s_source']);
pin('s_reason is truncated to 191 characters', str_repeat('y', 191), $row['s_reason']);
pin('s_field is truncated to 20 characters', str_repeat('z', 20), $row['s_field']);
pin('s_action is truncated to 20 characters', str_repeat('w', 20), $row['s_action']);

harness_section('ItemModerationLog::add — out-of-range item id is clamped, not rejected');

/* fk_i_item_id is INT UNSIGNED NOT NULL, and (int) casts a negative string to
 * a genuine negative int — the obvious hypothesis is that the unsigned column
 * rejects it. It does not: DBConnectionClass::setSQLMode() strips
 * STRICT_TRANS_TABLES from every connection unless OSC_DB_STRICT_MODE is
 * defined, so MySQL silently clamps the out-of-range value to 0 and the
 * insert succeeds. There is in fact no reachable path through add()'s public
 * signature that makes DAO::insert() return false: every value it builds is
 * coerced with (int)/(string)/substr() first, checkFieldKeys() always passes
 * (the key set is fixed), and pk_i_id is auto-increment, so no duplicate-key
 * failure is reachable either. The false-return branch is therefore pinned as
 * unreachable via the public API, not exercised. */
$ret = $model->add(-1, 'keyword', 'x');
pin('an out-of-range item id still returns bool true', true, $ret);
$rows = $logRows();
$row  = $rows[count($rows) - 1];
pin('the out-of-range item id is clamped to 0, not rejected', '0', $row['fk_i_item_id']);

/* ----------------------------------------------------------------------------
 * findByItem() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('ItemModerationLog::findByItem — empty table for this item');

$freshTable = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};
$freshTable();

pin('no rows at all returns an empty array, not false', array(), $model->findByItem($itemOne));

harness_section('ItemModerationLog::findByItem — single match');

seed_exec(
    $admin,
    "INSERT INTO $table (fk_i_item_id, s_source, s_reason, s_field, s_action, dt_date) VALUES (?, ?, ?, ?, ?, ?)",
    'isssss',
    array($itemOne, 'keyword', 'viagra', 'title', 'spam', '2026-01-01 10:00:00')
);

$rows = $model->findByItem($itemOne);
check('a match returns an array', is_array($rows), describe($rows));
pin('exactly one row comes back', 1, count($rows));
pin(
    'the row carries exactly the seven schema columns',
    array('pk_i_id', 'fk_i_item_id', 's_source', 's_reason', 's_field', 's_action', 'dt_date'),
    array_keys($rows[0])
);
pin('fk_i_item_id round-trips as a string, not an int (C4)', (string) $itemOne, $rows[0]['fk_i_item_id']);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

harness_section('ItemModerationLog::findByItem — newest first');

seed_exec(
    $admin,
    "INSERT INTO $table (fk_i_item_id, s_source, s_reason, s_field, s_action, dt_date) VALUES (?, ?, ?, ?, ?, ?)",
    'isssss',
    array($itemOne, 'report_threshold', '5 reports', '', 'disable', '2026-01-02 10:00:00')
);
seed_exec(
    $admin,
    "INSERT INTO $table (fk_i_item_id, s_source, s_reason, s_field, s_action, dt_date) VALUES (?, ?, ?, ?, ?, ?)",
    'isssss',
    array($itemOne, 'keyword', 'cialis', 'description', 'spam', '2026-01-01 12:00:00')
);

$rows = $model->findByItem($itemOne);
pin('all three events for this item come back', 3, count($rows));
$reasons = array_column($rows, 's_reason');
pin(
    'ordered newest dt_date first (01-02, then 01-01 12:00, then 01-01 10:00), not insertion order',
    array('5 reports', 'cialis', 'viagra'),
    $reasons
);

harness_section('ItemModerationLog::findByItem — filters by item');

seed_exec(
    $admin,
    "INSERT INTO $table (fk_i_item_id, s_source, s_reason, s_field, s_action, dt_date) VALUES (?, ?, ?, ?, ?, ?)",
    'isssss',
    array($itemTwo, 'keyword', 'other item', '', 'spam', '2026-01-05 10:00:00')
);

pin('the other item still has exactly one event', 1, count($model->findByItem($itemTwo)));
pin('the first item is unaffected by the other item\'s event', 3, count($model->findByItem($itemOne)));

harness_section('ItemModerationLog::findByItem — no match');

pin('an unused item id returns an empty array', array(), $model->findByItem(999999));

harness_section('ItemModerationLog::findByItem — malformed lookup (null item id)');

/* (int)null casts to 0 before it ever reaches the WHERE clause, so this never
 * exercises a genuine SQL NULL — the null-where correction does not apply.
 * Pinned anyway so a future change to the cast is caught. */
pin('a null item id returns an empty array (no item 0 was seeded)', array(), $model->findByItem(null));

/* ----------------------------------------------------------------------------
 * latestForItem() — rides on findByItem()'s ledger, no query of its own.
 * ------------------------------------------------------------------------- */
harness_section('ItemModerationLog::latestForItem');

pin(
    'the newest event for an item with history (dt_date 01-02, same row findByItem ranks first)',
    '5 reports',
    $model->latestForItem($itemOne)['s_reason'] ?? null
);
pin('an item with no events returns null', null, $model->latestForItem($itemTwo + 100000));
pin('a null item id returns null', null, $model->latestForItem(null));

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('ItemModerationLog: query cost');

pin('one findByItem() call costs one query', 1, harness_query_count(static function () use ($model, $itemOne) {
    $model->findByItem($itemOne);
}));
pin('one add() call costs one query', 1, harness_query_count(static function () use ($model, $itemOne) {
    $model->add($itemOne, 'keyword', 'x');
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemmoderationlog.php */
