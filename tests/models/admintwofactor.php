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
 * AdminTwoFactor against a real t_admin row: turning it on, a code and a backup code each
 * passing once, the try limit, turning it off, the remember-me binding changing across both,
 * and a database without the column reading as off.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\security\AdminTwoFactor;
use mindstellar\security\Totp;

$admin = scratchdb_session('osc_models_admintwofactor');
$table = DB_TABLE_PREFIX . 't_admin';

$admin->query("INSERT INTO $table (pk_i_id, s_name, s_username, s_password, s_email) VALUES (7, 'A', 'a', 'hash', 'a@example.test')");
$row    = array('pk_i_id' => 7, 's_password' => 'hash');
$stored = static function () use ($admin, $table) {
    return $admin->query("SELECT s_2fa FROM $table WHERE pk_i_id = 7")->fetch_row()[0];
};
$clearTries = static function () use ($admin) {
    $admin->query('TRUNCATE TABLE ' . DB_TABLE_PREFIX . 't_rate_counter');
};

harness_section('turning it on');

$secret = Totp::newSecret();
$off    = AdminTwoFactor::rememberBinding($row);
pin('off, the binding is the password hash alone', 'hash', $off);
pin('a wrong code turns nothing on', array(null, null), array(AdminTwoFactor::enable(7, $secret, '000000'), $stored()));
$codes = AdminTwoFactor::enable(7, $secret, Totp::code($secret));
pin('a right code returns 8 backup codes', 8, is_array($codes) ? count($codes) : null);
$settings = AdminTwoFactor::settings($row);
pin('and stores the secret and 8 hashes', array($secret, 8), array($settings['secret'] ?? null, count($settings['backup'] ?? array())));
check('the backup codes are not stored as given', strpos((string)$stored(), $codes[0]) === false);
check('on, the binding changes', AdminTwoFactor::rememberBinding($row) !== $off);

harness_section('codes pass once');

$clearTries();
$next = Totp::code($secret, Totp::step() + 1);
pin('the next app code passes', true, AdminTwoFactor::check($row, $next));
pin('and not twice', false, AdminTwoFactor::check($row, $next));
pin('a backup code passes', true, AdminTwoFactor::check($row, strtolower($codes[1])));
pin('and not twice', false, AdminTwoFactor::check($row, $codes[1]));
pin('one backup code is used up', 7, count(AdminTwoFactor::settings($row)['backup']));
pin('a 7-digit code is refused', false, AdminTwoFactor::check($row, Totp::code($secret, Totp::step() + 1) . '0'));

harness_section('the try limit');

$clearTries();
for ($i = 0; $i < 10; $i++) {
    AdminTwoFactor::check($row, '000000');
}
pin('after 10 tries a good backup code is refused', false, AdminTwoFactor::check($row, $codes[2]));
$clearTries();
pin('and passes once the window is clear', true, AdminTwoFactor::check($row, $codes[2]));

harness_section('turning it off');

$on = AdminTwoFactor::rememberBinding($row);
AdminTwoFactor::disable(7);
pin('off again', false, AdminTwoFactor::enabled($row));
$afterOff = AdminTwoFactor::rememberBinding($row);
check('the binding differs from while on', $afterOff !== $on);
check('and from before it was first turned on', $afterOff !== $off);
AdminTwoFactor::disable(7);
check('and changes on every turn-off', AdminTwoFactor::rememberBinding($row) !== $afterOff);

harness_section('a database not yet upgraded');

$admin->query("ALTER TABLE $table DROP COLUMN s_2fa");
pin('reads as off', array(false, 'hash'), array(AdminTwoFactor::enabled($row), AdminTwoFactor::rememberBinding($row)));
$admin->query("ALTER TABLE $table ADD COLUMN s_2fa TEXT NULL AFTER s_secret");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
