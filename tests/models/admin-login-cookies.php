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
 * scratch site. The redirect cookie lives on the admin path, so public pages never get it, and
 * the two-step code waits in a signed cookie there, so a password alone starts no session.
 * Usage: php tests/models/admin-login-cookies.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\AdminStore;
use mindstellar\database\Connection;
use mindstellar\security\AdminTwoFactor;
use mindstellar\security\Totp;

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
// At the site's own cost, so signing in does not rehash it and change what the code step is bound to.
$twoId    = api_admin_seed_admin($db, 'twofa');
$otherId  = api_admin_seed_admin($db, 'other');
$livePass = password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 12]);
$db->query("UPDATE {$p}t_admin SET s_password = '$livePass' WHERE pk_i_id IN ($twoId, $otherId)");
$secret = Totp::newSecret();
$codes  = AdminTwoFactor::enable($twoId, $secret, Totp::code($secret));
$otherSecret = Totp::newSecret();
AdminTwoFactor::enable($otherId, $otherSecret, Totp::code($otherSecret));
check('two admins with two-step sign-in on', is_array($codes) && AdminTwoFactor::enabled(['pk_i_id' => $otherId]));
$twoCookie  = 'oc_admin_2fa';
$clearTries = static function () use ($db, $p): void {
    $db->query("TRUNCATE TABLE {$p}t_rate_counter");
};

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
pin('...clearing the cookie on the admin path and the old site-path copy', ['/oc-admin/', '/'], $paths($set, 'oc_admin_login_redirect'));
[, , $set] = $login('plain', 'wrong-pass', ['oc_admin_login_redirect' => $remembered]);
pin('a wrong password clears only the admin-path cookie', ['/oc-admin/'], $paths($set, 'oc_admin_login_redirect'));
$clearTries();

harness_section('the password step starts no session');

$clearTries();
[$code, $location, $set] = $login('twofa', 'secret-pass');
check('a right password asks for the code', $code === 302 && str_contains($location, 'action=2fa'), $code . ' ' . $location);
pin('...with no session cookie', [], $set['osclass'] ?? []);
$pending = $valueOf($set, $twoCookie);
$line    = $set[$twoCookie][0] ?? '';
check('...but a signed code-step cookie on the admin path', $pending !== '' && $paths($set, $twoCookie) === ['/oc-admin/'], $line);
check('...HttpOnly, SameSite=Lax, for 300 s', stripos($line, 'HttpOnly') !== false && stripos($line, 'SameSite=Lax') !== false && preg_match('/Max-Age=(29[89]|300)\b/', $line) === 1, $line);
[$code, , $set, $body] = $call('/oc-admin/index.php?page=login&action=2fa', [$twoCookie => $pending]);
check('the code form opens from the cookie', $code === 200 && str_contains($body, 'two_factor_code'), (string) $code);
pin('...still with no session cookie', [], $set['osclass'] ?? []);
[$code, $location] = $call('/oc-admin/index.php?page=login&action=2fa');
check('without the cookie the code form goes back to the login', $code === 302 && !str_contains($location, 'action=2fa'), $code . ' ' . $location);

harness_section('a bad code-step cookie is refused');

$post2fa = static function (string $cookie, string $code) use ($call, $csrf, $twoCookie): array {
    return $call('/oc-admin/index.php', [$twoCookie => $cookie], ['page' => 'login', 'action' => '2fa_post', 'code' => $code] + $csrf);
};
$refused = static function (array $r): bool {
    return $r[0] === 302 && !str_contains($r[1], 'action=2fa') && ($r[2]['osclass'] ?? []) === [];
};
[$payload, $sig] = explode('.', $pending);
$data            = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
$data['id']      = $otherId;
$tampered        = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=') . '.' . $sig;
$r               = $post2fa($tampered, $codes[0]);
check('a cookie edited to name another admin signs no one in', $refused($r), $r[0] . ' ' . $r[1]);
check('...and is cleared', str_contains(implode(' ', $r[2][$twoCookie] ?? []), 'Max-Age=0'));
$twoRow   = AdminStore::find($twoId);
$otherRow = AdminStore::find($otherId);
$expired  = AdminTwoFactor::issuePending($twoRow, ['remember' => false, 'locale' => 'en_US', 'redirect' => $base . '/oc-admin/'], time() - 301);
$r        = $post2fa($expired, $codes[0]);
check('an expired cookie signs no one in', $refused($r), $r[0] . ' ' . $r[1]);
$read = AdminTwoFactor::readPending(AdminTwoFactor::issuePending($otherRow, ['locale' => 'en_US']));
pin('a token reads back as its admin and choices', [$otherId, ['locale' => 'en_US'], false], [(int) ($read['admin']['pk_i_id'] ?? 0), $read['choices'] ?? null, isset($read['admin']['s_2fa'])]);
pin('...but not one with a wrong purpose', null, AdminTwoFactor::readPending(\mindstellar\security\SignedPayload::pack('signed-redirect', ['id' => $twoId], 300)));

harness_section('wrong codes are still limited');

$clearTries();
$r = $post2fa($pending, '000000');
check('a wrong code goes back to the code form', $r[0] === 302 && str_contains($r[1], 'action=2fa') && ($r[2]['osclass'] ?? []) === [], $r[0] . ' ' . $r[1]);
for ($i = 0; $i < 9; $i++) {
    $post2fa($pending, '000000');
}
$r = $post2fa($pending, $codes[0]);
check('after 10 tries even a good backup code is refused', $r[0] === 302 && str_contains($r[1], 'action=2fa') && ($r[2]['osclass'] ?? []) === [], $r[0] . ' ' . $r[1]);
pin('...and the backup code is not used up', 8, count(AdminTwoFactor::settings($twoRow)['backup']));
$clearTries();

harness_section('a right code signs in');

$r = $post2fa($pending, Totp::code($secret, Totp::step() + 1));
check('the app code goes to the remembered page', $r[0] === 302 && !str_contains($r[1], 'page=login'), $r[0] . ' ' . $r[1]);
$session = $valueOf($r[2], 'osclass');
check('...with a new admin session', $session !== '');
check('...and clears the code-step cookie', str_contains(implode(' ', $r[2][$twoCookie] ?? []), 'Max-Age=0'));
[$code] = $call('/oc-admin/index.php?page=items', ['osclass' => $session]);
pin('the session opens the admin', 200, $code);
$r = $post2fa($pending, $codes[1]);
check('the used cookie does not sign in again, even with a good backup code', $refused($r), $r[0] . ' ' . $r[1]);

[, , $set] = $login('twofa', 'secret-pass');
$r         = $post2fa($valueOf($set, $twoCookie), $codes[2]);
check('a backup code signs in', $r[0] === 302 && !str_contains($r[1], 'page=login') && $valueOf($r[2], 'osclass') !== '', $r[0] . ' ' . $r[1]);
pin('...and is used up', 7, count(AdminTwoFactor::settings($twoRow)['backup']));

$log = (string) @file_get_contents($dir . 'server.log');
check('the server logged no PHP error', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log) !== 1, $log);

exit(harness_result());
