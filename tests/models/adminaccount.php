<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * AdminPassword::rehash() and issueReset() and AdminStore::delete(), the writes the admin
 * sign-in, recovery and Admins screens make.
 *
 * Usage:  php tests/models/adminaccount.php      (standalone, own scratch database)
 *         php tests/run-models.php adminaccount  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hSecurity.php';

use mindstellar\auth\AdminPassword;
use mindstellar\auth\AdminStore;
use mindstellar\auth\AuthStamp;
use mindstellar\security\ActionToken;

$admin = scratchdb_session('osc_models_adminaccount');
$table = DB_TABLE_PREFIX . 't_admin';

$admin->query("INSERT INTO $table (pk_i_id, s_name, s_username, s_password, s_email, s_secret) VALUES (5, 'A', 'alpha', 'old', 'a@example.test', 'none')");
$row = static fn (int $id = 5): ?array => $admin->query("SELECT * FROM $table WHERE pk_i_id = $id")->fetch_assoc();

harness_section('Rehash on sign-in');

$stamp = AuthStamp::of($row());
$hash  = AdminPassword::rehash(5, 'right-password');
check('the returned hash matches the password', osc_verify_password('right-password', $hash));
pin('the stored hash is the returned one', $hash, $row()['s_password']);
pin('the admin is not signed out elsewhere', $stamp, AuthStamp::of($row()));
check('an unknown admin still gets a hash', osc_verify_password('x', AdminPassword::rehash(999999, 'x')));

harness_section('Password reset code');

$code = AdminPassword::issueReset(5);
pin('the code is 40 characters', 40, strlen($code));
pin('only its fingerprint is stored', ActionToken::hash($code), $row()['s_secret']);
pin('the password is left alone', $hash, $row()['s_password']);
pin('the admin is not signed out elsewhere', $stamp, AuthStamp::of($row()));
check('a second request replaces the code', AdminPassword::issueReset(5) !== $code && $row()['s_secret'] !== ActionToken::hash($code));

harness_section('Delete');

$admin->query("INSERT INTO $table (pk_i_id, s_name, s_username, s_password, s_email) VALUES (6, 'B', 'beta', 'x', 'b@example.test'), (7, 'C', 'gamma', 'x', 'c@example.test')");
pin('an empty list is false, as deleteBatch() returned', false, AdminStore::delete([]));
pin('a nested array is refused, never cast to id 1', false, AdminStore::delete([['x']]));
pin('an unknown id deletes nothing', 0, AdminStore::delete(['999']));
pin('string ids from the request delete those rows', 2, AdminStore::delete(['6', '7']));
pin('...and only those', [true, null, null], [$row(5) !== null, $row(6), $row(7)]);

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAdmins.php');
check('the Admins screen deletes through AdminStore::delete()', str_contains($controller, 'AdminStore::delete(') && !str_contains($controller, 'deleteBatch('));
$login = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminLogin.php');
check('the sign-in screen writes t_admin only through the auth module', !str_contains($login, 'Admin::getInstance()->update('));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
