<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * FileSystem::denyLogs() closes *.log files to the web on Apache 2.4 and 2.2 and keeps other rules.
 *
 * No database.  Usage:  php tests/filesystem-deny-logs.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\FileSystem;

$dir = sys_get_temp_dir() . '/osc_deny_logs_' . getmypid();
mkdir($dir);
register_shutdown_function(static function () use ($dir) {
    exec('rm -rf ' . escapeshellarg($dir));
});

FileSystem::denyLogs($dir);
$made = (string) file_get_contents($dir . '/.htaccess');
check('a missing .htaccess is created', $made !== '');
check('it denies *.log on Apache 2.4', strpos($made, 'Require all denied') !== false && strpos($made, '\.log$') !== false);
check('...and on Apache 2.2', strpos($made, 'Deny from all') !== false);

FileSystem::denyLogs($dir);
check('a second run adds nothing', $made === (string) file_get_contents($dir . '/.htaccess'));

file_put_contents($dir . '/.htaccess', "Options -Indexes");
FileSystem::denyLogs($dir);
$kept = (string) file_get_contents($dir . '/.htaccess');
check('an existing file keeps its own rules', strpos($kept, "Options -Indexes\n<FilesMatch") === 0);

exit(harness_result());
