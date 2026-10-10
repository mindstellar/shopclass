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
 * Pins for the Cron model: each schedule's times in the `cron` group of t_key_value.
 * cron.php and alerts.php branch on getCronByType() returning false for a missing
 * schedule, and on claim() letting only one of two racing requests through.
 *
 * Usage:  php tests/models/cron.php          (standalone, own scratch database)
 *         php tests/run-models.php cron      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_cron');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'cron'");

$cron = Cron::getInstance();

harness_section('Cron: public surface');

pin(
    'getCronByType signature is unchanged',
    'public getCronByType($type)',
    harness_method_signature('Cron', 'getCronByType')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Cron', 'newInstance'));
pin('getInstance returns the shared instance', true, Cron::getInstance() === Cron::newInstance());

harness_section('Cron::getCronByType — empty table');

pin('no schedules at all returns bool false', false, $cron->getCronByType('HOURLY'));

harness_section('Cron::getCronByType — single match');

seed_cron($admin, 'HOURLY', '2026-01-01 00:00:00', '2026-01-01 01:00:00');
seed_cron($admin, 'DAILY', '2026-01-02 00:00:00', '2026-01-03 00:00:00');

$row = $cron->getCronByType('HOURLY');

check('a match returns an array', is_array($row), describe($row));
pin('the row carries the three old column names', array('e_type', 'd_last_exec', 'd_next_exec'), array_keys($row));
pin('e_type round-trips', 'HOURLY', $row['e_type']);
pin('d_last_exec round-trips', '2026-01-01 00:00:00', $row['d_last_exec']);
pin('d_next_exec round-trips', '2026-01-01 01:00:00', $row['d_next_exec']);
check('every value in the row is a string', all_values_string($row), describe($row));

$daily = $cron->getCronByType('DAILY');
pin('a second type is matched independently', '2026-01-02 00:00:00', $daily['d_last_exec']);

harness_section('Cron::getCronByType — no match');

pin('a type with no row returns bool false', false, $cron->getCronByType('WEEKLY'));
pin('an unknown type returns bool false', false, $cron->getCronByType('NOT_A_TYPE'));
pin('the empty string returns bool false', false, $cron->getCronByType(''));

harness_section('Cron::getCronByType — one row per type');

seed_cron($admin, 'CUSTOM', '2026-02-01 00:00:00', '2026-02-01 06:00:00');
seed_cron($admin, 'CUSTOM', '2026-03-01 00:00:00', '2026-03-01 06:00:00');
pin('a type holds one set of times, the last written', '2026-03-01 00:00:00', $cron->getCronByType('CUSTOM')['d_last_exec']);
$admin->query('INSERT INTO ' . DB_TABLE_PREFIX . "t_key_value (s_group, s_key, s_value, dt_created) VALUES ('cron', 'BROKEN', 'not json', NOW())");
pin('a stored value that is not the expected JSON reads as no schedule', false, $cron->getCronByType('BROKEN'));
pin('listAll returns every readable schedule in key order', array('CUSTOM', 'DAILY', 'HOURLY'), array_column($cron->listAll(), 'e_type'));

harness_section('Cron::getCronByType — malformed lookup');

/* cron.php and alerts.php both treat a false return as "no schedule row". */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('null returns bool false rather than raising', false, $cron->getCronByType(null));
error_reporting($prevLevel);

harness_section('Cron::claim — two requests that saw the same run due');

seed_cron($admin, 'HOURLY', '2026-03-01 09:00:00', '2026-03-01 10:00:00');
$seen = $cron->getCronByType('HOURLY');
pin('the first claim wins', true, $cron->claim('HOURLY', $seen['d_next_exec'], '2026-03-01 10:00:05', '2026-03-01 11:00:00'));
pin('the second, from the same reading, loses', false, $cron->claim('HOURLY', $seen['d_next_exec'], '2026-03-01 10:00:06', '2026-03-01 11:00:00'));
pin('and the schedule moved on once', '2026-03-01 10:00:05', $cron->getCronByType('HOURLY')['d_last_exec']);
// No lock is held: a run that crashed after its claim leaves only the moved schedule behind.
$next = $cron->getCronByType('HOURLY');
pin('so the next hour can still be claimed after a crashed run', true, $cron->claim('HOURLY', $next['d_next_exec'], '2026-03-01 11:00:03', '2026-03-01 12:00:00'));
pin('a type with no row cannot be claimed', false, $cron->claim('MISSING', '2026-03-01 11:00:00', '2026-03-01 12:00:00', '2026-03-01 13:00:00'));

harness_section('Cron::restore');

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'cron' AND s_key = 'WEEKLY'");
$cron->restore('WEEKLY');
pin('a lost schedule comes back as never run', '1000-01-01 00:00:00', $cron->getCronByType('WEEKLY')['d_next_exec'] ?? null);
$cron->restore('HOURLY');
pin('a schedule with times keeps them', '2026-03-01 11:00:03', $cron->getCronByType('HOURLY')['d_last_exec'] ?? null);

harness_section('Cron: query cost');

pin('one lookup costs one query', 1, harness_query_count(static function () use ($cron) {
    $cron->getCronByType('HOURLY');
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/cron.php */
