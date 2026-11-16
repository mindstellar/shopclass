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
 * the two-step code waits in a signed cookie there, so a password alone starts no session. The
 * login offers the admin default language, and no language cookie is kept.
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
$servers = [];
register_shutdown_function(static function () use (&$servers, $dir): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
});
$root = rtrim(ABS_PATH, '/');
// Language uploads land in a copy of the translations folder, not in the checkout.
$languages = $dir . 'languages/';
@mkdir($languages . 'en_US', 0700, true);
copy(ABS_PATH . 'oc-content/languages/index.php', $languages . 'index.php');
foreach (glob(ABS_PATH . 'oc-content/languages/en_US/*') ?: [] as $file) {
    copy($file, $languages . 'en_US/' . basename($file));
}
/** Serve the site on a free port under $prefix ('' or e.g. '/sub'); returns its base URL, or '' when it did not start. */
$serve = static function (string $prefix, array $env = [], string $defines = '') use (&$servers, $dir, $root, $p): string {
    $port  = 0;
    $probe = @stream_socket_server('tcp://127.0.0.1:0');
    if ($probe !== false) {
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
    }
    $router = $dir . 'router' . md5($prefix . $defines) . '.php';
    file_put_contents($router, '<?php
define("TRANSLATIONS_PATH", ' . var_export($dir . 'languages/', true) . ');
' . $defines . '
$prefix = ' . var_export($prefix, true) . ';
$path = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($prefix !== "" && strpos($path, $prefix . "/") === 0) {
    $path = substr($path, strlen($prefix));
}
if ($path === "/oc-admin/" || $path === "/oc-admin/index.php") {
    $_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = $prefix . "/oc-admin/index.php";
    $_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/oc-admin/index.php', true) . ';
    chdir(' . var_export($root . '/oc-admin', true) . ');
    require ' . var_export($root . '/oc-admin/index.php', true) . ';
    return true;
}
if ($prefix === "" && $path !== "/" && is_file(' . var_export($root, true) . ' . $path)) {
    return false;
}
$_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = $prefix . "/index.php";
$_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/index.php', true) . ';
chdir(' . var_export($root, true) . ');
require ' . var_export($root . '/index.php', true) . ';
');
    $base      = 'http://127.0.0.1:' . $port;
    $servers[] = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-d', 'opcache.jit=disable', '-d', 'sendmail_path=/bin/true', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
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
            'WEB_PATH'               => $base . $prefix . '/',
            'REL_WEB_URL'            => $prefix . '/',
        ] + $env
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
$base = $serve('');
if ($base === '') {
    echo "SKIP  the local server did not start\n";
    harness_section('skipped');
    check('skipped: the local server did not start', true);
    exit(harness_result());
}

/**
 * One request: [status, Location, Set-Cookie lines keyed by name, body].
 *
 * @param array<string,string> $cookies sent as the Cookie header
 * @param array<string,string> $post    form fields; a GET when empty
 */
$call = static function (string $path, array $cookies = [], array $post = [], array $headers = []) use ($base): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $base . $path);
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ] + ($post === [] ? [] : [CURLOPT_POSTFIELDS => array_filter($post, static fn ($v) => $v instanceof CURLFile) !== [] ? $post : http_build_query($post)]));
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

harness_section('a forged or stale code-step cookie');

$clearTries();
[, , $set] = $login('twofa', 'secret-pass');
$fresh     = $valueOf($set, $twoCookie);
$forged    = substr($fresh, 0, -1) . (substr($fresh, -1) === 'A' ? 'B' : 'A');
$r         = $post2fa($forged, $codes[3]);
check('a cookie with a wrong signature signs no one in', $refused($r), $r[0] . ' ' . $r[1]);
pin('...and the backup code is not used up', 7, count(AdminTwoFactor::settings($twoRow)['backup']));

