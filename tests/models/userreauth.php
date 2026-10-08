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
 * Reauth, the password check on the account's change-password and sign-out-all forms: a
 * wrong password is refused and counted under the sign-in form's limit, enough failures block
 * even the right password until the window ends, and a password sent as an array is a clean
 * refusal, not an error.
 *
 * Usage:  php tests/models/userreauth.php      (standalone, own scratch database)
 *         php tests/run-models.php userreauth  (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}
if (!function_exists('_mn')) {
    function _mn($single, $plural, $n)
    {
        return $n === 1 ? $single : $plural;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hSecurity.php';

use mindstellar\auth\Reauth;
use mindstellar\security\LoginThrottle;
use mindstellar\security\UserReauth;

$admin    = scratchdb_session('osc_models_userreauth');
$p        = DB_TABLE_PREFIX;
$attempts = $p . 't_login_attempt';

$uma  = seed_user($admin, 'uma', 'uma@example.test');
$hash = $admin->real_escape_string(osc_hash_password('right-password'));
$admin->query("UPDATE {$p}t_user SET s_password = '$hash' WHERE pk_i_id = $uma");
$row = static fn (): array => $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $uma")->fetch_assoc();
$failures = static fn (string $account = 'uma@example.test'): int => (int) $admin->query(
    "SELECT COUNT(*) FROM $attempts WHERE s_context = 'web' AND s_account = '" . $admin->real_escape_string($account) . "'"
)->fetch_row()[0];
$reset = static function () use ($admin, $attempts): void {
    $admin->query("TRUNCATE TABLE $attempts");
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
Params::init();

harness_section('Password');

$reset();
pin('a wrong password is refused', "Current password doesn't match", Reauth::verify($row(), 'wrong'));
pin('...and counted under the sign-in form\'s context and the e-mail', 1, $failures());
check('an empty password is refused', Reauth::verify($row(), '') !== '');
LoginThrottle::recordFailure('web', 'someone-else@example.test');
pin('the right password passes', '', Reauth::verify($row(), 'right-password'));
pin('...and clears this account\'s failures only, not the address\'s', [0, 1], [$failures(), $failures('someone-else@example.test')]);

harness_section('A password sent as an array');

$_POST = ['password' => ['x'], 'new_password' => ['y'], 'new_password2' => 'z'];
Params::init();
$read = Params::getParamString('password', false, false);
pin('reads as an empty string', '', $read);
check('which is a refusal, not an error', Reauth::verify($row(), $read) !== '');
$_POST = [];
Params::init();

$source = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebUser.php');
$change = substr($source, (int) strpos($source, "case 'change_password_post':"), 1800);
$cases  = ['change_password_post' => $change];
if (str_contains($source, "case 'sign_out_all_post':")) {
    $cases['change_password_post'] = substr($change, 0, (int) strpos($change, "case 'sign_out_all_post':"));
    $cases['sign_out_all_post']    = substr($source, (int) strpos($source, "case 'sign_out_all_post':"), 900);
}
foreach ($cases as $case => $code) {
    check("$case reads the passwords as strings", str_contains($code, "Params::getParamString('password', false, false)")
        && !preg_match("/Params::getParam\\('(password|new_password2?)'/", $code));
    check("$case checks the password through Reauth", (str_contains($code, 'Reauth::verify(') || str_contains($code, '(new AccountService())->changePassword('))
        && !str_contains($code, 'osc_verify_password('));
}
check('change_password_post reads the new passwords as strings too', str_contains($change, "Params::getParamString('new_password', false, false)")
    && str_contains($change, "Params::getParamString('new_password2', false, false)"));

harness_section('The limit');

$reset();
$max = osc_login_throttle_max_account();
for ($i = 0; $i < $max; $i++) {
    Reauth::verify($row(), 'wrong');
}
pin("$max failures are counted", $max, $failures());
$blocked = osc_login_throttle_message(LoginThrottle::evaluate('web', 'uma@example.test')['retry_after']);
pin('then the right password is refused with the sign-in form\'s message', $blocked, Reauth::verify($row(), 'right-password'));
pin('...without counting another try', $max, $failures());
pin('the sign-in form is blocked for the account too', LoginThrottle::BLOCKED, LoginThrottle::evaluate('web', 'uma@example.test')['status']);
$admin->query("UPDATE $attempts SET dt_date = DATE_SUB(dt_date, INTERVAL " . (osc_login_throttle_window() + 1) . ' MINUTE)');
pin('once the window ends, the right password passes', '', Reauth::verify($row(), 'right-password'));

$reset();
for ($i = 0; $i < $max; $i++) {
    LoginThrottle::recordFailure('web', 'uma');
}
check('sign-in failures by username use up the same budget', Reauth::verify($row(), 'right-password') !== '');
$reset();

harness_section('The 6.4 class name');

pin('UserReauth::verify() still answers as Reauth', ["Current password doesn't match", ''], [UserReauth::verify($row(), 'wrong'), UserReauth::verify($row(), 'right-password')]);
pin('...with the same context', Reauth::CONTEXT, UserReauth::CONTEXT);

$reset();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
