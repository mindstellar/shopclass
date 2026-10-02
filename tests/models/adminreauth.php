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
 * AdminReauth, the password check before a restore: a wrong password is refused, 2FA needs
 * its code, a right code passes, and failures count toward the sign-in limit until it blocks.
 *
 * Usage:  php tests/models/adminreauth.php      (standalone, own scratch database)
 *         php tests/run-models.php adminreauth  (as part of the suite)
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

use mindstellar\security\AdminReauth;
use mindstellar\security\AdminTwoFactor;
use mindstellar\security\LoginThrottle;
use mindstellar\security\Totp;

$admin    = scratchdb_session('osc_models_adminreauth');
$table    = DB_TABLE_PREFIX . 't_admin';
$attempts = DB_TABLE_PREFIX . 't_login_attempt';

$hash = $admin->real_escape_string(osc_hash_password('right-password'));
$admin->query("INSERT INTO $table (pk_i_id, s_name, s_username, s_password, s_email) VALUES (9, 'R', 'restorer', '$hash', '')");
$row = static function () use ($admin, $table): array {
    return $admin->query("SELECT * FROM $table WHERE pk_i_id = 9")->fetch_assoc();
};
$failures = static function () use ($admin, $attempts): int {
    return (int) $admin->query("SELECT COUNT(*) FROM $attempts WHERE s_context = 'restore_reauth' AND s_account = 'restorer'")->fetch_row()[0];
};
$reset = static function () use ($admin, $attempts): void {
    $admin->query("TRUNCATE TABLE $attempts");
    $admin->query('TRUNCATE TABLE ' . DB_TABLE_PREFIX . 't_rate_counter');
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
Params::init();

harness_section('Password');

$reset();
check('a wrong password is refused', AdminReauth::verify($row(), 'wrong', '') !== '');
pin('...and counted under restore_reauth', 1, $failures());
check('an empty password is refused', AdminReauth::verify($row(), '', '') !== '');
pin('the right password passes when 2FA is off', '', AdminReauth::verify($row(), 'right-password', ''));
pin('...and clears the failures', 0, $failures());

harness_section('Two-step sign-in');

$reset();
$secret = Totp::newSecret();
$codes  = AdminTwoFactor::enable(9, $secret, Totp::code($secret));
check('fixture: 2FA is on', AdminTwoFactor::enabled($row()));
pin('the right password without a code is refused', AdminTwoFactor::refusedMessage(), AdminReauth::verify($row(), 'right-password', ''));
check('the right password with a wrong code is refused', AdminReauth::verify($row(), 'right-password', '000000') !== '');
pin('...both counted', 2, $failures());
check('a wrong password with a right code is refused', AdminReauth::verify($row(), 'wrong', Totp::code($secret, Totp::step() + 1)) !== '');
pin('the right password and the app code pass', '', AdminReauth::verify($row(), 'right-password', Totp::code($secret, Totp::step() + 1)));
pin('the right password and a backup code pass', '', AdminReauth::verify($row(), 'right-password', (string) $codes[0]));

harness_section('The limit');

$reset();
$max = osc_login_throttle_max_account();
for ($i = 0; $i < $max; $i++) {
    AdminReauth::verify($row(), 'wrong', '');
}
pin("$max failures are counted", $max, $failures());
pin('then the right password and code are refused too', osc_login_throttle_message(
    LoginThrottle::evaluate(AdminReauth::RESTORE, 'restorer')['retry_after']
), AdminReauth::verify($row(), 'right-password', Totp::code($secret, Totp::step() + 2)));
pin('...without counting another try', $max, $failures());
check('restore_reauth is a sign-in context, so the admin can see and unblock it', in_array(AdminReauth::RESTORE, LoginThrottle::CONTEXTS, true));

$reset();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