[, , $set] = $login('plain', 'secret-pass', [$twoCookie => $fresh]);
pin('a sign-in without two-step clears a stale code-step cookie', ['/oc-admin/'], $paths($set, $twoCookie));
check('...as a deletion', str_contains(implode(' ', $set[$twoCookie] ?? []), 'Max-Age=0'));
[, , $set] = $call('/oc-admin/index.php?page=main&action=logout', ['osclass' => $valueOf($set, 'osclass'), $twoCookie => $fresh]);
check('logging out clears it too', str_contains(implode(' ', $set[$twoCookie] ?? []), 'Max-Age=0'), implode(' | ', $set[$twoCookie] ?? []));

$db->query("UPDATE {$p}t_admin SET s_password = '" . password_hash('new-pass', PASSWORD_BCRYPT, ['cost' => 12]) . "' WHERE pk_i_id = $twoId");
$r = $post2fa($fresh, $codes[3]);
check('a password changed after the password step ends the code step', $refused($r), $r[0] . ' ' . $r[1]);
$db->query("UPDATE {$p}t_admin SET s_password = '$livePass' WHERE pk_i_id = $twoId");

harness_section('the login offers the admin default language');

seed_locale($db, 'es_ES', 'Espanol');
seed_locale($db, 'fr_FR', 'Francais');
$db->query("UPDATE {$p}t_locale SET b_enabled_bo = 0 WHERE pk_c_code = 'fr_FR'");
$oldLocaleCookie = 'oc_admin_locale';
/** The language the login form has selected, or '' when none is. */
$offered = static function (array $cookies = []) use ($call): string {
    [, , , $body] = $call('/oc-admin/index.php?page=login', $cookies);

    return preg_match('/<option value="([A-Za-z_]+)"\s+selected/', $body, $m) === 1 ? $m[1] : '';
};
pin('the admin default setting is offered', 'en_US', $offered());
pin('...even when an old language cookie names another', 'en_US', $offered([$oldLocaleCookie => 'es_ES']));
$db->query("UPDATE {$p}t_preference SET s_value = 'es_ES' WHERE s_section = 'osclass' AND s_name = 'admin_language'");
pin('a changed admin default is offered', 'es_ES', $offered());
$db->query("UPDATE {$p}t_preference SET s_value = 'fr_FR' WHERE s_section = 'osclass' AND s_name = 'admin_language'");
pin('an admin default that is off for oc-admin gives way to the first language on', 'en_US', $offered());
$db->query("UPDATE {$p}t_preference SET s_value = 'en_US' WHERE s_section = 'osclass' AND s_name = 'admin_language'");

[, , $set] = $call('/oc-admin/index.php?page=login', [$oldLocaleCookie => 'es_ES']);
$line      = $set[$oldLocaleCookie][0] ?? '';
check('the login page expires an old language cookie on the admin path', $paths($set, $oldLocaleCookie) === ['/oc-admin/'] && str_contains($line, 'Max-Age=0'), $line);
[, , $set] = $call('/oc-admin/index.php?page=login');
pin('...and sends nothing for a browser without one', [], $set[$oldLocaleCookie] ?? []);

harness_section('the language chosen at login lasts for that session only');

$clearTries();
$signIn = static function (array $extra) use ($call, $csrf): array {
    return $call('/oc-admin/index.php', [], ['page' => 'login', 'action' => 'login_post', 'user' => 'plain', 'password' => 'secret-pass'] + $extra + $csrf);
};
[$code, , $set] = $signIn(['locale' => 'es_ES', 'remember' => '1']);
check('signing in with a language works', $code === 302);
pin('...and sets no language cookie', [], $set[$oldLocaleCookie] ?? []);
$session      = $valueOf($set, 'osclass');
[, , , $body] = $call('/oc-admin/index.php?page=items', ['osclass' => $session]);
check('...the admin screens use that language', str_contains($body, '<html lang="es"'), substr($body, 0, 300));
$bag       = [md5($base . '/') => (string) strtok(substr((string) ($set[md5($base . '/')][0] ?? ''), 33), ';')];
[, , $set] = $call('/oc-admin/index.php?page=main&action=logout', ['osclass' => $session] + $bag);
pin('logging out sets no language cookie', [], $set[$oldLocaleCookie] ?? []);
pin('...and starts no new session', '', $valueOf($set, 'osclass'));
pin('the login then offers the admin default again', 'en_US', $offered());

