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
 * Namespaced classes live in oc-includes/classes/ and load through composer.json's PSR-4 map:
 * every mindstellar\ top namespace there has an entry, none is left in oc-includes/osclass/classes/,
 * and every class file autoloads from its own path.
 *
 * DB-free.  Usage: php tests/class-layout.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

/** @return array<string, string> file => namespace, for every PHP file under $dir that declares mindstellar\ */
function class_layout_files(string $dir): array
{
    $out = array();
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php'
            && preg_match('/^namespace\s+(mindstellar\\\\[A-Za-z0-9_\\\\]+)\s*;/m', (string) file_get_contents($file->getPathname()), $m)) {
            $out[$file->getPathname()] = $m[1];
        }
    }
    ksort($out);

    return $out;
}

$composer = json_decode((string) file_get_contents(ABS_PATH . 'composer.json'), true);
$psr4     = $composer['autoload']['psr-4'] ?? array();
$files    = class_layout_files(ABS_PATH . 'oc-includes/classes');

harness_section('the PSR-4 map');
check('the old mindstellar\\ root is gone', !isset($psr4['mindstellar\\']));
$tops = array();
foreach ($files as $ns) {
    $tops[explode('\\', $ns)[1]] = true;
}
ksort($tops);
$missing = array();
foreach (array_keys($tops) as $top) {
    if (!isset($psr4['mindstellar\\' . $top . '\\'])) {
        $missing[] = $top;
    }
}
pin('every top namespace has an entry', array(), $missing);
$noDir = array();
foreach ($psr4 as $prefix => $path) {
    if (!is_dir(ABS_PATH . $path)) {
        $noDir[] = $prefix;
    }
}
pin('every entry points at a folder', array(), $noDir);

harness_section('nothing left behind');
pin('no namespaced file in oc-includes/osclass/classes', array(), array_keys(class_layout_files(ABS_PATH . 'oc-includes/osclass/classes')));

harness_section('every class loads from its own file');
$bad = array();
foreach ($files as $file => $ns) {
    $class = $ns . '\\' . basename($file, '.php');
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
        $bad[] = $class . ' (does not load)';
        continue;
    }
    if ((new ReflectionClass($class))->getFileName() !== realpath($file)) {
        $bad[] = $class . ' (loads from another file)';
    }
}
pin('all ' . count($files) . ' classes', array(), $bad);

exit(harness_result());
