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
 * Pins StrictModeReadiness::zeroDates(), the check `oc-cli.php doctor` runs before an owner
 * turns strict SQL mode on: it counts zero dates per column in this site's tables only.
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 * Usage:  php tests/db-strict-readiness.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\StrictModeReadiness;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$admin  = scratchdb_session('osc_strict_readiness');
$prefix = DB_TABLE_PREFIX;

harness_section('zeroDates');

pin('the bundled schema and seed hold no zero dates', array(), StrictModeReadiness::zeroDates($prefix));

pin('... and no zero default', array(), StrictModeReadiness::zeroDefaults($prefix));

$admin->query("SET SESSION sql_mode = ''");
$admin->query("CREATE TABLE {$prefix}zz_probe (id INT PRIMARY KEY, d DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',"
    . ' e DATE NULL, t TIMESTAMP NULL)');
$admin->query("INSERT INTO {$prefix}zz_probe VALUES (1, '0000-00-00 00:00:00', NULL, NULL),"
    . " (2, '2026-01-01 00:00:00', '0000-00-00', NULL), (3, '0000-00-00 00:00:00', '2026-05-00', NULL),"
    . " (4, '2026-00-10 00:00:00', '2026-01-01', NULL)");
$admin->query("CREATE VIEW {$prefix}zz_view AS SELECT d FROM {$prefix}zz_probe");
$admin->query('CREATE TABLE other_zz_probe (d DATETIME NOT NULL)');
$admin->query("INSERT INTO other_zz_probe VALUES ('0000-00-00 00:00:00')");

$expected = array($prefix . 'zz_probe.d' => 3, $prefix . 'zz_probe.e' => 2);
pin("zero and partly zero dates are counted per column, in this site's base tables only",
    $expected, StrictModeReadiness::zeroDates($prefix));
pin('a zero default is reported', array($prefix . 'zz_probe.d'), StrictModeReadiness::zeroDefaults($prefix));

$mode = $admin->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
osc_db_execute("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
pin('the prefix still matches with NO_BACKSLASH_ESCAPES on', $expected, StrictModeReadiness::zeroDates($prefix));
osc_db_execute("SET SESSION sql_mode = '" . $mode . "'");

$admin->query("DROP VIEW {$prefix}zz_view");
$admin->query("DROP TABLE {$prefix}zz_probe");
$admin->query('DROP TABLE other_zz_probe');

exit(harness_result());
