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
 * Sign out of all devices: the i_auth_stamp column on users and admins, read from the row the
 * identity code loads anyway. A copied web cookie, an admin session and an admin remember-me
 * cookie keep working after a normal sign-out elsewhere, and all stop once the stamp goes up.
 * A stamp of 0 signs as before, so cookies from before the column existed still work. Logout
 * drops the session cookie, and the account and admin buttons need the CSRF token and the
 * password.
 *
 * Usage:  php tests/models/auth-stamp.php        (standalone, own scratch database)
 *         php tests/run-models.php auth-stamp    (as part of the suite)
 */

// This file loads the user helpers and starts sessions, so under the runner it runs in a
// process of its own.
if (defined('MODELS_RUNNER')) {
    $asOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $asOut, $asCode);
    $asOut = implode("\n", $asOut);
    echo $asOut, "\n";
    $asFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $asOut, $asM) === 1;
    $asFail  = $asFound ? (int)$asM[2] : 0;
    if (!$asFound || ($asCode !== 0 && $asFail === 0)) {
        $asFail = max(1, $asFail);
    }
    $GLOBALS['okCount']   += $asFound ? (int)$asM[1] : 0;
    $GLOBALS['failCount'] += $asFail;
    if ($asFail > 0) {
        $GLOBALS['failLabels'][] = 'auth-stamp: ' . $asFail . ' failed (exit ' . $asCode . ')';
    }

    return;
}

// Held until the end, so the sessions this test starts are not refused for output already sent.
ob_start();

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_auth_stamp');

foreach (array(
    'OSC_CACHE_TTL'   => 60,
    'WEB_PATH'        => 'http://localhost/',
    'REL_WEB_URL'     => '/',
    'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'        => false,
    'OSC_DEBUG'       => false,
    'OSC_CSRF_SECRET' => 'auth-stamp-test-secret',
    'BCRYPT_COST'     => 4,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once __DIR__ . '/../lib/action-standins.php';

use mindstellar\auth\AuthStamp;
use mindstellar\auth\SignOut;
use mindstellar\security\AdminTwoFactor;
use mindstellar\security\RememberMe;

$p   = DB_TABLE_PREFIX;
$uma = seed_user($admin, 'uma', 'uma@example.test');
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string(password_hash('pass', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST))) . "' WHERE pk_i_id = $uma");
$admin->query("INSERT INTO {$p}t_admin (pk_i_id, s_name, s_username, s_password, s_email) VALUES (9, 'Boss', 'boss', '"
    . $admin->real_escape_string(password_hash('pass', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST))) . "', 'boss@example.test')");
Preference::getInstance()->set('enabled_users', '1');
scratchdb_forget_cache();

$userRow  = static fn (): array => $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $uma")->fetch_assoc();
$adminRow = static fn (): array => $admin->query("SELECT * FROM {$p}t_admin WHERE pk_i_id = 9")->fetch_assoc();
// A new request: no identity resolved yet, no cached admin row.
$request = static function (): void {
    View::getInstance()->_erase('_loggedUser');
    $session = Session::getInstance();
    foreach (array('userId', 'adminId', 'adminStamp') as $key) {
        $session->_dropEphemeral($key);
        $session->_drop($key);
    }
    $cookie = Cookie::getInstance();
    foreach (array('oc_userId', 'oc_userSecret', 'oc_adminId', 'oc_adminSecret') as $name) {
        $cookie->pop($name);
    }
    $cache = new ReflectionProperty(Admin::class, 'cachedAdmin');
    $cache->setAccessible(true);
    $cache->setValue(Admin::getInstance(), array());
};
$webUser = static function (string $id, string $secret) use ($request): ?int {
    $request();
    Cookie::getInstance()->push('oc_userId', $id);
    Cookie::getInstance()->push('oc_userSecret', $secret);
    $user = osc_resolve_web_user();

    return isset($user['pk_i_id']) ? (int) $user['pk_i_id'] : null;
};

harness_section('the column');
pin('users and admins start at stamp 0', array('0', '0'), array($userRow()['i_auth_stamp'] ?? null, $adminRow()['i_auth_stamp'] ?? null));
check('the Admin model reads it', array_key_exists('i_auth_stamp', (array) Admin::getInstance()->findByPrimaryKey(9)));
$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0062_auth_stamp.php';
$admin->query("ALTER TABLE {$p}t_admin DROP COLUMN i_auth_stamp");
$beforeUpgrade = new Admin();
pin('before the upgrade adds it, an admin row still loads, without it', array(false, 'boss'), array(
    in_array('i_auth_stamp', $beforeUpgrade->getFields(), true), $beforeUpgrade->findByPrimaryKey(9)['s_username'] ?? null,
));
$migration->up(\mindstellar\database\Connection::getInstance());
$migration->up(\mindstellar\database\Connection::getInstance());
pin('the migration adds it back and can run twice', '0', $adminRow()['i_auth_stamp'] ?? null);

harness_section('a web sign-in cookie');
$row    = $userRow();
$legacy = (string) (time() + 3600);
$legacy .= '.' . hash_hmac('sha256', implode('|', array('1', 'web', (string) $uma, $legacy, $row['s_password'])), \mindstellar\security\SigningKey::get());
pin('a cookie signed before the stamp existed still works', $uma, $webUser((string) $uma, $legacy));
$copied = RememberMe::issue('web', $uma, $row['s_password'], 3600, AuthStamp::of($row));
pin('a copied cookie works', $uma, $webUser((string) $uma, $copied));
$mine = RememberMe::issue('web', $uma, $row['s_password'], 3600, AuthStamp::of($row));
$webUser((string) $uma, $mine);
(new class () extends WebSecBaseModel {
    public function __construct()
    {
    }
})->logout();
pin('a normal sign-out in one browser leaves the copy working', $uma, $webUser((string) $uma, $copied));
scratchdb_forget_cache();
$queries = harness_query_count(static fn () => $webUser((string) $uma, $copied));
pin('reading the stamp costs no query: the user row and its descriptions, as before', 2, $queries);
$request();
Session::getInstance()->_setEphemeral('userId', (string) $uma);
pin('a session-only sign-in from before the cookie still works while the stamp is 0', $uma, (int) (osc_resolve_web_user()['pk_i_id'] ?? 0));

