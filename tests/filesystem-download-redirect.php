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
 * Also covers FileSystem::head() and FileSystem::writeAtomic().
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

harness_section('FileSystem::head');
pin('a HEAD answers the status without following the redirect', 302, (new \mindstellar\utility\FileSystem())->head('http://127.0.0.1:' . $port . '/'));
$pinned = 'http://pinned.invalid:' . $port . '/';
pin('a HEAD connects to the pinned IP, not the host name', 302, (new \mindstellar\utility\FileSystem())->head($pinned, 3, \mindstellar\security\AddressGuard::curlOptions($pinned, '127.0.0.1')));
proc_terminate($proc);
proc_close($proc);
pin('no server answers 0', 0, (new \mindstellar\utility\FileSystem())->head('http://127.0.0.1:' . $port . '/', 1));

check('the FTP address is never contacted', !$contacted);
check('...and the download fails', !$ok);

harness_section('FileSystem::writeAtomic');
$file = $dir . 'atomic.txt';
file_put_contents($file, 'old');
$leftovers = static fn (): array => array_values(array_diff(scandir($dir), array('.', '..', 'router.php', 'atomic.txt')));
check('a write that fails part way returns false', !\mindstellar\utility\FileSystem::writeAtomic($file, static fn ($out): bool => fwrite($out, 'partial') && false));
pin('...leaves the old content', 'old', file_get_contents($file));
pin('...and no temp file', array(), $leftovers());
check('a full write returns true', \mindstellar\utility\FileSystem::writeAtomic($file, 'new', 0600));
pin('...replaces the content, with the mode asked for', array('new', 0600), array(file_get_contents($file), fileperms($file) & 0777));
pin('...and leaves no temp file', array(), $leftovers());
$oldUmask = umask(0);
$seen     = null;
\mindstellar\utility\FileSystem::writeAtomic($file, static function ($out) use (&$seen): bool {
    $seen = fstat($out)['mode'] & 0777;

    return fwrite($out, 'secret') !== false;
}, 0600);
pin('a private write is never readable by others, even before its first byte', array(0600, 0, 0600), array($seen, umask(), fileperms($file) & 0777));
umask($oldUmask);
check('a folder that does not exist is refused', !\mindstellar\utility\FileSystem::writeAtomic($dir . 'missing/x.txt', 'x'));

exit(harness_result());
