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
 * Every core class with getInstance() and the deprecated newInstance() or instance() answers
 * the same shared object through all of them, so plugins on the old names keep working.
 *
 * Usage:  php tests/models/shared-instance.php        (standalone, own scratch database)
 *         php tests/run-models.php shared-instance    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_shared_instance');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

harness_section('deprecated accessors answer the shared instance');

$classes = array();
$sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-includes/osclass/classes', FilesystemIterator::SKIP_DOTS));
foreach ($sources as $file) {
    $src = (string) file_get_contents($file->getPathname());
    if (strpos($src, 'function getInstance(') === false
        || !preg_match('/function (newInstance|instance)\(/', $src)
        || !preg_match('/^(?:final |abstract )?class (\w+)/m', $src, $m)
    ) {
        continue;
    }
    $ns        = preg_match('/^namespace ([^;]+);/m', $src, $n) ? $n[1] . '\\' : '';
    $classes[] = $ns . $m[1];
}
sort($classes);
check('found the shared classes', count($classes) >= 60);

$skipped = array();
foreach ($classes as $class) {
    $ref = new ReflectionClass($class);
    if ($ref->isAbstract() || !$ref->getMethod('getInstance')->isPublic()) {
        continue;
    }
    try {
        $shared = $class::getInstance();
    } catch (\Throwable $e) {
        // Needs a web request or files this test does not set up; listed below.
        $skipped[] = $class;
        continue;
    }
    foreach (array('newInstance', 'instance') as $old) {
        if (method_exists($class, $old) && $ref->getMethod($old)->getNumberOfRequiredParameters() === 0) {
            check($class . '::' . $old . '() is the shared instance', $class::$old() === $shared);
        }
    }
}
// These need a theme, a locale or a live connection set up by the request.
pin('only the known classes cannot start here', array('AdminThemes', 'Translation', 'WebThemes'), $skipped);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
