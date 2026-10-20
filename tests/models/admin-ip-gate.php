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
 * The admin address allow list over real HTTP: a `php -S` server runs an installed scratch site
 * with OSC_ADMIN_IP_ALLOW set in the environment. An address outside the list gets a plain 404
 * from /oc-admin/ with no cookie and no-store; listed and loopback addresses get the panel; cron,
 * the installer and the command line are never refused. The test router sets REMOTE_ADDR from an
 * X-Test-Remote header, as a proxy's real_ip would.
 * Usage: php tests/models/admin-ip-gate.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\database\Connection;

$db = api_admin_boot('osc_models_admin_ip_gate');
$p  = DB_TABLE_PREFIX;

harness_section('an installed site');
seed_locale($db, 'en_US', 'English (US)');
Connection::getInstance()->executeScript(str_replace(
    ['/*TABLE_PREFIX*/', '/*OSCLASS_VERSION*/'],
    [$p, OSCLASS_VERSION],
    file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql') . file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/pages.sql')
));
foreach ([
    ['osclass', 'osclass_installed', '1'],
    ['osclass', 'csrf_secret', OSC_CSRF_SECRET],
] as [$section, $name, $value]) {
    $db->query("UPDATE {$p}t_preference SET s_value = '$value' WHERE s_section = '$section' AND s_name = '$name'");
    if ($db->affected_rows === 0) {
        seed_exec($db, "INSERT INTO {$p}t_preference VALUES (?, ?, ?, 'STRING')", 'sss', [$section, $name, $value]);
    }
}
$ledger = \mindstellar\migration\MigrationRunner::forCore();
$ledger->ensureLedger();
$ledger->baseline();
check('an admin', api_admin_seed_admin($db, 'plain') > 0);

$dir = sys_get_temp_dir() . '/osc-admin-ip-gate-' . getmypid() . '/';
@mkdir($dir, 0700, true);
$servers = [];
register_shutdown_function(static function () use (&$servers, $dir): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    array_map('unlink', glob($dir . '*') ?: []);
    @rmdir($dir);
});
$root = rtrim(ABS_PATH, '/');
$env  = [
    'PATH'                   => (string) getenv('PATH'),
    'OSC_IGNORE_CONFIG_FILE' => '1',
    'DB_HOST'                => DB_HOST,
    'DB_PORT'                => (string) ini_get('mysqli.default_port'),
    'DB_NAME'                => DB_NAME,
    'DB_USER'                => DB_USER,
    'DB_PASSWORD'            => DB_PASSWORD,
    'DB_TABLE_PREFIX'        => $p,
];
file_put_contents($dir . 'router.php', '<?php
if (isset($_SERVER["HTTP_X_TEST_REMOTE"])) {
    $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_X_TEST_REMOTE"];
}
$path = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$entries = ["/oc-admin/" => "/oc-admin/index.php", "/oc-admin/index.php" => "/oc-admin/index.php", "/oc-includes/osclass/install.php" => "/oc-includes/osclass/install.php"];
$entry = $entries[$path] ?? "/index.php";
$_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = $entry;
$_SERVER["SCRIPT_FILENAME"] = ' . var_export($root, true) . ' . $entry;
chdir(dirname($_SERVER["SCRIPT_FILENAME"]));
require $_SERVER["SCRIPT_FILENAME"];
');
/** Serve the site with $allow as OSC_ADMIN_IP_ALLOW (none when null); its base URL, or '' when it did not start. */
$serve = static function (?string $allow) use (&$servers, $dir, $root, $env): string {
    $port  = 0;
    $probe = @stream_socket_server('tcp://127.0.0.1:0');
    if ($probe !== false) {
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
    }
    $base      = 'http://127.0.0.1:' . $port;
    $servers[] = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-d', 'opcache.jit=disable', '-d', 'sendmail_path=/bin/true', '-S', '127.0.0.1:' . $port, '-t', $root, $dir . 'router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . 'server.log', 'a'], 2 => ['file', $dir . 'server.log', 'a']],
        $pipes,
        $root,
        $env + ['WEB_PATH' => $base . '/', 'REL_WEB_URL' => '/'] + ($allow === null ? [] : ['OSC_ADMIN_IP_ALLOW' => $allow])
    );
    for ($i = 0; $i < 100 && $port > 0; $i++) {
        usleep(30000);
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($sock !== false) {
            fclose($sock);

            return $base;
        }
    }

    return '';
};
$locked = $serve('203.0.113.0/24, 2001:db8::/32');
$open   = $serve(null);
if ($locked === '' || $open === '') {
    echo "SKIP  the local server did not start\n";
    check('skipped: the local server did not start', true);
    exit(harness_result());
}

