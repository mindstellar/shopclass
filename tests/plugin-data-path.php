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
 * osc_plugin_data_path() gives each plugin one folder under oc-content/plugin-data/, closed to
 * the web, and refuses a slug that could reach outside it.
 *
 * DB-free.  Usage: php tests/plugin-data-path.php
 */

$root = sys_get_temp_dir() . '/osc-plugin-data-' . getmypid() . '/';
define('ABS_PATH', dirname(__DIR__) . '/');
define('CONTENT_PATH', $root);
require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});
mkdir($root);

$dir = osc_plugin_data_path('translator');
pin('a plugin gets its own folder', $root . 'plugin-data/translator/', $dir);
check('...which exists', is_dir($dir));
check('...closed to the web', is_file($dir . '.htaccess') && is_file($dir . 'index.php'));
check('...and so is the folder above it', is_file($root . 'plugin-data/.htaccess') && is_file($root . 'plugin-data/index.php'));
pin('a second call gives the same folder', $dir, osc_plugin_data_path('translator'));

foreach (array('', '../uploads', 'a/b', '.hidden', "x\n", str_repeat('a', 65)) as $bad) {
    pin('a bad slug is refused: ' . json_encode($bad), '', osc_plugin_data_path($bad));
}
check('...and makes nothing outside plugin-data', !is_dir($root . 'uploads'));

exit(harness_result());