$webUser((string) $uma, $copied);
check('fixture: the row is cached', osc_resolve_web_user() !== null);
pin('signing out of all devices raises the stamp', array(true, 1), array(SignOut::everywhereUser($uma), AuthStamp::of($userRow())));
pin('the copied cookie is dead at once, cached row or not', null, $webUser((string) $uma, $copied));
pin('so is the legacy cookie', null, $webUser((string) $uma, $legacy));
$request();
Session::getInstance()->_setEphemeral('userId', (string) $uma);
pin('and the session-only sign-in', null, osc_resolve_web_user());
pin('a new sign-in works', $uma, $webUser((string) $uma, RememberMe::issue('web', $uma, $userRow()['s_password'], 3600, AuthStamp::of($userRow()))));
pin('an unknown user is not bumped', false, SignOut::everywhereUser(999999));
pin('only SignOut raises a stamp: AuthStamp has no public way to', [[], false], [
    array_values(array_filter(get_class_methods(AuthStamp::class), static fn (string $m): bool => stripos($m, 'bump') !== false)),
    (new ReflectionMethod(SignOut::class, 'bump'))->isPublic(),
]);

harness_section('an admin session and remember-me cookie');
$session = Session::getInstance();
$signIn  = static function () use ($request, $session, $adminRow): void {
    $request();
    $session->_set('adminId', '9');
    $session->_set('adminStamp', AuthStamp::of($adminRow()));
};
$signIn();
pin('a signed-in admin session counts', true, osc_is_admin_user_logged_in());
$remember = RememberMe::issue('admin', 9, AdminTwoFactor::rememberBinding($adminRow()), 3600, AuthStamp::of($adminRow()));
$byCookie = static function () use ($request, $remember): bool {
    $request();
    Cookie::getInstance()->push('oc_adminId', '9');
    Cookie::getInstance()->push('oc_adminSecret', $remember);

    return osc_is_admin_user_logged_in();
};
pin('so does a copied remember-me cookie', true, $byCookie());
pin('which records the stamp in the session it starts', 0, (int) $session->_get('adminStamp'));
$queries = harness_query_count(static function () use ($request, $session): void {
    $request();
    $session->_set('adminId', '9');
    osc_is_admin_user_logged_in();
});
pin('checking the admin session costs one query, as before', 1, $queries);
$request();
$session->_set('adminId', '9');
$session->_drop('adminStamp');
pin('a session from before the stamp existed still counts while it is 0', true, osc_is_admin_user_logged_in());

pin('signing the admin out of all devices raises the stamp', array(true, 1), array(SignOut::everywhereAdmin(9), AuthStamp::of($adminRow())));
$request();
$session->_set('adminId', '9');
$session->_set('adminStamp', 0);
pin('an older admin session no longer counts', false, osc_is_admin_user_logged_in());
pin('nor does the copied remember-me cookie', false, $byCookie());
$signIn();
pin('a new sign-in counts', true, osc_is_admin_user_logged_in());

harness_section('logout ends the session');
$_COOKIE['osclass'] = session_id();
$session->_set('flash', 'x');
(new class () extends AdminSecBaseModel {
    public function __construct()
    {
    }
})->logout();
pin('admin logout destroys the session and drops its cookie for the rest of the request', array(PHP_SESSION_NONE, false, ''), array(
    session_status(), isset($_COOKIE['osclass']), $session->_get('adminId'),
));
$session->_set('later', 1);
check('a later write starts a new session', session_status() === PHP_SESSION_ACTIVE && $session->_get('later') === 1);
$session->session_end();
$sessionSource = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/Session.php');
check('session_end() expires the cookie in the browser', str_contains($sessionSource, "setcookie('osclass', '', array(") && str_contains($sessionSource, "'expires'  => time() - 3600,"));
$main = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebMain.php');
$web  = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/base/WebSecBaseModel.php');
check('the web logout link and the account logout both end it', str_contains($main, 'session_end();') && str_contains($web, 'session_end();'));

harness_section('the buttons');
$case  = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebUser.php', 'signOutAllPost');
check('the account button checks the CSRF token, then the password', (int) strpos($case, 'osc_csrf_check();') < (int) strpos($case, 'Reauth::verify(') && str_contains($case, 'Reauth::verify('));
check('and signs out through SignOut, this browser too', str_contains($case, 'SignOut::everywhereUser($userId);') && str_contains($case, '$this->logout();'));
$view = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/gui/account/user-signin-content.php');
$form = substr($view, (int) strpos($view, 'id="sign-out-all"'), 1500);
check('the account form asks for the password and is not exempt from the CSRF token', str_contains($form, 'name="password"') && !str_contains($form, 'nocsrf'));
$admins = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAdmins.php');
$case   = substr($admins, (int) strpos($admins, "case ('sign_out_all'):"), 400);
$method = substr($admins, (int) strpos($admins, 'private function signOutEverywhere()'), 900);
check('the admin button checks the CSRF token, then the password and code', str_contains($case, 'osc_csrf_check();') && str_contains($method, 'AdminReauth::verify('));
$users = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminUsers.php', 'signOutAll');
check('the Users screen action checks the CSRF token', str_contains($users, 'osc_csrf_check();'));

$result = harness_result();
ob_end_flush();
exit($result);
