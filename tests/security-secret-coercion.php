<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Regression tests for the secret-comparison coercion.
 *
 * The legacy query layer passed values through an escape() that returns an
 * is_numeric() value UNQUOTED. A secret of "0" therefore reached MySQL as a
 * number, the VARCHAR column was compared numerically, and every secret
 * beginning with a letter evaluates to 0 — so "0" matched almost any account.
 *
 * Reachable anonymously through the login cookies (oc_userId / oc_userSecret),
 * the password-reset link, and the registration validation link. Each of those
 * gates on one of the lookups below.
 *
 * These tests fail loudly if any of them is ever moved back onto a comparison
 * that coerces. They are deliberately separate from the model characterization
 * suites: those pin behaviour as it was, and this behaviour was a defect.
 *
 * Usage:  php tests/security-secret-coercion.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_security_secret');

seed_country($admin);
seed_region($admin);

$prefix = DB_TABLE_PREFIX;

/* Secrets as osc_genRandomPassword() actually produces them: alphanumeric, and
 * in the overwhelming majority of cases starting with a letter. */
$userId = seed_user($admin, 'victim', 'victim@example.test', 1, 1);
$admin->query("UPDATE {$prefix}t_user SET s_secret = 'aB3xK9qLmZ' WHERE pk_i_id = $userId");

$digitId = seed_user($admin, 'other', 'other@example.test', 1, 1);
$admin->query("UPDATE {$prefix}t_user SET s_secret = '7fQw2ZzP' WHERE pk_i_id = $digitId");

$user = User::newInstance();

/* ----------------------------------------------------------------------------
 * The login cookie path.
 * ------------------------------------------------------------------------- */
harness_section('User::findByIdSecret — a numeric secret must not coerce');

$match = $user->findByIdSecret($userId, 'aB3xK9qLmZ');
check('the real secret still authenticates', is_array($match) && ($match['pk_i_id'] ?? null) == $userId);

pin('the secret "0" matches nothing', array(), $user->findByIdSecret($userId, '0'));
pin('an int 0 matches nothing either', array(), $user->findByIdSecret($userId, 0));
pin('a wrong secret still matches nothing', array(), $user->findByIdSecret($userId, 'totally-wrong'));

/* A secret that genuinely starts with a digit must not be reachable by its
 * numeric prefix either. */
pin('a digit prefix does not match a secret beginning with that digit', array(), $user->findByIdSecret($digitId, '7'));
pin('the empty string matches nothing', array(), $user->findByIdSecret($userId, ''));
$realDigit = $user->findByIdSecret($digitId, '7fQw2ZzP');
check('that account still authenticates with its real secret', is_array($realDigit) && ($realDigit['pk_i_id'] ?? null) == $digitId);

/* ----------------------------------------------------------------------------
 * The password-reset path.
 * ------------------------------------------------------------------------- */
harness_section('User::findByIdPasswordSecret — same comparison, same rule');

$admin->query(
    "UPDATE {$prefix}t_user SET s_pass_code = 'rQ8vNmT1', s_pass_date = NOW() WHERE pk_i_id = $userId"
);

$reset = $user->findByIdPasswordSecret($userId, 'rQ8vNmT1');
check('the real reset code still works', is_array($reset) && ($reset['pk_i_id'] ?? null) == $userId);

pin('a reset code of "0" matches nothing', array(), $user->findByIdPasswordSecret($userId, '0'));
pin('an int 0 matches nothing either', array(), $user->findByIdPasswordSecret($userId, 0));
pin('a wrong code matches nothing', array(), $user->findByIdPasswordSecret($userId, 'wrong'));

/* The 24-hour window still applies. */
$admin->query(
    "UPDATE {$prefix}t_user SET s_pass_date = DATE_SUB(NOW(), INTERVAL 48 HOUR) WHERE pk_i_id = $userId"
);
pin('an expired code no longer works even when correct', array(), $user->findByIdPasswordSecret($userId, 'rQ8vNmT1'));

/* ----------------------------------------------------------------------------
 * The admin panel equivalent, closed earlier in this migration.
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByIdSecret — the admin-side twin');

$adminId = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_admin (s_name, s_username, s_password, s_email, s_secret)
     VALUES (?, ?, ?, ?, ?)",
    'sssss',
    array('Root', 'root', str_repeat('x', 60), 'root@example.test', 'kR4tYbN8wL')
);

$adminModel = Admin::newInstance();
$adminMatch = $adminModel->findByIdSecret($adminId, 'kR4tYbN8wL');
check('the real admin secret still authenticates', is_array($adminMatch) && ($adminMatch['pk_i_id'] ?? null) == $adminId);
check('the admin secret "0" matches nothing', !$adminModel->findByIdSecret($adminId, '0'), describe($adminModel->findByIdSecret($adminId, '0')));

exit(harness_result());

/* file end: ./tests/security-secret-coercion.php */
