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
 * A site address taken from the request's Host header is flagged, and the backup bucket
 * refuses it: a visitor must not pick the bucket folder a backup job works in. An address
 * set in the environment is trusted; one given to the command line only by OSC_CLI_URL is not. The config loader runs under PHP's built-in server,
 * since its request fallback never runs on the command line.
 *
 * No database.  Usage:  php tests/web-path-from-request.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/harness.php';

$repo = dirname(__DIR__);
$site = sys_get_temp_dir() . '/osc_web_path_' . getmypid();
@mkdir($site, 0700, true);
register_shutdown_function(static function () use ($site) {
    exec('rm -rf ' . escapeshellarg($site));
});
file_put_contents($site . '/router.php', '<?php
define("ABS_PATH", ' . var_export($site . '/', true) . ');
require ' . var_export($repo . '/oc-includes/osclass/config-loader.php', true) . ';
require ' . var_export($repo . '/oc-includes/vendor/autoload.php', true) . ';
header("Content-Type: application/json");
echo json_encode(array(
    "web_path" => defined("WEB_PATH") ? WEB_PATH : null,
    "flag"     => defined("OSC_WEB_PATH_FROM_REQUEST") && OSC_WEB_PATH_FROM_REQUEST,
    "trusted"  => defined("OSC_TRUSTED_WEB_PATH") ? OSC_TRUSTED_WEB_PATH : null,
    "known"    => \mindstellar\backup\BackupBucket::addressKnown(),
    "adapter"  => \mindstellar\backup\BackupBucket::addressKnown() ? null : \mindstellar\backup\BackupBucket::adapter() !== null,
));
');

/** Serve the router with $env and ask it once with the given Host header. */
function ask_site(string $site, array $env, string $host): ?array
{
    $port = 0;
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    if ($sock !== false) {
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
    }
    $env  = $env + array('PATH' => (string) getenv('PATH'), 'DB_NAME' => 'shop', 'OSC_IGNORE_CONFIG_FILE' => '1');
    $proc = proc_open(
        array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $site . '/router.php'),
        array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
        $pipes,
        $site,
        $env
    );
    $body = false;
    for ($i = 0; $i < 50 && $body === false; $i++) {
        usleep(100000);
        $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, stream_context_create(array(
            'http' => array('header' => 'Host: ' . $host . "\r\n", 'timeout' => 2),
        )));
    }
    proc_terminate($proc);
    proc_close($proc);

    return is_string($body) ? json_decode($body, true) : null;
}

harness_section('An address from the Host header');

$got = ask_site($site, array(), 'evil.example');
pin('the site still boots on it', 'http://evil.example/', $got['web_path'] ?? null);
pin('...and it is flagged as taken from the request', true, $got['flag'] ?? null);
pin('the bucket does not trust it', false, $got['known'] ?? null);
pin('...so no bucket adapter is handed out', false, $got['adapter'] ?? null);

harness_section('OSC_CLI_URL set, no WEB_PATH');

$got = ask_site($site, array('OSC_CLI_URL' => 'https://shop.example.com/'), 'evil.example');
pin('pages keep the request address', 'http://evil.example/', $got['web_path'] ?? null);
pin('...and e-mail links get the trusted one', 'https://shop.example.com/', $got['trusted'] ?? null);

harness_section('OSC_ALLOWED_HOSTS');

$got = ask_site($site, array('OSC_ALLOWED_HOSTS' => 'shop.example.com'), 'evil.example');
pin('another host is refused', null, $got);
$got = ask_site($site, array('OSC_ALLOWED_HOSTS' => 'shop.example.com'), 'Shop.Example.com:8080');
pin('a listed host is served, whatever its case or port', 'http://Shop.Example.com:8080/', $got['web_path'] ?? null);

harness_section('An address set in the environment');

$got = ask_site($site, array('WEB_PATH' => 'https://shop.example.com/'), 'evil.example');
pin('the environment wins over the Host header', 'https://shop.example.com/', $got['web_path'] ?? null);
pin('...and it is not flagged', false, $got['flag'] ?? null);
pin('the bucket trusts it', true, $got['known'] ?? null);

harness_section('An address given to the command line only');

$script = $site . '/cli.php';
file_put_contents($script, str_replace('header("Content-Type: application/json");', '', (string) file_get_contents($site . '/router.php')));
$env = array('PATH' => (string) getenv('PATH'), 'DB_NAME' => 'shop', 'OSC_IGNORE_CONFIG_FILE' => '1', 'OSC_CLI_URL' => 'https://shop.example.com/');
$proc = proc_open(array(PHP_BINARY, $script), array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $site, $env);
$got  = json_decode((string) stream_get_contents($pipes[1]), true);
proc_close($proc);
pin('OSC_CLI_URL gives the command line its address', 'https://shop.example.com/', $got['web_path'] ?? null);
pin('...but the bucket does not use it, as the web side never sees it', false, $got['known'] ?? null);

exit(harness_result());
