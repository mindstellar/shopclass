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
 * Project help links live in mindstellar\utility\ProjectLinks only. No other PHP file under
 * oc-includes/osclass, oc-includes/classes or oc-admin may type one by hand.
 *
 * DB-free.  Usage: php tests/project-links.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\ProjectLinks;

$pattern = '~https?://(?:docs\.)?mindstellar\.com|https?://github\.com/mindstellar/shopclass(?![\w-])~i';
$central = realpath(ABS_PATH . 'oc-includes/classes/core/utility/ProjectLinks.php');
$offenders = array();

foreach (array('oc-includes/osclass', 'oc-includes/classes', 'oc-admin') as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || realpath($path) === $central || str_contains($path, '/vendor/')) {
            continue;
        }
        foreach (file($path) as $n => $line) {
            if (preg_match($pattern, $line)) {
                $offenders[] = substr($path, strlen(ABS_PATH)) . ':' . ($n + 1);
            }
        }
    }
}

check('no hand-typed project link outside ProjectLinks', $offenders === array(), implode(', ', $offenders));

$reflection = new ReflectionClass(ProjectLinks::class);
$bad = array();
foreach ($reflection->getConstants() as $name => $url) {
    if (!is_string($url) || !preg_match('~^https://(mindstellar\.com/docs/|github\.com/mindstellar/shopclass)~', $url)) {
        $bad[] = $name;
    }
}
check('every ProjectLinks constant is a docs or repository https URL', $bad === array(), implode(', ', $bad));

exit(harness_result());
