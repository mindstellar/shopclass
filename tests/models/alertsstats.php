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
 * Characterization pins for the AlertsStats model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * increase() moves to the parameterized query layer.
 *
 * increase() is the canonical duplicate-key dance: it inserts a fresh counter
 * row, and when that fails it asks the DAO layer whether the failure was errno
 * 1062 before incrementing the existing row instead. The parameterized layer
 * raises a deliberately generic exception that carries no errno, so this method
 * cannot keep that shape — it has to become a single statement whose observable
 * results are identical. These pins are what "identical" means.
 *
 * The DAO-level half of that side channel (insert() returning false on a
 * duplicate primary key, getErrorLevel() reporting 1062) is pinned separately in
 * tests/dao-contract.php and is NOT changed by this conversion — increase() is
 * the only body being rewritten.
 *
 * Usage:  php tests/models/alertsstats.php          (standalone, own scratch database)
 *         php tests/run-models.php alertsstats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_alertsstats');

$model = AlertsStats::newInstance();
$table = DB_TABLE_PREFIX . 't_alerts_sent';

/** Read a counter row back with raw mysqli, never through the code under test. */
$counterFor = static function (string $date) use ($admin, $table): ?string {
    $stmt = $admin->prepare("SELECT i_num_alerts_sent FROM $table WHERE d_date = ?");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : (string)$row['i_num_alerts_sent'];
};

$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('AlertsStats: public surface');

pin('increase signature is unchanged', 'public increase($date)', harness_method_signature('AlertsStats', 'increase'));
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('AlertsStats', 'newInstance')
);
check('AlertsStats still extends DAO', is_subclass_of('AlertsStats', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'd_date', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('d_date', 'i_num_alerts_sent'), $model->getFields());

/* ----------------------------------------------------------------------------
 * Date validation happens before any query is issued.
 * ------------------------------------------------------------------------- */
harness_section('increase — rejected dates');

pin('the empty string is rejected', false, $model->increase(''));
pin('a non-date is rejected', false, $model->increase('not-a-date'));
pin('an unpadded date is rejected', false, $model->increase('2026-1-1'));
pin('a date without separators is rejected', false, $model->increase('20260101'));
pin('a datetime is rejected', false, $model->increase('2026-01-01 00:00:00'));
pin('nothing was written by any rejected date', 0, $rowCount());
pin('a rejected date costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increase('nope');
}));

/* ----------------------------------------------------------------------------
 * The counter itself.
 * ------------------------------------------------------------------------- */
harness_section('increase — first call for a date');

pin('a fresh date returns bool true', true, $model->increase('2026-03-01'));
pin('the counter row was created at 1', '1', $counterFor('2026-03-01'));
pin('exactly one row exists', 1, $rowCount());

harness_section('increase — subsequent calls for the same date');

pin('a repeat call also returns bool true', true, $model->increase('2026-03-01'));
pin('the counter incremented to 2', '2', $counterFor('2026-03-01'));
pin('still exactly one row — it incremented, it did not duplicate', 1, $rowCount());

$model->increase('2026-03-01');
$model->increase('2026-03-01');
pin('the counter keeps incrementing', '4', $counterFor('2026-03-01'));

harness_section('increase — a second date is independent');

pin('a different date is a fresh insert again', true, $model->increase('2026-03-02'));
pin('the new counter starts at 1', '1', $counterFor('2026-03-02'));
pin('the first date is untouched', '4', $counterFor('2026-03-01'));
pin('both rows now exist', 2, $rowCount());

/* ----------------------------------------------------------------------------
 * Query cost. The legacy dance costs two statements on the increment path — the
 * insert that fails on the primary key, then the update. Collapsing that is a
 * legitimate improvement so long as the ledger above is unchanged, so this pins
 * the ceiling rather than an exact number.
 * ------------------------------------------------------------------------- */
harness_section('AlertsStats: query cost');

$freshCost = harness_query_count(static function () use ($model) {
    $model->increase('2026-04-01');
});
check('a first-call write costs no more than one statement (' . $freshCost . ')', $freshCost <= 1);

$repeatCost = harness_query_count(static function () use ($model) {
    $model->increase('2026-04-01');
});
check('an increment costs no more than the legacy two statements (' . $repeatCost . ')', $repeatCost <= 2);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/alertsstats.php */
