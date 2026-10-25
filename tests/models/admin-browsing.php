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
 * The signed admin-browsing cookie: set at admin sign-in, read by the site's own API calls as
 * osc_admin_is_browsing(), and never an admin sign-in. Checked in process and over real HTTP.
 * Usage: php tests/models/admin-browsing.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\AdminBrowsing;
use mindstellar\auth\AdminStore;
use mindstellar\database\Connection;

$db = api_admin_boot('osc_models_admin_browsing');
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
    ['api', 'api_enabled', '1'],
] as [$section, $name, $value]) {
    $db->query("UPDATE {$p}t_preference SET s_value = '$value' WHERE s_section = '$section' AND s_name = '$name'");
    if ($db->affected_rows === 0) {
        seed_exec($db, "INSERT INTO {$p}t_preference VALUES (?, ?, ?, 'STRING')", 'sss', [$section, $name, $value]);
    }
}
$ledger = \mindstellar\migration\MigrationRunner::forCore();
$ledger->ensureLedger();
$ledger->baseline();
$adminId = api_admin_seed_admin($db, 'plain');
$otherId = api_admin_seed_admin($db, 'other');
$db->query("UPDATE {$p}t_admin SET s_password = '" . password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]) . "'");
check('two admins', AdminStore::find($otherId) !== null);

harness_section('the cookie checks itself');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$session = 'abcdefghijklmnopqrstuvwxyz';
$verify  = static function (string $token, ?int $now = null) use ($session): bool {
    $_COOKIE = $token === '' ? ['osclass' => $session] : ['osclass' => $session, AdminBrowsing::COOKIE => $token];
    AdminBrowsing::reset();

    return AdminBrowsing::verified($now);
};
$_COOKIE = ['osclass' => $session];
$row     = AdminStore::find($adminId);
$good    = AdminBrowsing::token($row);
pin('a fresh cookie verifies', true, $verify($good));
pin('no cookie, no admin', false, $verify(''));
[$payload, $sig] = explode('.', $good);
$data            = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
$data['a']       = $otherId;
$tampered        = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=') . '.' . $sig;
pin('a cookie edited to name another admin is refused', false, $verify($tampered));
pin('a cookie with a broken signature is refused', false, $verify(substr($good, 0, -2) . 'xx'));
pin('an expired cookie is refused', false, $verify($good, time() + AdminBrowsing::TTL + 5));
pin('a cookie for another purpose is refused', false, $verify(\mindstellar\security\SignedPayload::pack('signed-redirect', ['a' => $adminId], 300)));
$_COOKIE = ['osclass' => 'zyxwvutsrqponmlkjihgfedcba', AdminBrowsing::COOKIE => $good];
AdminBrowsing::reset();
pin('a cookie with another session is refused', false, AdminBrowsing::verified());
$db->query("UPDATE {$p}t_admin SET i_auth_stamp = i_auth_stamp + 1 WHERE pk_i_id = $adminId");
pin('a cookie from before a sign-out everywhere is refused', false, $verify($good));
$db->query("UPDATE {$p}t_admin SET i_auth_stamp = i_auth_stamp - 1 WHERE pk_i_id = $adminId");
$db->query("DELETE FROM {$p}t_admin WHERE pk_i_id = $otherId");
pin('a cookie for a deleted admin is refused', false, $verify(AdminBrowsing::token(['pk_i_id' => $otherId])));

harness_section('in an API request it is a preview signal, not a sign-in');

Params::setParam('page', 'api');
$_COOKIE  = ['osclass' => $session, AdminBrowsing::COOKIE => $good];
$_SESSION = ['adminId' => $adminId, 'adminStamp' => 0];
pin('the browser carries an admin session', $adminId, (int) Session::getInstance()->_get('adminId'));
AdminBrowsing::reset();
\mindstellar\api\identity\WebIdentity::forget();
pin('osc_admin_is_browsing() reads the cookie', true, osc_admin_is_browsing());
pin('osc_is_admin_user_logged_in() stays false', false, (bool) osc_is_admin_user_logged_in());
$_COOKIE = ['osclass' => $session];
AdminBrowsing::reset();
pin('without the cookie no admin is browsing', false, osc_admin_is_browsing());
Params::setParam('page', '');
$_COOKIE = ['osclass' => $session, AdminBrowsing::COOKIE => $good];
AdminBrowsing::reset();
pin('on a page request the cookie alone is not enough', false, osc_admin_is_browsing());

harness_section('over HTTP');

$dir = sys_get_temp_dir() . '/osc-admin-browsing-' . getmypid() . '/';
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
$port  = 0;
$probe = @stream_socket_server('tcp://127.0.0.1:0');
if ($probe !== false) {
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
}
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
    $up   = $sock !== false && fclose($sock);
}
if (!$up) {
    echo "SKIP  the local server did not start\n";
    check('skipped: the local server did not start', true);
    exit(harness_result());
}

