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
 * The admin login cookies over real HTTP: a `php -S` server runs oc-admin on an installed
 * scratch site. The redirect cookie lives on the admin path, so public pages never get it.
 * Usage: php tests/models/admin-login-cookies.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\database\Connection;

$db = api_admin_boot('osc_models_admin_login_cookies');
$p  = DB_TABLE_PREFIX;

$port  = 0;
$probe = @stream_socket_server('tcp://127.0.0.1:0');
if ($probe !== false) {
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
}
if ($port === 0) {
    echo "SKIP  no free local port\n";
    harness_section('skipped');
    check('skipped: no free local port', true);
    exit(harness_result());
}

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
$plainId = api_admin_seed_admin($db, 'plain');
$db->query("UPDATE {$p}t_admin SET s_password = '" . password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]) . "'");

$dir = sys_get_temp_dir() . '/osc-admin-cookies-' . getmypid() . '/';
@mkdir($dir, 0700, true);
$server = null;
register_shutdown_function(static function () use (&$server, $dir): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    array_map('unlink', glob($dir . '*') ?: []);
    @rmdir($dir);
});
$root = rtrim(ABS_PATH, '/');
file_put_contents($dir . 'router.php', '<?php
$path = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/oc-admin/" || $path === "/oc-admin/index.php") {
    $_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = "/oc-admin/index.php";
    $_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/oc-admin/index.php', true) . ';
    chdir(' . var_export($root . '/oc-admin', true) . ');
    require ' . var_export($root . '/oc-admin/index.php', true) . ';
    return true;
}
if ($path !== "/" && is_file(' . var_export($root, true) . ' . $path)) {
    return false;
}
$_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = "/index.php";
$_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/index.php', true) . ';
chdir(' . var_export($root, true) . ');
require ' . var_export($root . '/index.php', true) . ';
');
$base   = 'http://127.0.0.1:' . $port;
$server = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-d', 'opcache.jit=disable', '-S', '127.0.0.1:' . $port, '-t', $root, $dir . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . 'server.log', 'w'], 2 => ['file', $dir . 'server.log', 'a']],
    $pipes,
    $root,
    [
        'PATH'                   => (string) getenv('PATH'),
        'OSC_IGNORE_CONFIG_FILE' => '1',
        'DB_HOST'                => DB_HOST,
        'DB_PORT'                => (string) ini_get('mysqli.default_port'),
        'DB_NAME'                => DB_NAME,
        'DB_USER'                => DB_USER,
        'DB_PASSWORD'            => DB_PASSWORD,
        'DB_TABLE_PREFIX'        => $p,
        'WEB_PATH'               => $base . '/',
        'REL_WEB_URL'            => '/',
    ]
);
$up = false;
for ($i = 0; $i < 100 && !$up; $i++) {
    usleep(30000);
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    $up   = $sock !== false && fclose($sock);
}
check('the server started', $up);

/**
 * One request: [status, Location, Set-Cookie lines keyed by name, body].
 *
 * @param array<string,string> $cookies sent as the Cookie header
 * @param array<string,string> $post    form fields; a GET when empty
 */
$call = static function (string $path, array $cookies = [], array $post = [], array $headers = []) use ($base): array {
    $ch = curl_init($base . $path);
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ] + ($post === [] ? [] : [CURLOPT_POSTFIELDS => http_build_query($post)]));
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $location = '';
    $set      = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        } elseif (stripos($line, 'Set-Cookie:') === 0) {
            $value = trim(substr($line, 11));
            $set[(string) strtok($value, '=')][] = $value;
        }
    }

    return [$code, $location, $set, substr($raw, $size)];
};
/** The path of each Set-Cookie line for a name. */
$paths = static function (array $set, string $name): array {
    return array_map(static fn ($line) => preg_match('/;\s*path=([^;]*)/i', $line, $m) === 1 ? $m[1] : '', $set[$name] ?? []);
};
/** The value of the cookie the line sets, or '' when it deletes it. */
$valueOf = static function (array $set, string $name): string {
    foreach ($set[$name] ?? [] as $line) {
        $value = urldecode((string) strtok(substr($line, strlen($name) + 1), ';'));
        if ($value !== '' && $value !== 'deleted') {
            return $value;
        }
    }

    return '';
};

harness_section('the login redirect cookie stays on the admin path');

[$code, $location, $set] = $call('/oc-admin/index.php?page=items');
check('an admin page with no session sends the admin to the login', $code === 302 && str_contains($location, 'page=login'), $code . ' ' . $location);
pin('...remembering the page on the admin path only', ['/oc-admin/'], $paths($set, 'oc_admin_login_redirect'));
$remembered = $valueOf($set, 'oc_admin_login_redirect');

[$code, , $set] = $call('/oc-admin/index.php?page=login', [], [], ['Referer: ' . $base . '/oc-admin/index.php?page=users']);
pin('the login form remembers the referer on the admin path', [200, ['/oc-admin/']], [$code, $paths($set, 'oc_admin_login_redirect')]);

[$code, , $set, $body] = $call('/oc-admin/index.php?page=login');
$csrf = [];
foreach (['CSRFName', 'CSRFToken'] as $field) {
    if (preg_match('/name=[\'"]' . $field . '[\'"] value=[\'"]([^\'"]*)/', $body, $m) === 1) {
        $csrf[$field] = html_entity_decode($m[1]);
    }
}
check('the login form carries a CSRF token', count($csrf) === 2, substr($body, 0, 400));
$login = static function (string $user, string $password, array $cookies = []) use ($call, $csrf): array {
    return $call('/oc-admin/index.php', $cookies, ['page' => 'login', 'action' => 'login_post', 'user' => $user, 'password' => $password, 'locale' => 'en_US'] + $csrf);
};

[$code, $location, $set] = $login('plain', 'secret-pass', ['oc_admin_login_redirect' => $remembered]);
check('signing in goes to the remembered page', $code === 302 && str_contains($location, 'page=items'), $code . ' ' . $location);
pin('...clearing the cookie on the admin path and the old site-path copy', ['/', '/oc-admin/'], $paths($set, 'oc_admin_login_redirect'));

$log = (string) @file_get_contents($dir . 'server.log');
check('the server logged no PHP error', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log) !== 1, $log);

exit(harness_result());