harness_section('a bulk language action with nothing ticked');

$clearTries();
[, , $set]    = $signIn(['locale' => 'en_US']);
$session      = $valueOf($set, 'osclass');
[, , , $body] = $call('/oc-admin/index.php?page=languages', ['osclass' => $session]);
$token        = preg_match('/CSRFName=([^&"\']+)&(?:amp;)?CSRFToken=([^&"\']+)/', $body, $m) === 1 ? '&CSRFName=' . $m[1] . '&CSRFToken=' . $m[2] : '';
check('the languages screen carries a CSRF token', $token !== '');
foreach (['enable_selected', 'disable_selected', 'enable_bo_selected', 'disable_bo_selected', 'delete'] as $action) {
    [$code, , $set] = $call('/oc-admin/index.php?page=languages&action=' . $action . html_entity_decode($token), ['osclass' => $session]);
    $flash          = [];
    foreach ($set as $name => $lines) {
        if ($valueOf($set, (string) $name) !== '') {
            $flash[(string) $name] = rawurlencode($valueOf($set, (string) $name));
        }
    }
    [, , , $body] = $call('/oc-admin/index.php?page=languages', ['osclass' => $session] + $flash);
    check($action . ' says no language was selected', $code === 302 && str_contains($body, 'No language selected.') && !str_contains($body, 'correct format'), (string) $code);
}

harness_section('uploading and editing a language');

/** The languages screen after a redirect, with the flash cookies the redirect set. */
$afterRedirect = static function (array $set, string $session) use ($call, $valueOf): string {
    $flash = [];
    foreach ($set as $name => $lines) {
        if ($valueOf($set, (string) $name) !== '') {
            $flash[(string) $name] = rawurlencode($valueOf($set, (string) $name));
        }
    }
    [, , , $body] = $call('/oc-admin/index.php?page=languages', ['osclass' => $session] + $flash);

    return $body;
};
[, , , $body] = $call('/oc-admin/index.php?page=languages&action=add', ['osclass' => $session]);
$formCsrf     = [];
foreach (['CSRFName', 'CSRFToken'] as $field) {
    if (preg_match('/name=[\'"]' . $field . '[\'"] value=[\'"]([^\'"]*)/', $body, $m) === 1) {
        $formCsrf[$field] = html_entity_decode($m[1]);
    }
}
check('the upload form carries a CSRF token', count($formCsrf) === 2);
$zipPath = $dir . 'en_AU.zip';
$zip     = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$usInfo  = (array) json_decode((string) file_get_contents(ABS_PATH . 'oc-content/languages/en_US/locale.json'), true);
$zip->addFromString('en_AU/locale.json', (string) json_encode(['locale_code' => 'en_AU', 'name' => 'English (Australia)', 'short_name' => 'English'] + $usInfo));
$zip->addFile(ABS_PATH . 'oc-content/languages/en_US/core.po', 'en_AU/core.po');
$zip->close();
$upload = static function () use ($call, $session, $formCsrf, $zipPath): array {
    return $call('/oc-admin/index.php', ['osclass' => $session], ['page' => 'languages', 'action' => 'add_post', 'package' => new CURLFile($zipPath, 'application/zip', 'en_AU.zip')] + $formCsrf);
};
[$code, , $set] = $upload();
$body           = $afterRedirect($set, $session);
check('a new language says it was installed', $code === 302 && str_contains($body, 'The language has been installed correctly') && !str_contains($body, 'already installed'), (string) $code);
[$code, , $set] = $upload();
$body           = $afterRedirect($set, $session);
check('the same language again says it was already installed and is updated', $code === 302 && str_contains($body, 'This language was already installed. It has been updated.'), (string) $code);

