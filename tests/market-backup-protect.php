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
 * The folder the market installer saves a plugin backup in is closed to the web.
 *
 * No database.  Usage:  php tests/market-backup-protect.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$base = sys_get_temp_dir() . '/osc_market_backup_' . getmypid() . '/';
@mkdir($base . 'plugins/demo', 0777, true);
register_shutdown_function(static function () use ($base) {
    exec('rm -rf ' . escapeshellarg($base));
});
file_put_contents($base . 'plugins/demo/index.php', "<?php\n/*\nPlugin Name: Demo\nVersion: 1.0\n*/\n");

define('ABS_PATH', dirname(__DIR__) . '/');
define('CONTENT_PATH', $base);
define('PLUGINS_PATH', $base . 'plugins/');
define('THEMES_PATH', $base . 'themes/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

harness_section('Backing up a plugin before an update');

$installer = \mindstellar\market\Installer::forPlugins();
$backup    = new ReflectionMethod($installer, 'backupExisting');
$backup->setAccessible(true);
$zip = $backup->invoke($installer, 'demo', $base . 'plugins/demo');

check('the backup is written', is_string($zip) && is_file($zip));
check('the folder has an .htaccess', is_file($base . 'downloads/backups/.htaccess'));
check('...and an index.php', is_file($base . 'downloads/backups/index.php'));

exit(harness_result());