/**
 * One request: [status, Set-Cookie lines keyed by name, lower-cased headers].
 *
 * @param array<string,string> $cookies
 * @param array<string,string> $post
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
    $set  = [];
    $head = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $value = trim(substr($line, 11));
            $set[(string) strtok($value, '=')][] = $value;
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $head[strtolower(trim($k))] = trim($v);
        }
    }

    return [$code, $set, $head, substr($raw, $size)];
};
$valueOf = static function (array $set, string $name): string {
    foreach ($set[$name] ?? [] as $line) {
        $value = urldecode((string) strtok(substr($line, strlen($name) + 1), ';'));
        if ($value !== '' && $value !== 'deleted') {
            return $value;
        }
    }

    return '';
};
$cleared = static fn (array $set, string $name): bool => str_contains(implode(' ', $set[$name] ?? []), 'Max-Age=0');
$name    = AdminBrowsing::COOKIE;

[$code, $set] = $call('/');
check('an anonymous page gets no admin-browsing cookie', !isset($set[$name]), (string) $code);

[, , , $body] = $call('/oc-admin/index.php?page=login');
$csrf         = [];
foreach (['CSRFName', 'CSRFToken'] as $field) {
    if (preg_match('/name=[\'"]' . $field . '[\'"] value=[\'"]([^\'"]*)/', $body, $m) === 1) {
        $csrf[$field] = html_entity_decode($m[1]);
    }
}
[$code, $set] = $call('/oc-admin/index.php', [], ['page' => 'login', 'action' => 'login_post', 'user' => 'plain', 'password' => 'secret-pass', 'locale' => 'en_US'] + $csrf);
$sid    = $valueOf($set, 'osclass');
$signal = $valueOf($set, $name);
$line   = $set[$name][0] ?? '';
check('signing in sets the cookie', $code === 302 && $sid !== '' && $signal !== '', $code . ' ' . $line);
check('...HttpOnly, SameSite=Lax, on the site path, for an hour', stripos($line, 'HttpOnly') !== false && stripos($line, 'SameSite=Lax') !== false && stripos($line, 'path=/;') !== false && preg_match('/Max-Age=(359[89]|3600)\b/', $line) === 1, $line);
$admin = ['osclass' => $sid, $name => $signal];

harness_section('maintenance lets the admin\'s API calls through');

// Files newer than the database lock visitors out without a .maintenance file in the checkout.
$db->query("UPDATE {$p}t_preference SET s_value = '1.0.0' WHERE s_section = 'osclass' AND s_name = 'version'");
$db->query("DELETE FROM {$p}t_migration ORDER BY s_migration DESC LIMIT 1");
[$code] = $call('/index.php?page=api&path=v1/');
pin('a visitor\'s API call gets 503', 503, $code);
[$code, , $head] = $call('/index.php?page=api&path=v1/', $admin);
// Past the gate the API answers as usual: 401 here, as the call carries no page token.
check('the admin\'s API call passes the gate', $code !== 503 && $code > 0, (string) $code);
[$code] = $call('/index.php?page=api&path=v1/', ['osclass' => $sid, $name => substr($signal, 0, -2) . 'xx']);
pin('a forged cookie gets 503', 503, $code);
[$code] = $call('/index.php?page=api&path=v1/', ['osclass' => $sid]);
pin('the admin session alone does not pass an API call', 503, $code);
$ledger->baseline();
$db->query("UPDATE {$p}t_preference SET s_value = '" . OSCLASS_VERSION . "' WHERE s_section = 'osclass' AND s_name = 'version'");
$bearer = ['Authorization: Bearer ' . (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('public', 'browsing test', ['listings:read'], \mindstellar\apikey\KeyOwner::admin($adminId))->token()];
[$code, , $head] = $call('/index.php?page=api&path=v1/categories', [], [], $bearer);
check('a visitor\'s public read may be shared-cached', $code === 200 && str_contains($head['cache-control'] ?? '', 'public'), $code . ' ' . ($head['cache-control'] ?? ''));
[$code, , $head] = $call('/index.php?page=api&path=v1/categories', ['osclass' => $sid, $name => $signal], [], $bearer);
check('...but not one from a browser carrying the admin cookie', $code === 200 && str_contains($head['cache-control'] ?? '', 'private'), $code . ' ' . ($head['cache-control'] ?? ''));

harness_section('renewed and cleared with the admin session');

[$code, $set] = $call('/', $admin);
check('a page with a fresh cookie does not rewrite it', $code === 200 && !isset($set[$name]), $code . ' ' . implode(' | ', $set[$name] ?? []));
[$code, $set] = $call('/', ['osclass' => $sid]);
check('a page with the admin session and no cookie sets it again', $valueOf($set, $name) !== '', $code . ' ' . implode(' | ', $set[$name] ?? []));
[, $set] = $call('/', ['osclass' => 'nosuchsessionnosuchsession', $name => $signal]);
check('a page with no admin session drops the cookie', $cleared($set, $name), implode(' | ', $set[$name] ?? []));
[, $set] = $call('/oc-admin/index.php?page=main&action=logout', $admin);
check('signing out drops it', $cleared($set, $name), implode(' | ', $set[$name] ?? []));

$log = (string) @file_get_contents($dir . 'server.log');
check('the server logged no PHP error', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log) !== 1, $log);

exit(harness_result());
