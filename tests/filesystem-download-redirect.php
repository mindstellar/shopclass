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
 * FileSystem::downloadFile() does not follow a redirect to ftp:// or any other scheme
 * that is not HTTP(S). A listener stands in for the FTP server and notes any connection.
 *
 * No database.  Usage:  php tests/filesystem-download-redirect.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_base_url()
{
    return 'http://localhost/';
}

$dir = sys_get_temp_dir() . '/osc_dl_redirect_' . getmypid() . '/';
@mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir) {
    exec('rm -rf ' . escapeshellarg($dir));
});
$ftp     = stream_socket_server('tcp://127.0.0.1:0');
$ftpPort = (int) substr(strrchr(stream_socket_get_name($ftp, false), ':'), 1);
stream_set_blocking($ftp, false);
file_put_contents($dir . 'router.php', '<?php header("Location: ftp://127.0.0.1:' . $ftpPort . '/x", true, 302);');

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$proc = proc_open(
    array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . 'router.php'),
    array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
    $pipes
);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

harness_section('A download that redirects to an FTP address');

$target = $dir . 'out.bin';
try {
    $ok = (new \mindstellar\utility\FileSystem())->downloadFile('http://127.0.0.1:' . $port . '/', $target);
} catch (\Throwable $e) {
    $ok = false;
}
$contacted = @stream_socket_accept($ftp, 0.5) !== false;
proc_terminate($proc);
proc_close($proc);

check('the FTP address is never contacted', !$contacted);
check('...and the download fails', !$ok);

exit(harness_result());
