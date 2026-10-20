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
 * Core never names a mindstellar\api class: the REST API builds on core, not the other way
 * round. Core reaches the API only through mindstellar\apikey\ApiAccess, which the API's
 * boot.php connects.
 *
 * DB-free.  Usage: php tests/core-no-api-import.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

/** A reference to the API namespace, as code (one backslash) or in a string (two). */
const API_NAME = '/mindstellar\\\\{1,2}api\b/';

/**
 * Lines outside the API that name it, as "path:line: text".
 *
 * @return string[]
 */
function core_api_references(): array
{
    $files = glob(ABS_PATH . '*.php') ?: array();
    foreach (array('oc-includes', 'oc-admin') as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . $root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() === 'php'
                && !str_contains($path, '/oc-includes/vendor/')
                && !str_contains($path, '/oc-includes/osclass/classes/api/')
            ) {
                $files[] = $path;
            }
        }
    }

    $found = array();
    foreach ($files as $path) {
        $src = (string) file_get_contents($path);
        foreach (explode("\n", $src) as $i => $line) {
            if (preg_match(API_NAME, $line) === 1) {
                $found[] = substr($path, strlen(ABS_PATH)) . ':' . ($i + 1) . ': ' . trim($line);
            }
        }
    }
    $GLOBALS['scanned'] = count($files);

    return $found;
}

harness_section('The pattern');

check('catches a use line', preg_match(API_NAME, 'use mindstellar\api\Kernel;') === 1);
check('catches a fully qualified call', preg_match(API_NAME, '\mindstellar\api\ApiServices::site()') === 1);
check('catches a class name in a string', preg_match(API_NAME, "'mindstellar\\\\api\\\\Kernel'") === 1);
check('lets the core apikey module through', preg_match(API_NAME, 'use mindstellar\apikey\ApiAccess;') === 0);

harness_section('Core files');

$refs = core_api_references();
check('the scan reads core', $GLOBALS['scanned'] > 500, $GLOBALS['scanned'] . ' files');
pin('no file outside classes/api names mindstellar\\api', array(), $refs);

exit(harness_result());