[$code, , $set] = $call('/oc-admin/index.php', ['osclass' => $session], [
    'page' => 'languages', 'action' => 'edit_post', 'pk_c_code' => 'en_AU', 's_name' => 'English (Australia)',
    's_short_name' => 'English', 's_description' => 'Australian English', 's_direction' => 'ltr', 's_currency_format' => '{CURRENCY} {NUMBER}',
    's_dec_point' => '.', 'i_num_dec' => '2', 's_thousands_sep' => ',', 's_date_format' => 'd/m/Y', 's_stop_words' => '', 'b_enabled' => '1', 'b_enabled_bo' => '1',
] + $formCsrf);
$body = $afterRedirect($set, $session);
check('the edit flash names the full language', $code === 302 && str_contains($body, 'English (Australia) has been updated'), (string) $code);

harness_section('a site in a subfolder');

$sub = $serve('/sub');
check('the subfolder server started', $sub !== '');
[$code, , $set] = $call($sub . '/sub/oc-admin/index.php?page=items');
pin('the redirect cookie is on the subfolder admin path', [302, ['/sub/oc-admin/']], [$code, $paths($set, 'oc_admin_login_redirect')]);
[, , , $body] = $call($sub . '/sub/oc-admin/index.php?page=login');
$subCsrf      = [];
foreach (['CSRFName', 'CSRFToken'] as $field) {
    if (preg_match('/name=[\'"]' . $field . '[\'"] value=[\'"]([^\'"]*)/', $body, $m) === 1) {
        $subCsrf[$field] = html_entity_decode($m[1]);
    }
}
$clearTries();
[$code, $location, $set] = $call($sub . '/sub/oc-admin/index.php', [], ['page' => 'login', 'action' => 'login_post', 'user' => 'twofa', 'password' => 'secret-pass', 'locale' => 'en_US'] + $subCsrf);
check('the password step asks for the code', $code === 302 && str_contains($location, 'action=2fa'), $code . ' ' . $location);
pin('...with the code-step cookie on the subfolder admin path', ['/sub/oc-admin/'], $paths($set, $twoCookie));

harness_section('debug mode adds nothing after a JSON answer');

$debug = $serve('', ['OSC_DEBUG' => '1', 'http_proxy' => 'http://127.0.0.1:1', 'https_proxy' => 'http://127.0.0.1:1'], 'define("OSC_DEBUG_DB", true);');
check('the debug server started', $debug !== '');
$clearTries();
[, , , $body] = $call($debug . '/oc-admin/index.php?page=login');
$debugCsrf    = [];
foreach (['CSRFName', 'CSRFToken'] as $field) {
    if (preg_match('/name=[\'"]' . $field . '[\'"] value=[\'"]([^\'"]*)/', $body, $m) === 1) {
        $debugCsrf[$field] = html_entity_decode($m[1]);
    }
}
check('...and the login page has no timing comment', !str_contains($body, 'seg. -->'));
[, , $set] = $call($debug . '/oc-admin/index.php', [], ['page' => 'login', 'action' => 'login_post', 'user' => 'plain', 'password' => 'secret-pass', 'locale' => 'en_US'] + $debugCsrf);
$debugSession = $valueOf($set, 'osclass');
check('an admin signs in on it', $debugSession !== '');
[$code, , , $body] = $call($debug . '/oc-admin/index.php?page=languages&action=published', ['osclass' => $debugSession]);
check('the language list answers plain JSON, without the query panel', is_array(json_decode($body, true)), $code . ' ' . substr($body, 0, 200));
[, , , $body] = $call($debug . '/oc-admin/index.php?page=languages', ['osclass' => $debugSession]);
check('an admin page still shows the query panel', str_contains($body, 'osc-qdbg'));

$log = (string) @file_get_contents($dir . 'server.log');
check('the server logged no PHP error', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log) !== 1, $log);

exit(harness_result());
