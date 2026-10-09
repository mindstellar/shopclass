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
 * The shared account services the web forms and the API both call: UserSignIn (one sign-in
 * decision, its hooks in order, one limit), AccountService::changePassword()/setPassword() (a
 * new password ends every sign-in) and SignOut (the stamp goes up, then the action runs).
 *
 * Usage:  php tests/models/usersignin.php      (standalone, own scratch database)
 *         php tests/run-models.php usersignin  (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hSecurity.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hValidate.php';

use mindstellar\auth\AdminPassword;
use mindstellar\auth\AuthStamp;
use mindstellar\auth\SignIn;
use mindstellar\auth\SignOut;
use mindstellar\security\LoginThrottle;
use mindstellar\validation\BlockedException;
use mindstellar\validation\InvalidException;

$admin = scratchdb_session('osc_models_usersignin');
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
$p     = DB_TABLE_PREFIX;

$uma  = seed_user($admin, 'uma', 'uma@example.test');
$hash = $admin->real_escape_string(osc_hash_password('right-password'));
$admin->query("UPDATE {$p}t_user SET s_password = '$hash', b_active = 1, b_enabled = 1 WHERE pk_i_id = $uma");
$row   = static fn (): array => $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $uma")->fetch_assoc();
$reset = static function () use ($admin, $p): void {
    $admin->query("TRUNCATE TABLE {$p}t_login_attempt");
    scratchdb_forget_cache();
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.79';
Params::init();

$fired = [];
foreach (['before_login', SignOut::USER_HOOK, SignOut::ADMIN_HOOK] as $hook) {
    osc_add_hook($hook, static function (...$args) use (&$fired, $hook): void {
        $fired[] = $hook . ($args === [] ? '' : ':' . implode(',', $args));
    });
}

harness_section('UserSignIn');
$reset();
$fired = [];
$in    = SignIn::attempt('uma@example.test', 'right-password');
pin('the right password signs in, by e-mail', [SignIn::OK, $uma, ['before_login']], [$in->status(), (int) ($in->user()['pk_i_id'] ?? 0), $fired]);
pin('and by username', SignIn::OK, SignIn::attempt('uma', 'right-password')->status());
$fired = [];
$wrong = SignIn::attempt('uma@example.test', 'wrong');
pin('a wrong password is refused before any hook, with no account handed back', [SignIn::WRONG, null, []], [$wrong->status(), $wrong->user(), $fired]);
pin('an unknown account answers the same', SignIn::WRONG, SignIn::attempt('nobody@example.test', 'wrong')->status());
pin('failures are counted in the web sign-in context', 2, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_context = 'web'")->fetch_row()[0]);

$reset();
for ($i = 0; $i < 20 && SignIn::attempt('uma@example.test', 'wrong')->status() !== SignIn::BLOCKED; $i++) {
}
$fired   = [];
$blocked = SignIn::attempt('uma@example.test', 'right-password');
pin('enough failures block even the right password, before any hook', [SignIn::BLOCKED, true, []], [$blocked->status(), $blocked->retryAfter() > 0, $fired]);
pin('the web form is blocked for the account too: one budget', LoginThrottle::BLOCKED, LoginThrottle::evaluate('web', 'uma@example.test')['status']);

$reset();
for ($i = 0; $i < 20 && SignIn::attempt('uma', 'wrong')->status() !== SignIn::BLOCKED; $i++) {
}
$max = $i;
pin('failures by username lock the account out by e-mail too, answered as a wrong password', [SignIn::WRONG, SignIn::WRONG], [
    SignIn::attempt('UMA@example.test', 'right-password')->status(), SignIn::attempt('UMA@example.test', 'wrong')->status(),
]);
pin('so a locked username does not tell its e-mail from any other', [LoginThrottle::OK, SignIn::WRONG], [
    LoginThrottle::evaluate('web', 'uma@example.test')['status'], SignIn::attempt('nobody@example.test', 'wrong')->status(),
]);
pin('the name that was typed wrong answers blocked', SignIn::BLOCKED, SignIn::attempt('uma', 'right-password')->status());
$reset();
for ($i = 0; $i < $max; $i++) {
    SignIn::attempt($i % 2 === 0 ? 'uma' : 'uma@example.test', 'wrong');
}
pin('failures split over both names share one budget', [SignIn::WRONG, SignIn::WRONG], [
    SignIn::attempt('uma', 'right-password')->status(), SignIn::attempt('uma@example.test', 'right-password')->status(),
]);
pin('and a solved captcha lifts the account budget, as before', SignIn::OK, SignIn::attempt('uma', 'right-password', true)->status());
$reset();
SignIn::attempt('uma', 'wrong');
pin('a failure by username counts the address once', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_ip = '203.0.113.79'")->fetch_row()[0]);

$reset();
SignIn::attempt('someone-else', 'wrong');
SignIn::attempt('uma', 'wrong');
SignIn::attempt('uma', 'right-password');
pin('a success clears the account\'s counters, under both names, but not the address\'s', [0, 1], [
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_account IN ('uma', 'uma@example.test')")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_account = 'someone-else'")->fetch_row()[0],
]);
pin('so the address keeps the failure it made against another account', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_ip = '203.0.113.79'")->fetch_row()[0]);

$reset();
$admin->query("UPDATE {$p}t_user SET b_active = 0 WHERE pk_i_id = $uma");
scratchdb_forget_cache();
$fired = [];
pin('an unconfirmed account is refused before before_login', [SignIn::INACTIVE, []], [SignIn::attempt('uma', 'right-password')->status(), $fired]);
$reset();
for ($i = 0; $i < 20 && SignIn::attempt('uma@example.test', 'right-password')->status() !== SignIn::BLOCKED; $i++) {
}
$inactiveTries = $i;
$reset();
for ($i = 0; $i < 20 && SignIn::attempt('uma@example.test', 'wrong')->status() !== SignIn::BLOCKED; $i++) {
}
pin('the right password of an unconfirmed account locks it out like a wrong one, after as many tries', [true, $i], [$inactiveTries < 20, $inactiveTries]);
$reset();
$admin->query("UPDATE {$p}t_user SET b_active = 1, b_enabled = 0 WHERE pk_i_id = $uma");
scratchdb_forget_cache();
pin('so is a suspended one', SignIn::DISABLED, SignIn::attempt('uma', 'right-password')->status());
$admin->query("UPDATE {$p}t_user SET b_enabled = 1 WHERE pk_i_id = $uma");
scratchdb_forget_cache();

$admin->query("INSERT INTO {$p}t_ban_rule (s_name, s_ip, s_email) VALUES ('test', '', 'uma@example.test')");
scratchdb_forget_cache();
$fired  = [];
$banned = SignIn::attempt('uma', 'right-password');
pin('a banned e-mail is refused before before_login, also when signing in by username', [SignIn::BANNED, 1, []], [$banned->status(), $banned->banned() & 1, $fired]);
$admin->query("DELETE FROM {$p}t_ban_rule");
scratchdb_forget_cache();

$hooked = [];
osc_add_hook('before_validating_login', static function () use (&$hooked): void {
    $hooked[] = 'before_validating_login';
});
osc_add_hook('after_login', static function (array $user, string $redirect) use (&$hooked): void {
    $hooked[] = 'after_login ' . $user['s_username'] . ' ' . $redirect;
});
SignIn::attempt('nobody@example.test', 'wrong');
SignIn::complete(['s_username' => 'uma'], '/dashboard');
pin('attempt() fires before_validating_login once, complete() after_login with the user and where they go', ['before_validating_login', 'after_login uma /dashboard'], $hooked);

$web = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebLogin.php', 'loginPost');
$api = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/api/controller/AuthController.php');
foreach (['the web form' => $web, 'the API' => $api] as $who => $code) {
    check("$who leaves before_validating_login and after_login to SignIn, completing after the attempt", !str_contains($code, "osc_run_hook('before_validating_login'")
        && !str_contains($code, "osc_run_hook('after_login'") && (int) strpos($code, 'SignIn::attempt(') > 0 && (int) strpos($code, 'SignIn::attempt(') < (int) strpos($code, 'SignIn::complete('));
    check("$who checks no password itself", !str_contains($code, 'osc_verify_password(') && !str_contains($code, 'LoginThrottle::'));
}

harness_section('changing the password');
$reset();
try {
    (new \mindstellar\user\AccountService())->changePassword($row(), 'wrong', 'new-password', 'new-password');
    $refused = '';
} catch (InvalidException $e) {
    $refused = $e->pointer();
}
pin('a wrong current password is refused on current_password', '/current_password', $refused);
try {
    (new \mindstellar\user\AccountService())->changePassword($row(), 'right-password', 'new-password', 'other');
    $refused = '';
} catch (InvalidException $e) {
    $refused = $e->pointer();
}
pin('a confirmation that differs is refused, after the current password', '/new_password2', $refused);
$stamp = AuthStamp::of($row());
$fired = [];
(new \mindstellar\user\AccountService())->changePassword($row(), 'right-password', 'new-password', 'new-password');
pin('a change stores the new password, raises the stamp and runs the sign-out action', [true, $stamp + 1, [SignOut::USER_HOOK . ':' . $uma]], [
    osc_verify_password('new-password', $row()['s_password']), AuthStamp::of($row()), $fired,
]);
$fired = [];
\mindstellar\user\AccountService::setPassword($uma, 'right-password');
pin('setting a password (a reset, an admin edit) does the same', [true, $stamp + 2, [SignOut::USER_HOOK . ':' . $uma]], [
    osc_verify_password('right-password', $row()['s_password']), AuthStamp::of($row()), $fired,
]);
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string(password_hash('right-password', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST + 1])) . "' WHERE pk_i_id = $uma");
scratchdb_forget_cache();
$fired = [];
SignIn::attempt('uma', 'right-password');
pin('a rehash at sign-in is not a change: no stamp, no sign-out', [$stamp + 2, ['before_login']], [AuthStamp::of($row()), $fired]);
$reset();
for ($i = 0; $i < 20; $i++) {
    try {
        (new \mindstellar\user\AccountService())->changePassword($row(), 'wrong', 'x');
    } catch (InvalidException $e) {
        continue;
    } catch (BlockedException $e) {
        break;
    }
}
pin('enough wrong current passwords block the change', true, isset($e) && $e instanceof BlockedException && $e->retryAfter() > 0);
$sources = [
    'web change'   => (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebUser.php'),
    'API change'   => (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/api/controller/AccountController.php'),
];
foreach ($sources as $who => $code) {
    check("the $who goes through AccountService::changePassword()", str_contains($code, '->changePassword('));
}
$code  = User::getInstance()->issuePassCode($uma, User::PASS_CODE_RESET);
$stamp = AuthStamp::of($row());
$fired = [];
pin('a wrong reset code stores nothing and signs no one out', [false, $stamp, []], [\mindstellar\user\AccountService::setPassword($uma, 'reset-password', 'not-the-code'), AuthStamp::of($row()), $fired]);
pin('the right one stores the password, uses the code up and signs out', [true, true, null, $stamp + 1, [SignOut::USER_HOOK . ':' . $uma]], [
    \mindstellar\user\AccountService::setPassword($uma, 'reset-password', $code), osc_verify_password('reset-password', $row()['s_password']), $row()['s_pass_code'], AuthStamp::of($row()), $fired,
]);
pin('so the same link cannot be used twice', [false, $stamp + 1], [\mindstellar\user\AccountService::setPassword($uma, 'other-password', $code), AuthStamp::of($row())]);
$failing = static function (): void {
    throw new RuntimeException('listener failed');
};
$code = User::getInstance()->issuePassCode($uma, User::PASS_CODE_RESET);
osc_add_hook(SignOut::USER_HOOK, $failing);
try {
    \mindstellar\user\AccountService::setPassword($uma, 'kept-out', $code);
} catch (RuntimeException $e) {
}
osc_remove_hook(SignOut::USER_HOOK, $failing);
pin('a failing sign-out rolls the reset back: old password, code still valid', [true, false, true], [
    osc_verify_password('reset-password', $row()['s_password']), osc_verify_password('kept-out', $row()['s_password']), $row()['s_pass_code'] !== null,
]);
$login = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebLogin.php', 'forgotPost');
check('the reset form stores the password through AccountService::setPassword() with the code', str_contains($login, 'AccountService::setPassword(')
    && !str_contains($login, 'osc_hash_password(') && !str_contains($login, 'SignOut::'));

harness_section('an admin edit with a new password');
if (!function_exists('osc_base_url')) {
    function osc_base_url($withIndex = false)
    {
        return 'http://localhost/';
    }
}
foreach (['OSC_CACHE_TTL' => 60, 'OC_ADMIN' => false] as $constName => $constValue) {
    if (!defined($constName)) {
        define($constName, $constValue);
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
foreach (['hLocale', 'hCache', 'hUsers'] as $helper) {
    require_once ABS_PATH . "oc-includes/osclass/helpers/$helper.php";
}
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
$edit = static function (array $params) use ($uma) {
    foreach (['s_name' => 'Uma', 's_email' => 'uma@example.test', 's_username' => 'uma'] + $params as $key => $value) {
        Params::setParam($key, $value);
    }

    return (new UserActions(true))->edit($uma);
};
$stamp = AuthStamp::of($row());
$fired = [];
$edit(['s_password' => 'admin-set', 's_password2' => 'admin-set']);
pin('stores the password and signs the user out once', [true, $stamp + 1, [SignOut::USER_HOOK . ':' . $uma]], [
    osc_verify_password('admin-set', $row()['s_password']), AuthStamp::of($row()), array_values(array_filter($fired, static fn ($f) => str_starts_with($f, SignOut::USER_HOOK))),
]);
osc_add_hook(SignOut::USER_HOOK, $failing);
try {
    $edit(['s_password' => 'not-kept', 's_password2' => 'not-kept']);
} catch (RuntimeException $e) {
}
osc_remove_hook(SignOut::USER_HOOK, $failing);
pin('a failing sign-out rolls the new password back with it', [true, $stamp + 1], [osc_verify_password('admin-set', $row()['s_password']), AuthStamp::of($row())]);
$stamp = AuthStamp::of($row());
Params::setParam('s_password', '');
Params::setParam('s_password2', '');
$edit([]);
pin('an edit with no password signs no one out', $stamp, AuthStamp::of($row()));
\mindstellar\user\AccountService::setPassword($uma, 'right-password');

harness_section('SignOut');
$fired = [];
$stamp = AuthStamp::of($row());
pin('a user: the stamp goes up, then the action runs with the id', [true, $stamp + 1, [SignOut::USER_HOOK . ':' . $uma]], [SignOut::everywhereUser($uma), AuthStamp::of($row()), $fired]);
$fired = [];
pin('an unknown user is not signed out, and nothing runs', [false, []], [SignOut::everywhereUser(999999), $fired]);
$admin->query("INSERT INTO {$p}t_admin (s_name, s_username, s_password, s_email) VALUES ('Boss', 'boss', 'x', 'boss@example.test')");
$adminId = (int) $admin->insert_id;
$fired   = [];
pin('an admin the same, with its own action', [true, [SignOut::ADMIN_HOOK . ':' . $adminId]], [SignOut::everywhereAdmin($adminId), $fired]);
$failing = static function (): void {
    throw new RuntimeException('listener failed');
};
osc_add_hook(SignOut::USER_HOOK, $failing);
try {
    SignOut::everywhereUser($uma);
} catch (RuntimeException $e) {
}
osc_remove_hook(SignOut::USER_HOOK, $failing);
pin('a failing listener rolls the stamp back with it', $stamp + 1, AuthStamp::of($row()));

harness_section('an admin\'s new password');
$adminRow = static fn (): array => $admin->query("SELECT * FROM {$p}t_admin WHERE pk_i_id = $adminId")->fetch_assoc();
$stamp    = AuthStamp::of($adminRow());
$fired    = [];
pin('stores the password and the other columns, raises the stamp and runs the sign-out action', [true, true, 'dead', $stamp + 1, [SignOut::ADMIN_HOOK . ':' . $adminId]], [
    AdminPassword::set($adminId, 'admin-new', ['s_secret' => 'dead']), osc_verify_password('admin-new', $adminRow()['s_password']), $adminRow()['s_secret'], AuthStamp::of($adminRow()), $fired,
]);
pin('an unknown admin gets nothing', false, AdminPassword::set(999999, 'x'));
osc_add_hook(SignOut::ADMIN_HOOK, $failing);
try {
    AdminPassword::set($adminId, 'not-kept');
} catch (RuntimeException $e) {
}
osc_remove_hook(SignOut::ADMIN_HOOK, $failing);
pin('a failing sign-out rolls the password back with it', [true, $stamp + 1], [osc_verify_password('admin-new', $adminRow()['s_password']), AuthStamp::of($adminRow())]);
$recover = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminLogin.php', 'forgotPost');
$cli     = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php');
$cli     = substr($cli, (int) strpos($cli, 'private function cmdUserResetPassword('), 1800);
foreach (['the recovery link' => $recover, 'the CLI user:reset-password' => $cli] as $who => $code) {
    check("$who stores the password through AdminPassword::set()", str_contains($code, 'AdminPassword::set(') && !str_contains($code, 'osc_hash_password('));
}

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