/**
 * One GET from $ip: [status, header lines, body]. Sends a flash and a session cookie, so a
 * request that reached the session would answer with a Set-Cookie.
 *
 * @return array{0:int,1:array<int,string>,2:string}
 */
$call = static function (string $url, string $ip): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ['X-Test-Remote: ' . $ip, 'Cookie: oc_flash=stale; osclass=0123456789abcdef0123456789'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$code, explode("\r\n", substr($raw, 0, $size)), substr($raw, $size)];
};
$header = static function (array $lines, string $name): array {
    return array_values(array_filter($lines, static fn ($l) => stripos($l, $name . ':') === 0));
};
$reached = static fn (int $code): bool => $code === 200 || $code === 302;

harness_section('an address outside the list');

[$code, $lines, $body] = $call($locked . '/oc-admin/index.php?page=login', '198.51.100.7');
pin('gets a 404', 404, $code);
pin('...with no cookie', [], $header($lines, 'Set-Cookie'));
check('...not stored by a cache', in_array('Cache-Control: no-store', $header($lines, 'Cache-Control'), true), implode(' | ', $header($lines, 'Cache-Control')));
pin('...with a plain "Not Found" body', 'Not Found', $body);
pin('...as plain text', ['Content-Type: text/plain; charset=utf-8'], $header($lines, 'Content-Type'));
check('...naming neither Shopclass nor the admin panel', stripos($body, 'shopclass') === false && stripos($body, 'oc-admin') === false && stripos($body, 'admin') === false);
[$code, $lines, $body] = $call($locked . '/oc-admin/', '198.51.100.7');
pin('the bare /oc-admin/ is refused too', [404, [], 'Not Found'], [$code, $header($lines, 'Set-Cookie'), $body]);
[$code] = $call($locked . '/oc-admin/index.php?page=ajax&action=runhook', '2001:db9::1');
pin('...and admin AJAX from an IPv6 address outside it', 404, $code);
[$code] = $call($locked . '/oc-admin/index.php?page=upgrade', '198.51.100.7');
pin('...and the upgrade screen', 404, $code);

harness_section('addresses in the list');

[$code, , $body] = $call($locked . '/oc-admin/index.php?page=login', '203.0.113.9');
check('a listed IPv4 address gets the login form', $code === 200 && str_contains($body, 'CSRFToken'), (string) $code);
[$code] = $call($locked . '/oc-admin/index.php?page=login', '2001:db8::77');
pin('...and a listed IPv6 one', 200, $code);
[$code] = $call($locked . '/oc-admin/index.php?page=login', '::ffff:203.0.113.9');
pin('...and a listed IPv4 one written as IPv6', 200, $code);
[$code] = $call($locked . '/oc-admin/index.php?page=items', '127.0.0.1');
check('loopback always gets in', $reached($code), (string) $code);

harness_section('what the list never touches');

[$code] = $call($locked . '/index.php?page=cron', '198.51.100.7');
check('cron on the public entry', $code !== 404, (string) $code);
[$code] = $call($locked . '/', '198.51.100.7');
pin('the public site', 200, $code);
[$code] = $call($locked . '/oc-includes/osclass/install.php', '198.51.100.7');
check('the installer', $code !== 404, (string) $code);
$out = [];
exec(
    'env -i ' . implode(' ', array_map(static fn ($k, $v) => escapeshellarg($k . '=' . $v), array_keys($env), $env))
    . ' OSC_ADMIN_IP_ALLOW=198.51.100.0/24 OSC_CLI_URL=http://127.0.0.1/ '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ABS_PATH . 'oc-cli.php') . ' version 2>&1',
    $out,
    $exit
);
check('the command line', $exit === 0 && preg_match('/^\d+\.\d+\.\d+/', trim(implode("\n", $out))) === 1, $exit . ' ' . implode(' ', $out));

harness_section('with the list off');

[$code, , $body] = $call($open . '/oc-admin/index.php?page=login', '198.51.100.7');
check('any address gets the login form', $code === 200 && str_contains($body, 'CSRFToken'), (string) $code);

exit(harness_result());

/* file end: ./tests/models/admin-ip-gate.php */
