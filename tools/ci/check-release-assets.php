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
 * Checks the release files the market's package CI downloads, before the release uploads them:
 * the deprecated-API inventory, the package-ci bundle built from tools/ci/package-ci-files.txt,
 * and the install script.
 */

const USAGE = "Usage: php tools/ci/check-release-assets.php <version> <deprecated-api.json> <bundle.tar.gz> <install.sh>\n";

if ($argc !== 5) {
    fwrite(STDERR, USAGE);
    exit(2);
}
[, $version, $jsonPath, $bundlePath, $installPath] = $argv;

$errors = array();

$data = json_decode((string) @file_get_contents($jsonPath), true);
if (!is_array($data)) {
    $errors[] = 'deprecated-api.json is not JSON';
} else {
    if (($data['core_version'] ?? null) !== $version) {
        $errors[] = 'deprecated-api.json names core ' . json_encode($data['core_version'] ?? null) . ', not ' . $version;
    }
    // Core keeps over a hundred deprecated functions; far fewer means the scan broke.
    $functions = is_array($data['functions'] ?? null) ? $data['functions'] : array();
    if (count($functions) < 50) {
        $errors[] = 'deprecated-api.json lists only ' . count($functions) . ' functions';
    }
    foreach (array('hooks', 'filters', 'files', 'classes') as $list) {
        if (!is_array($data[$list] ?? null)) {
            $errors[] = 'deprecated-api.json has no ' . $list . ' list';
        }
    }
}

$wanted = array();
foreach (file(__DIR__ . '/package-ci-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] !== '#') {
        $wanted[] = 'package-ci/' . preg_split('/\s+/', trim($line))[1];
    }
}
$tmp = sys_get_temp_dir() . '/release-assets-' . getmypid();
if (!mkdir($tmp) || !is_file($bundlePath)) {
    $errors[] = 'the package-ci bundle could not be opened';
} else {
    exec('tar xzf ' . escapeshellarg($bundlePath) . ' -C ' . escapeshellarg($tmp) . ' 2>/dev/null');
    foreach ($wanted as $file) {
        $path = $tmp . '/' . $file;
        if (!is_file($path)) {
            $errors[] = 'the package-ci bundle misses ' . $file;
            continue;
        }
        $lint = str_ends_with($file, '.php') ? escapeshellarg(PHP_BINARY) . ' -l' : (str_ends_with($file, '.sh') ? 'bash -n' : null);
        if ($lint !== null) {
            exec($lint . ' ' . escapeshellarg($path) . ' > /dev/null 2>&1', $unused, $code);
            if ($code !== 0) {
                $errors[] = $file . ' does not parse';
            }
        }
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}

$install = (string) @file_get_contents($installPath);
if (!str_contains($install, 'RELEASE_VERSION="' . $version . '"')) {
    $errors[] = 'install.sh does not carry version ' . $version;
}
$sum = trim((string) @file_get_contents($installPath . '.sha256'));
if ($sum === '' || strtok($sum, ' ') !== hash_file('sha256', $installPath)) {
    $errors[] = 'install.sh.sha256 does not match install.sh';
}

foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
echo $errors === array() ? "Release files look right.\n" : '';
exit($errors === array() ? 0 : 1);
