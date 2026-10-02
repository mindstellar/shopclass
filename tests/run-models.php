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
 * Runner for the model characterization tests in tests/models/.
 *
 * Imports struct.sql ONCE into a shared scratch database, then includes every
 * tests/models/*.php in turn, truncating all tables between files so each starts
 * from a clean schema. The schema import is the expensive step, so amortising it
 * across the whole suite is what keeps a full run in the tens of seconds instead
 * of repeating a 1-2 second import per model.
 *
 * Each model test file must:
 *   - require tests/lib/scratchdb.php and tests/lib/harness.php
 *   - obtain its admin connection from scratchdb_session('osc_models_<name>')
 *   - seed its own fixtures through the raw seed helpers
 *   - guard its exit so it stays includable:
 *         if (!defined('MODELS_RUNNER')) { exit(harness_result()); }
 *
 * That guard is what lets the same file run standalone during focused work on
 * one model (`php tests/models/widget.php`, fresh database, dropped on exit) and
 * as part of the suite here.
 *
 * Usage:  php tests/run-models.php [name ...]
 *         Names filter to tests/models/<name>.php.
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root — the throwaway container)
 */

define('MODELS_RUNNER', true);
define('MODELS_RUNNER_DB', 'osc_models_suite');

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$dir = __DIR__ . '/models';

$filter = array_slice($argv, 1);
$files  = array();
if (is_dir($dir)) {
    $files = glob($dir . '/*.php') ?: array();
    sort($files);
}
if ($filter !== array()) {
    $files = array_values(array_filter($files, static function ($f) use ($filter) {
        return in_array(basename($f, '.php'), $filter, true);
    }));
    foreach ($filter as $name) {
        if (!is_file($dir . '/' . $name . '.php')) {
            fwrite(STDERR, "no such model test: tests/models/$name.php\n");
            exit(2);
        }
    }
}

$admin = scratchdb_bootstrap(MODELS_RUNNER_DB);
scratchdb_create($admin, MODELS_RUNNER_DB);

register_shutdown_function(static function () use ($admin) {
    $admin->query('DROP DATABASE IF EXISTS `' . MODELS_RUNNER_DB . '`');
});

if ($files === array()) {
    // P0 ships the harness before any model has been pinned, so an empty suite
    // is a valid green state rather than a failure.
    echo "no model tests in tests/models/ yet\n";
    echo "\n----------------------------------------\n";
    echo "RESULT: 0 passed, 0 failed (0 files)\n";
    exit(0);
}

$start   = microtime(true);
$results = array();

// A model file that calls exit() outside the MODELS_RUNNER guard ends the whole suite
// where it stands -- and PHP's exit code is the file's own, so the run looks green while
// every later file is skipped. That happened, silently, for 36 of 44 files. The runner
// now names the file it stopped in and fails.
$GLOBALS['runnerCurrent']  = null;
$GLOBALS['runnerFinished'] = false;
register_shutdown_function(static function () {
    if ($GLOBALS['runnerFinished'] || $GLOBALS['runnerCurrent'] === null) {
        return;
    }
    fwrite(STDERR, "\nFAIL  the suite stopped inside tests/models/" . $GLOBALS['runnerCurrent']
                   . ".php -- it exits outside the MODELS_RUNNER guard, so every later file was skipped\n");
    exit(1);
});

foreach ($files as $file) {
    $name = basename($file, '.php');
    $GLOBALS['runnerCurrent'] = $name;
    echo "\n########## $name ##########\n";

    $okBefore   = $GLOBALS['okCount'];
    $failBefore = $GLOBALS['failCount'];

    try {
        require $file;
    } catch (Throwable $e) {
        $GLOBALS['failCount']++;
        $GLOBALS['failLabels'][] = "$name: uncaught " . get_class($e) . ' — ' . $e->getMessage();
        echo 'FAIL  ' . $name . ': uncaught ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
    }

    $results[$name] = array(
        'ok'   => $GLOBALS['okCount'] - $okBefore,
        'fail' => $GLOBALS['failCount'] - $failBefore,
    );
}

$GLOBALS['runnerFinished'] = true;

echo "\n########## summary ##########\n";
foreach ($results as $name => $r) {
    printf("  %-28s %3d passed, %3d failed%s\n", $name, $r['ok'], $r['fail'], $r['fail'] > 0 ? '  <-- FAILED' : '');
}
printf("\n%d files in %.1fs\n", count($files), microtime(true) - $start);

exit(harness_result());

/* file end: ./tests/run-models.php */
