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
 * Runs every tests/*.php in its own PHP process and fails if any fails.
 *
 * Usage:  php tests/run-unit.php [-j N] [name ...]   run all (or the named) tests, 4 at a time
 *         php tests/run-unit.php --check             fail if a test file has no docblock
 *                                                    saying what it checks (no tests run)
 * Needs the database settings of tests/lib/scratchdb.php for the DB tests.
 */

// Files in tests/ that are not tests run on their own. Every entry needs a reason.
const RUNNER_EXCLUDE = array(
    'schema-drift' => 'needs a baseline file argument; run by schema-drift.yml',
    'run-models'   => 'its own runner; run it with php tests/run-models.php',
);

$dir = __DIR__;
$all = array();
foreach (glob($dir . '/*.php') ?: array() as $f) {
    $name = basename($f, '.php');
    if ($name !== 'run-unit' && !isset(RUNNER_EXCLUDE[$name])) {
        $all[] = $name;
    }
}
sort($all);

$args  = array_slice($argv, 1);
$jobs  = 4;
$names = array();
$check = false;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--check') {
        $check = true;
    } elseif ($args[$i] === '-j' && isset($args[$i + 1])) {
        $jobs = max(1, (int) $args[++$i]);
    } else {
        $names[] = basename($args[$i], '.php');
    }
}

if ($check) {
    // CI runs every file this runner finds, so the check is that each one says what it checks.
    $missing = array();
    foreach ($all as $name) {
        $doc = false;
        foreach (token_get_all((string) file_get_contents($dir . '/' . $name . '.php')) as $token) {
            if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                $doc = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#m', '', $token[1])) !== '';
                break;
            }
            if (is_array($token) && !in_array($token[0], array(T_OPEN_TAG, T_COMMENT, T_WHITESPACE), true)) {
                break;
            }
        }
        if (!$doc) {
            $missing[] = $name;
        }
    }
    foreach ($missing as $name) {
        fwrite(STDERR, "FAIL  tests/$name.php has no docblock at the top saying what it checks\n");
    }
    echo $missing === array() ? 'OK  all ' . count($all) . " tests say what they check\n" : '';
    exit($missing === array() ? 0 : 1);
}

if ($names !== array()) {
    foreach ($names as $n) {
        if (!in_array($n, $all, true)) {
            fwrite(STDERR, "no such test: tests/$n.php\n");
            exit(2);
        }
    }
    $all = $names;
}

$start   = microtime(true);
$queue   = $all;
$running = array();
$failed  = array();
$done    = 0;
$passed  = 0;

$finish = static function (string $name, array $p, int $code) use (&$failed, &$done, &$passed): void {
    $done++;
    $out = $p['out'];
    if ($code === 0) {
        $passed++;
        $last = trim((string) strrchr(trim($out), "\n"));
        echo sprintf("ok    %-45s %s\n", $name, $last);
        return;
    }
    $failed[$name] = $out;
    echo sprintf("FAIL  %-45s exit %d\n", $name, $code);
};

while ($queue !== array() || $running !== array()) {
    while ($queue !== array() && count($running) < $jobs) {
        $name = array_shift($queue);
        $cmd  = array(PHP_BINARY, $dir . '/' . $name . '.php');
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, dirname($dir));
        stream_set_blocking($pipes[1], false);
        $running[$name] = array('proc' => $proc, 'pipe' => $pipes[1], 'out' => '', 'code' => null);
    }
    foreach ($running as $name => &$p) {
        $p['out'] .= (string) stream_get_contents($p['pipe']);
        $st = proc_get_status($p['proc']);
        if (!$st['running']) {
            if ($p['code'] === null) {
                $p['code'] = $st['exitcode'];
            }
            $p['out'] .= (string) stream_get_contents($p['pipe']);
            fclose($p['pipe']);
            proc_close($p['proc']);
            $finish($name, $p, $p['code']);
            unset($running[$name]);
        }
    }
    unset($p);
    usleep(20000);
}

foreach ($failed as $name => $out) {
    echo "\n########## $name ##########\n" . $out . "\n";
}
printf("\n%d files, %d passed, %d failed, %.1fs\n", $done, $passed, count($failed), microtime(true) - $start);
exit($failed === array() ? 0 : 1);

/* file end: ./tests/run-unit.php */
