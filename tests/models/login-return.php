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
 * The sign-in `return` path over real HTTP: a same-site path sends the visitor back after
 * signing in or registering, with no redirect cookie; another host, `//host`, a scheme or a
 * backslash is refused. A failed sign-in keeps the path on the form.
 * Usage: php tests/models/login-return.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\database\Connection;

$db = api_admin_boot('osc_models_login_return');
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
    ['osclass', 'enabled_user_validation', '0'],
] as [$section, $name, $value]) {
    $db->query("UPDATE {$p}t_preference SET s_value = '$value' WHERE s_section = '$section' AND s_name = '$name'");
    if ($db->affected_rows === 0) {
        seed_exec($db, "INSERT INTO {$p}t_preference VALUES (?, ?, ?, 'STRING')", 'sss', [$section, $name, $value]);
    }
}
$ledger = \mindstellar\migration\MigrationRunner::forCore();
$ledger->ensureLedger();
$ledger->baseline();
$amy = seed_user($db, 'amy', 'amy@example.test');
$db->query("UPDATE {$p}t_user SET s_password = '" . password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]) . "', b_active = 1, b_enabled = 1 WHERE pk_i_id = $amy");
check('a user to sign in as', $amy > 0);

$dir = sys_get_temp_dir() . '/osc-login-return-' . getmypid() . '/';
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
$root  = rtrim(ABS_PATH, '/');
$port  = 0;
$probe = @stream_socket_server('tcp://127.0.0.1:0');
if ($probe !== false) {
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
}
file_put_contents($dir . 'router.php', '<?php
$path = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
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
    [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-d', 'opcache.jit=disable', '-d', 'sendmail_path=/bin/true', '-S', '127.0.0.1:' . $port, '-t', $root, $dir . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . 'server.log', 'a'], 2 => ['file', $dir . 'server.log', 'a']],
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
for ($i = 0; $i < 100 && $port > 0 && !$up; $i++) {
    usleep(30000);
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($sock !== false) {
        fclose($sock);
        $up = true;
    }
}
if (!$up) {
    check('skipped: the local server did not start', true);
    exit(harness_result());
}

/**
 * One request: [status, Location, Set-Cookie names, body].
 *
 * @param array<string,string> $post form fields; a GET when empty
 */
$call = static function (string $path, array $post = [], array $headers = []) use ($base): array {
    $ch = curl_init($base . $path);
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
            $set[] = (string) strtok(trim(substr($line, 11)), '=');
        }
    }

    return [$code, $location, $set, substr($raw, $size)];
};
/** The CSRF fields of a form page. */
$csrfOf = static function (string $body): array {
    $csrf = [];
    foreach (['CSRFName', 'CSRFToken'] as $field) {
        if (preg_match('/name=[\'"]' . $field . '[\'"] value=[\'"]([^\'"]*)/', $body, $m) === 1) {
            $csrf[$field] = html_entity_decode($m[1]);
        }
    }

    return $csrf;
};

harness_section('the sign-in page');
[$code, , $set, $body] = $call('/index.php?page=login', [], ['Referer: ' . $base . '/index.php?page=search']);
pin('without return it remembers the referer in a cookie, as before', [200, true], [$code, in_array('oc_login_redirect', $set, true)]);
[$code, , $set, $body] = $call('/index.php?page=login&return=' . rawurlencode('/checkout'), [], ['Referer: ' . $base . '/index.php?page=search']);
pin('with return it sets no cookie at all', [200, []], [$code, $set]);
check('the form carries return as a hidden field', str_contains($body, 'name="return" value="/checkout"'));
check('and the register link keeps it', preg_match('/page=register[^"]*return=%2Fcheckout"/', $body) === 1);
[, , , $evil] = $call('/index.php?page=login&return=' . rawurlencode('//evil.test/x'));
check('an off-site return is not put in the form', !str_contains($evil, 'name="return"'));
$csrf = $csrfOf($body);
check('the form carries a CSRF token', count($csrf) === 2);

$login = static function (string $return, string $password = 'secret-pass') use ($call, $csrf): array {
    return $call('/index.php', ['page' => 'login', 'action' => 'login_post', 'email' => 'amy@example.test', 'password' => $password, 'return' => $return] + $csrf);
};

harness_section('signing in');
[$code, $location, $set] = $login('/checkout');
pin('a same-site path is where she lands', [302, $base . '/checkout'], [$code, $location]);
check('with no redirect cookie set', !in_array('oc_login_redirect', $set, true));
[$code, $location] = $login('/checkout', 'wrong-pass');
check('a wrong password goes back to the form, keeping return', $code === 302 && str_contains($location, 'page=login') && str_ends_with($location, '&return=%2Fcheckout'), $location);
foreach (['https://evil.test/x', '//evil.test/x', '/\\evil.test', 'javascript:alert(1)'] as $bad) {
    [$code, $location] = $login($bad);
    check("$bad is refused: she lands on the dashboard", $code === 302 && !str_contains($location, 'evil') && !str_contains($location, 'javascript') && str_contains($location, 'page=user'), $location);
}

harness_section('registering');
[, , $set, $body] = $call('/index.php?page=register&action=register&return=' . rawurlencode('/checkout'));
check('the register form carries return', str_contains($body, 'name="return" value="/checkout"'));
pin('and sets no cookie for it', false, in_array('oc_login_redirect', $set, true));
[$code, $location] = $call('/index.php', [
    'page' => 'register', 'action' => 'register_post', 's_name' => 'Bo', 's_email' => 'bo@example.test',
    's_password' => 'secret-pass-2', 's_password2' => 'secret-pass-2', 'return' => '/checkout',
] + $csrfOf($body));
pin('a new account is signed in and sent back', [302, $base . '/checkout'], [$code, $location]);

exit(harness_result());
