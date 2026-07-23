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
 * Characterization pins for the Cron model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer. Several pinned
 * behaviours are quirks rather than intentions — a malformed lookup reports
 * "no such cron" rather than raising, and the model trusts the caller to pass a
 * valid e_type. They are pinned because oc-includes/osclass/cron.php and
 * alerts.php branch on exactly those return values.
 *
 * Cron's own surface is one method; everything else it exposes comes from DAO
 * and is pinned in tests/dao-contract.php.
 *
 * Usage:  php tests/models/cron.php          (standalone, own scratch database)
 *         php tests/run-models.php cron      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_cron');

$cron = Cron::newInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Cron: public surface');

pin(
    'getCronByType signature is unchanged',
    'public getCronByType($type)',
    harness_method_signature('Cron', 'getCronByType')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Cron', 'newInstance'));
check('Cron still extends DAO', is_subclass_of('Cron', 'DAO'));
check('$cron->dao is a live DBCommandClass (C5)', $cron->dao instanceof DBCommandClass);
pin('table name is unchanged', DB_TABLE_PREFIX . 't_cron', $cron->getTableName());
pin(
    'field allowlist is unchanged',
    array('e_type', 'd_last_exec', 'd_next_exec'),
    $cron->getFields()
);
pin(
    'the model adds exactly one method of its own',
    array('__construct', 'getCronByType', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Cron')),
        array('__construct', 'newInstance', 'getCronByType')
    ))
);

/* ----------------------------------------------------------------------------
 * getCronByType — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Cron::getCronByType — empty table');

pin('no rows at all returns bool false', false, $cron->getCronByType('HOURLY'));

harness_section('Cron::getCronByType — single match');

seed_cron($admin, 'HOURLY', '2026-01-01 00:00:00', '2026-01-01 01:00:00');
seed_cron($admin, 'DAILY', '2026-01-02 00:00:00', '2026-01-03 00:00:00');

$row = $cron->getCronByType('HOURLY');

check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the three schema columns', array('e_type', 'd_last_exec', 'd_next_exec'), array_keys($row));
pin('e_type round-trips', 'HOURLY', $row['e_type']);
pin('d_last_exec round-trips', '2026-01-01 00:00:00', $row['d_last_exec']);
pin('d_next_exec round-trips', '2026-01-01 01:00:00', $row['d_next_exec']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

$daily = $cron->getCronByType('DAILY');
pin('a second type is matched independently', '2026-01-02 00:00:00', $daily['d_last_exec']);

harness_section('Cron::getCronByType — no match');

pin('an e_type with no row returns bool false', false, $cron->getCronByType('WEEKLY'));
pin('a value outside the enum returns bool false', false, $cron->getCronByType('NOT_A_TYPE'));
pin('the empty string returns bool false', false, $cron->getCronByType(''));

harness_section('Cron::getCronByType — duplicate rows');

/* t_cron declares no primary key, so the same type can appear twice. The legacy
 * body takes row() off the recordset, which is the first row in result order. */
seed_cron($admin, 'CUSTOM', '2026-02-01 00:00:00', '2026-02-01 06:00:00');
seed_cron($admin, 'CUSTOM', '2026-03-01 00:00:00', '2026-03-01 06:00:00');

$dup = $cron->getCronByType('CUSTOM');
check('a duplicated type still returns a single row, not a list', is_array($dup) && isset($dup['e_type']));
pin('the first inserted row wins', '2026-02-01 00:00:00', $dup['d_last_exec']);

harness_section('Cron::getCronByType — malformed lookup');

/* Passing null builds a comparison with no right-hand side, so the query fails
 * and the failure surfaces as "not found" rather than as an error. cron.php and
 * alerts.php both treat a false return as "no schedule row", so this quirk is
 * load-bearing and must survive the conversion. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('null returns bool false rather than raising', false, $cron->getCronByType(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * Query cost — a single lookup is one statement, and stays one.
 * ------------------------------------------------------------------------- */
harness_section('Cron: query cost');

pin('one lookup costs one query', 1, harness_query_count(static function () use ($cron) {
    $cron->getCronByType('HOURLY');
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/cron.php */
