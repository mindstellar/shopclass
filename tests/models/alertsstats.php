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
 * Pins for the AlertsStats model: one counter per day in the `alerts_sent` group of
 * t_key_value, raised in one statement.
 *
 * Usage:  php tests/models/alertsstats.php          (standalone, own scratch database)
 *         php tests/run-models.php alertsstats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_alertsstats');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'alerts_sent'");

$model = AlertsStats::getInstance();
$table = DB_TABLE_PREFIX . 't_key_value';

/** Read a counter back with raw mysqli, never through the code under test. */
$counterFor = static function (string $date) use ($admin, $table): ?string {
    $stmt = $admin->prepare("SELECT s_value FROM $table WHERE s_group = 'alerts_sent' AND s_key = ?");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : (string)$row['s_value'];
};

$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table WHERE s_group = 'alerts_sent'")->fetch_assoc()['c'];
};

harness_section('AlertsStats: public surface');

pin('increase signature is unchanged', 'public increase($date)', harness_method_signature('AlertsStats', 'increase'));
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('AlertsStats', 'newInstance')
);

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

harness_section('AlertsStats: query cost');

$freshCost = harness_query_count(static function () use ($model) {
    $model->increase('2026-04-01');
});
check('a first-call write costs no more than one statement (' . $freshCost . ')', $freshCost <= 1);

$repeatCost = harness_query_count(static function () use ($model) {
    $model->increase('2026-04-01');
});
check('an increment costs one statement too (' . $repeatCost . ')', $repeatCost <= 1);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/alertsstats.php */
